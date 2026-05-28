<?php declare(strict_types=1);

/**
 * 快活システム - 発注ステータス自動遷移バッチ
 *
 * 起動: CLI のみ（Web 経由は拒否）
 *
 * 遷移ルール（備品のみ自動進行。修理・部品は商品部の手動運用）:
 *   0→1 (備品のみ): カテゴリ締め日の当日に status=0 の備品発注を 1 に遷移
 *   1→2 (備品のみ): カテゴリ締め日の翌日に status=1 の備品発注を 2 に遷移
 *                    delivery_date が未設定なら 締め日+4日 で仮設定
 *   2→3 (備品のみ): 「予定日 = 当日」の備品発注を 3 に遷移
 *                    actual_delivery_date 未設定なら当日をセット
 *                    予算実績(actual_amount)に estimate_amount を加算（納品月ベース）
 *   3→4 (備品のみ): 「予定日翌日 = 当日」の備品発注を 4 に遷移
 *                    final_amount 未設定なら estimate_amount をコピー
 *                    予算実績(actual_amount)に final-estimate 差分を反映（納品月ベース）
 *
 * 修理発注・部品発注は最終金額が変動するため、すべての遷移を商品部が手動で行う。
 *
 * オプション:
 *   --date=YYYY-MM-DD  当日として扱う日付（テスト用、省略時は今日）
 *   --dry-run          DB変更なしで対象だけ表示
 *   --only=0to1,1to2,2to3,3to4  実行する遷移を限定（カンマ区切り）
 *
 * 設計:
 *   - 各発注を個別トランザクションで処理（1件失敗しても他に影響しない）
 *   - 多重起動防止: flock + .lockファイル
 *   - 履歴記録: order_status_history に changed_by='system_batch'
 *   - 予算実績: 2→3 で estimate を加算, 3→4 で final-estimate 差分を加算
 *               （いずれも納品月ベース: applyBudgetActualDeltaByDelivery）
 *
 * 本番 cron 設定例:
 *   0 0 * * * /usr/local/bin/php /home/.../setup/auto_advance_status.php
 *
 * Windows タスクスケジューラ設定例:
 *   php.exe C:\Users\ssasa\kaikatsu-system\setup\auto_advance_status.php
 *   トリガー: 毎日 0:00
 */

// CLI 以外を拒否
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from CLI.';
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/budget.php';

date_default_timezone_set('Asia/Tokyo');

// ----------------------------------------------------------------
// 引数解析
// ----------------------------------------------------------------
$opts = [
    'date'    => date('Y-m-d'),
    'dry_run' => false,
    'only'    => ['0to1', '1to2', '2to3', '3to4'],
];

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif (preg_match('/\A--date=(\d{4}-\d{2}-\d{2})\z/', $arg, $m)) {
        $opts['date'] = $m[1];
    } elseif (preg_match('/\A--only=(.+)\z/', $arg, $m)) {
        $opts['only'] = array_filter(array_map('trim', explode(',', $m[1])));
    } else {
        fwrite(STDERR, "[ERROR] 不明な引数: {$arg}\n");
        fwrite(STDERR, "Usage: php auto_advance_status.php [--date=YYYY-MM-DD] [--dry-run] [--only=0to1,1to2,2to3,3to4]\n");
        exit(2);
    }
}

$today = new DateTimeImmutable($opts['date']);
$yesterday = $today->modify('-1 day');
$tomorrow = $today->modify('+1 day');
$todayStr = $today->format('Y-m-d');
$yesterdayStr = $yesterday->format('Y-m-d');

// ----------------------------------------------------------------
// 排他制御
// ----------------------------------------------------------------
$lockFile = __DIR__ . '/.auto_advance_status.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "[ERROR] ロックファイルを開けません: {$lockFile}\n");
    exit(2);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[INFO] 別プロセスが実行中。終了します。\n");
    fclose($lockHandle);
    exit(0);
}

// ----------------------------------------------------------------
// 開始ログ
// ----------------------------------------------------------------
$startedAt = date('Y-m-d H:i:s');
$dryFlag = $opts['dry_run'] ? 'true' : 'false';
$onlyFlag = implode(',', $opts['only']);
fwrite(STDOUT, "[INFO] {$startedAt} 自動遷移バッチ開始 (date={$todayStr}, dry-run={$dryFlag}, only={$onlyFlag})\n");

$total = ['advanced' => 0, 'errors' => 0, 'skipped' => 0];

