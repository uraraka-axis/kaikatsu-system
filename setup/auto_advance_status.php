<?php declare(strict_types=1);

/**
 * 快活システム - 発注ステータス自動遷移バッチ
 *
 * 起動: CLI のみ（Web 経由は拒否）
 *
 * 遷移ルール:
 *   1→2 (備品のみ): カテゴリ締め日の翌日に status=1 の備品発注を 2 に遷移
 *                    delivery_date が未設定なら 締め日+4日 で仮設定
 *   2→3 (全種別)  : 「予定日 = 当日」の発注を 3 に遷移
 *                    備品: actual_delivery_date 未設定なら当日をセット
 *                    修理: repair_completed_date 未設定なら当日をセット
 *   3→4 (全種別)  : 「予定日翌日 = 当日」の発注を 4 に遷移
 *                    final_amount 未設定なら estimate_amount をコピー
 *                    予算実績(actual_amount)に差分を反映
 *
 * オプション:
 *   --date=YYYY-MM-DD  当日として扱う日付（テスト用、省略時は今日）
 *   --dry-run          DB変更なしで対象だけ表示
 *   --only=1to2,2to3,3to4  実行する遷移を限定（カンマ区切り）
 *
 * 設計:
 *   - 各発注を個別トランザクションで処理（1件失敗しても他に影響しない）
 *   - 多重起動防止: flock + .lockファイル
 *   - 履歴記録: order_status_history に changed_by='system_batch'
 *   - 予算実績: 3→4 のみ applyBudgetActualDelta() で反映
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
    'only'    => ['1to2', '2to3', '3to4'],
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
        fwrite(STDERR, "Usage: php auto_advance_status.php [--date=YYYY-MM-DD] [--dry-run] [--only=1to2,2to3,3to4]\n");
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
// 2→3: 全種別、「予定日 = 当日」
// ================================================================
if (in_array('2to3', $opts['only'], true)) {
    // 修理は order_repair_details.repair_schedule_date を使う
    $repairTargets = query(
        "SELECT o.id, o.type, o.category_code, o.shop_code, o.date,
                rd.repair_schedule_date, rd.repair_completed_date
         FROM orders o
         JOIN order_repair_details rd ON rd.order_id = o.id
         WHERE o.status = 2
           AND o.type = 'repair'
           AND rd.repair_schedule_date = :today",
        [':today' => $todayStr]
    );

    // 備品・部品は orders.delivery_date を使う
    $deliveryTargets = query(
        "SELECT id, type, category_code, shop_code, date, delivery_date, actual_delivery_date
         FROM orders
         WHERE status = 2
           AND type IN ('equipment', 'parts')
           AND delivery_date = :today",
        [':today' => $todayStr]
    );

    $targets = array_merge($repairTargets, $deliveryTargets);
    fwrite(STDOUT, "[INFO] 2→3 対象: " . count($targets) . " 件 (予定日=" . $todayStr . ")\n");

    foreach ($targets as $order) {
        try {
            $typeLabel = orderTypeLabel($order['type']);
            $dateField = $order['type'] === 'repair' ? "repair_schedule_date={$order['repair_schedule_date']}" : "delivery_date={$order['delivery_date']}";

            if ($opts['dry_run']) {
                fwrite(STDOUT, "  [DRY-RUN] {$order['id']} ({$typeLabel}) status 2→3, {$dateField}\n");
            } else {
                advanceOrderStatusTwoToThree($order, $todayStr);
                fwrite(STDOUT, "  ✓ {$order['id']} ({$typeLabel}) status 2→3, {$dateField}\n");
            }
            $total['advanced']++;
        } catch (Throwable $e) {
            fwrite(STDERR, "  [ERROR] {$order['id']}: " . $e->getMessage() . "\n");
            $total['errors']++;
        }
    }
}

// ================================================================
// 3→4: 全種別、「予定日翌日 = 当日」
// ================================================================
if (in_array('3to4', $opts['only'], true)) {
    // 修理: repair_schedule_date + 1 = 当日
    $repairTargets = query(
        "SELECT o.id, o.type, o.category_code, o.shop_code, o.date, o.estimate_amount, o.final_amount,
                rd.repair_schedule_date
         FROM orders o
         JOIN order_repair_details rd ON rd.order_id = o.id
         WHERE o.status = 3
           AND o.type = 'repair'
           AND rd.repair_schedule_date = :yesterday",
        [':yesterday' => $yesterdayStr]
    );

    // 備品・部品: delivery_date + 1 = 当日
    $deliveryTargets = query(
        "SELECT id, type, category_code, shop_code, date, delivery_date, estimate_amount, final_amount
         FROM orders
         WHERE status = 3
           AND type IN ('equipment', 'parts')
           AND delivery_date = :yesterday",
        [':yesterday' => $yesterdayStr]
    );

    $targets = array_merge($repairTargets, $deliveryTargets);
    fwrite(STDOUT, "[INFO] 3→4 対象: " . count($targets) . " 件 (予定日翌日=" . $todayStr . ")\n");

    foreach ($targets as $order) {
        try {
            $typeLabel = orderTypeLabel($order['type']);
            $estimate = (int)($order['estimate_amount'] ?? 0);
            $finalRaw = $order['final_amount'];
            $isFinalSet = $finalRaw !== null;

            if ($opts['dry_run']) {
                if ($isFinalSet) {
                    $delta = (int)$finalRaw - $estimate;
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} ({$typeLabel}) status 3→4, final_amount={$finalRaw}(手動), 予算差分={$delta}\n");
                } else {
                    fwrite(STDOUT, "  [DRY-RUN] {$order['id']} ({$typeLabel}) status 3→4, final_amount未設定→estimate({$estimate})適用\n");
                }
            } else {
                $applied = advanceOrderStatusThreeToFour($order);
                if ($applied['final_was_set']) {
                    fwrite(STDOUT, "  ✓ {$order['id']} ({$typeLabel}) status 3→4, final={$applied['final_amount']}(手動), 予算差分={$applied['delta']}\n");
                } else {
                    fwrite(STDOUT, "  ✓ {$order['id']} ({$typeLabel}) status 3→4, final={$applied['final_amount']}(estimate適用)\n");
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
 * 2→3 遷移。備品: actual_delivery_date 未設定なら今日。修理: repair_completed_date 未設定なら今日。
 */
function advanceOrderStatusTwoToThree(array $order, string $todayStr): void
{
    beginTransaction();
    try {
        if ($order['type'] === 'equipment' && empty($order['actual_delivery_date'])) {
            execute(
                'UPDATE orders SET status = 3, actual_delivery_date = :d WHERE id = :oid',
                [':d' => $todayStr, ':oid' => $order['id']]
            );
        } elseif ($order['type'] === 'repair' && empty($order['repair_completed_date'])) {
            execute(
                'UPDATE orders SET status = 3 WHERE id = :oid',
                [':oid' => $order['id']]
            );
            execute(
                'UPDATE order_repair_details SET repair_completed_date = :d WHERE order_id = :oid',
                [':d' => $todayStr, ':oid' => $order['id']]
            );
        } else {
            execute(
                'UPDATE orders SET status = 3 WHERE id = :oid',
                [':oid' => $order['id']]
            );
        }

        insertStatusHistory($order['id'], 3, '自動遷移バッチ: 2→3 (予定日到来)');
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

        // 予算実績反映（差分が 0 でも applyBudgetActualDelta 内でスキップされる）
        if ($delta !== 0) {
            applyBudgetActualDelta($order, $delta);
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