// ================================================================
// 0→1: 備品のみ、カテゴリ締め日当日
// ================================================================
if (in_array('0to1', $opts['only'], true)) {
    $cats = query("SELECT code, name, closing_type, closing_day FROM categories WHERE closing_type IN ('monthly', 'weekly')");
    $eligibleCategoryCodes = [];

    foreach ($cats as $cat) {
        if (isClosingDateForDate($today, $cat['closing_type'], (int)$cat['closing_day'])) {
            $eligibleCategoryCodes[] = $cat['code'];
        }
    }

    if (empty($eligibleCategoryCodes)) {
        fwrite(STDOUT, "[INFO] 0→1 対象: 0 件 (当日が締め日に該当するカテゴリなし)\n");
    } else {
        $placeholders = [];
        $params = [];
        foreach ($eligibleCategoryCodes as $i => $code) {
            $key = ':cat' . $i;
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $sql = "SELECT id, type, category_code, shop_code, date
                FROM orders
                WHERE status = 0
                  AND type = 'equipment'
                  AND cancelled_at IS NULL
                  AND category_code IN (" . implode(',', $placeholders) . ")";
        $targets = query($sql, $params);

        fwrite(STDOUT, "[INFO] 0→1 対象: " . count($targets) . " 件 (カテゴリ: " . implode('/', $eligibleCategoryCodes) . ")\n");

        foreach ($targets as $order) {
            try {
                if ($opts['dry_run']) {
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} (備品/{$order['category_code']}) status 0→1\n");
                } else {
                    advanceOrderStatusZeroToOne($order);
                    fwrite(STDOUT, "  ✓ {$order['id']} (備品/{$order['category_code']}) status 0→1\n");
                }
                $total['advanced']++;
            } catch (Throwable $e) {
                fwrite(STDERR, "  [ERROR] {$order['id']}: " . $e->getMessage() . "\n");
                $total['errors']++;
            }
        }
    }
}

// ================================================================
// 1→2: 備品のみ、カテゴリ締め日翌日
// ================================================================
if (in_array('1to2', $opts['only'], true)) {
    $cats = query("SELECT code, name, closing_type, closing_day FROM categories WHERE closing_type IN ('monthly', 'weekly')");
    $eligibleCategoryCodes = [];
    $closingDateByCategory = [];

    foreach ($cats as $cat) {
        $closingDate = matchClosingDateForYesterday($yesterday, $cat['closing_type'], (int)$cat['closing_day']);
        if ($closingDate !== null) {
            $eligibleCategoryCodes[] = $cat['code'];
            $closingDateByCategory[$cat['code']] = $closingDate;
        }
    }

    if (empty($eligibleCategoryCodes)) {
        fwrite(STDOUT, "[INFO] 1→2 対象: 0 件 (前日が締め日に該当するカテゴリなし)\n");
    } else {
        $placeholders = [];
        $params = [];
        foreach ($eligibleCategoryCodes as $i => $code) {
            $key = ':cat' . $i;
            $placeholders[] = $key;
            $params[$key] = $code;
        }
        $sql = "SELECT id, type, category_code, shop_code, date, delivery_date, estimate_amount
                FROM orders
                WHERE status = 1
                  AND type = 'equipment'
                  AND cancelled_at IS NULL
                  AND category_code IN (" . implode(',', $placeholders) . ")";
        $targets = query($sql, $params);

        fwrite(STDOUT, "[INFO] 1→2 対象: " . count($targets) . " 件 (カテゴリ: " . implode('/', $eligibleCategoryCodes) . ")\n");

        foreach ($targets as $order) {
            $closingDate = $closingDateByCategory[$order['category_code']];
            $tempDeliveryDate = $closingDate->modify('+4 days')->format('Y-m-d');

            try {
                if ($opts['dry_run']) {
                    $deliveryNote = $order['delivery_date']
                        ? "delivery_date={$order['delivery_date']}(既存維持)"
                        : "delivery_date={$tempDeliveryDate}(締め日+4日で仮設定)";
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} (備品/{$order['category_code']}) status 1→2, {$deliveryNote}\n");
                } else {
                    advanceOrderStatusOneToTwo($order, $tempDeliveryDate);
                    $deliveryNote = $order['delivery_date']
                        ? "delivery_date={$order['delivery_date']}(既存維持)"
                        : "delivery_date={$tempDeliveryDate}(締め日+4日)";
                    fwrite(STDOUT, "  ✓ {$order['id']} (備品/{$order['category_code']}) status 1→2, {$deliveryNote}\n");
                }
                $total['advanced']++;
            } catch (Throwable $e) {
                fwrite(STDERR, "  [ERROR] {$order['id']}: " . $e->getMessage() . "\n");
                $total['errors']++;
            }
        }
    }
}

// ================================================================
// 2→3: 備品のみ、「納品予定日 = 当日」
// ================================================================
if (in_array('2to3', $opts['only'], true)) {
    $targets = query(
        "SELECT id, type, category_code, shop_code, date, delivery_date, actual_delivery_date
         FROM orders
         WHERE status = 2
           AND type = 'equipment'
           AND cancelled_at IS NULL
           AND delivery_date = :today",
        [':today' => $todayStr]
    );

    fwrite(STDOUT, "[INFO] 2→3 対象: " . count($targets) . " 件 (予定日=" . $todayStr . ")\n");

    foreach ($targets as $order) {
        try {
            $dateField = "delivery_date={$order['delivery_date']}";

            if ($opts['dry_run']) {
                fwrite(STDOUT, "  [DRY-RUN] {$order['id']} (備品) status 2→3, {$dateField}\n");
            } else {
                advanceOrderStatusTwoToThree($order, $todayStr);
                fwrite(STDOUT, "  ✓ {$order['id']} (備品) status 2→3, {$dateField}\n");
            }
            $total['advanced']++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  [ERROR] {$order['id']}: " . $e->getMessage() . "\n");
            $total['errors']++;
        }
    }
}

// ================================================================
// 3→4: 備品のみ、「納品予定日翌日 = 当日」
// ※ 修理・部品は最終金額の確定が変動するため自動進行せず、商品部が手動で運用する。
// ================================================================
if (in_array('3to4', $opts['only'], true)) {
    $targets = query(
        "SELECT id, type, category_code, shop_code, date, delivery_date, actual_delivery_date, estimate_amount, final_amount
         FROM orders
         WHERE status = 3
           AND type = 'equipment'
           AND cancelled_at IS NULL
           AND delivery_date = :yesterday",
        [':yesterday' => $yesterdayStr]
    );

    fwrite(STDOUT, "[INFO] 3→4 対象: " . count($targets) . " 件 (予定日翌日=" . $todayStr . ")\n");

    foreach ($targets as $order) {
        try {
            $estimate = (int)($order['estimate_amount'] ?? 0);
            $finalRaw = $order['final_amount'];
            $isFinalSet = $finalRaw !== null;

            if ($opts['dry_run']) {
                if ($isFinalSet) {
                    $delta = (int)$finalRaw - $estimate;
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} (備品) status 3→4, final_amount={$finalRaw}(手動), 予算差分={$delta}\n");
                } else {
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} (備品) status 3→4, final_amount未設定→estimate({$estimate})適用\n");
                }
            } else {
                $applied = advanceOrderStatusThreeToFour($order);
                if ($applied['final_was_set']) {
                    fwrite(STDOUT, "  ✓ {$order['id']} (備品) status 3→4, final={$applied['final_amount']}(手動), 予算差分={$applied['delta']}\n");
                } else {
                    fwrite(STDOUT, "  ✓ {$order['id']} (備品) status 3→4, final={$applied['final_amount']}(estimate適用)\n");
                }
            }
            $total['advanced']++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  [ERROR] {$order['id']}: " . $e->getMessage() . "\n");
            $total['errors']++;
        }
    }
}

// ----------------------------------------------------------------
// 終了
// ----------------------------------------------------------------
$endedAt = date('Y-m-d H:i:s');
fwrite(STDOUT, "[INFO] {$endedAt} 完了: {$total['advanced']} 件遷移 / {$total['errors']} 件エラー" . ($opts['dry_run'] ? " (dry-run)" : "") . "\n");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit($total['errors'] > 0 ? 1 : 0);


// ================================================================
// ヘルパー関数
// ================================================================

/**
 * 指定の前日が closing_type/closing_day に該当する締め日なら、その締め日(DateTimeImmutable)を返す。
 * 該当しなければ null。
 */
function matchClosingDateForYesterday(DateTimeImmutable $yesterday, string $closingType, int $closingDay): ?DateTimeImmutable
{
    if ($closingType === 'monthly') {
        // 前日.day == closing_day なら、前日が締め日
        if ((int)$yesterday->format('j') === $closingDay) {
            return $yesterday;
        }
        return null;
    }

    if ($closingType === 'weekly') {
        // 前日の曜日 == closing_day なら、前日が締め日（PHP 'w': 0=日…6=土）
        if ((int)$yesterday->format('w') === $closingDay) {
            return $yesterday;
        }
        return null;
    }

    return null;
}

/**
 * 指定日が closing_type/closing_day に該当する締め日なら true。
 * 月次: 日付一致、週次: 曜日一致（PHP 'w': 0=日…6=土）。
 */
function isClosingDateForDate(DateTimeImmutable $date, string $closingType, int $closingDay): bool
{
    if ($closingType === 'monthly') {
        return (int)$date->format('j') === $closingDay;
    }
    if ($closingType === 'weekly') {
        return (int)$date->format('w') === $closingDay;
    }
    return false;
}

function orderTypeLabel(string $type): string
{
    return match ($type) {
        'repair'    => '修理',
        'equipment' => '備品',
        'parts'     => '部品',
        default     => $type,
    };
}

/**
 * 0→1 遷移を 1 トランザクションで実行。締め日当日に依頼中の備品発注を「発注済」へ進める。
 */
function advanceOrderStatusZeroToOne(array $order): void
{
    beginTransaction();
    try {
        execute(
            'UPDATE orders SET status = 1 WHERE id = :oid',
            [':oid' => $order['id']]
        );
        insertStatusHistory($order['id'], 1, '自動遷移バッチ: 0→1 (締め日当日)');
        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }
}

/**
 * 1→2 遷移を 1 トランザクションで実行。delivery_date が空なら $tempDeliveryDate を設定。
 */
function advanceOrderStatusOneToTwo(array $order, string $tempDeliveryDate): void
{
    beginTransaction();
    try {
        $updateCols = ['status = 2'];
        $updateVals = [':oid' => $order['id']];

        if (empty($order['delivery_date'])) {
            $updateCols[] = 'delivery_date = :dd';
            $updateVals[':dd'] = $tempDeliveryDate;
        }

        execute(
            'UPDATE orders SET ' . implode(', ', $updateCols) . ' WHERE id = :oid',
            $updateVals
        );

        insertStatusHistory($order['id'], 2, '自動遷移バッチ: 1→2 (締め日翌日)');
        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }
}

/**
 * 2→3 遷移（備品のみ）。actual_delivery_date 未設定なら今日をセット。
 * 予算実績(actual_amount)に estimate_amount を納品月ベースで加算。
 */
function advanceOrderStatusTwoToThree(array $order, string $todayStr): void
{
    $estimate = (int)($order['estimate_amount'] ?? 0);
    $deliveryDate = !empty($order['actual_delivery_date']) ? $order['actual_delivery_date'] : $todayStr;

    beginTransaction();
    try {
        if (empty($order['actual_delivery_date'])) {
            execute(
                'UPDATE orders SET status = 3, actual_delivery_date = :d WHERE id = :oid',
                [':d' => $todayStr, ':oid' => $order['id']]
            );
        } else {
            execute(
                'UPDATE orders SET status = 3 WHERE id = :oid',
                [':oid' => $order['id']]
            );
        }

        insertStatusHistory($order['id'], 3, '自動遷移バッチ: 2→3 (予定日到来)');

        // 予算実績反映（納品月ベース）
        if ($estimate > 0) {
            $orderForBudget = array_merge($order, ['actual_delivery_date' => $deliveryDate]);
            applyBudgetActualDeltaByDelivery($orderForBudget, $estimate);
        }

        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }
}

/**
 * 3→4 遷移。final_amount 未設定なら estimate_amount を適用。差分を予算実績に反映。
 * @return array{final_amount:int, final_was_set:bool, delta:int}
 */
function advanceOrderStatusThreeToFour(array $order): array
{
    $estimate = (int)($order['estimate_amount'] ?? 0);
    $finalRaw = $order['final_amount'];
    $isFinalSet = $finalRaw !== null;
    $finalAmount = $isFinalSet ? (int)$finalRaw : $estimate;
    $delta = $finalAmount - $estimate;

    beginTransaction();
    try {
        if ($isFinalSet) {
            execute(
                'UPDATE orders SET status = 4 WHERE id = :oid',
                [':oid' => $order['id']]
            );
        } else {
            execute(
                'UPDATE orders SET status = 4, final_amount = :fa WHERE id = :oid',
                [':fa' => $finalAmount, ':oid' => $order['id']]
            );
        }

        insertStatusHistory($order['id'], 4, '自動遷移バッチ: 3→4 (予定日翌日)');

        // 予算実績反映（納品月ベース、差分が 0 ならスキップ）
        if ($delta !== 0) {
            applyBudgetActualDeltaByDelivery($order, $delta);
        }

        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }

    return [
        'final_amount'  => $finalAmount,
        'final_was_set' => $isFinalSet,
        'delta'         => $delta,
    ];
}

function insertStatusHistory(string $orderId, int $status, string $memo): void
{
    execute(
        'INSERT INTO order_status_history (order_id, status, changed_by, memo)
         VALUES (:oid, :status, :by, :memo)',
        [
            ':oid'    => $orderId,
            ':status' => $status,
            ':by'     => 'system_batch',
            ':memo'   => $memo,
        ]
    );
}
