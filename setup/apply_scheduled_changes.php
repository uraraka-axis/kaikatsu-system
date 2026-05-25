<?php declare(strict_types=1);

/**
 * 快活システム - マスタ予約更新 適用バッチ
 *
 * 起動: CLI のみ（Web 経由は拒否）
 * 役割:
 *   1) master_scheduled_changes WHERE scheduled_at <= NOW() AND status='pending' を順次取得
 *   2) target_table に応じて INSERT / UPDATE を実行
 *   3) 成功: status='applied', applied_at=NOW(), master_change_log にも記録
 *   4) 失敗: status='error', error_message にエラー詳細を保存（次の行へ進む）
 *
 * 設計上の注意:
 *   - 各レコードを個別トランザクションで処理（1件失敗しても他は影響しない）
 *   - 二重実行防止のため flock() による排他制御
 *   - パスワード等は登録時点で transform 済み(bcrypt hash)の前提
 *   - shops の shop_categories 同期はフェーズ1スコープ外（未同期になる旨を運用者に伝える）
 *
 * Windows タスクスケジューラ設定例:
 *   php.exe C:\Users\ssasa\kaikatsu-system\setup\apply_scheduled_changes.php
 *   トリガー: 毎日 0:05
 *
 * 本番 (さくらレンタルサーバ) cron 設定例:
 *   5 0 * * * /usr/local/bin/php /home/.../kaikatsu-system/setup/apply_scheduled_changes.php
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
require_once __DIR__ . '/../includes/master_excel.php';

// タイムゾーン明示
date_default_timezone_set('Asia/Tokyo');

// 排他制御（多重起動防止）
$lockFile = __DIR__ . '/.apply_scheduled_changes.lock';
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

// ロギング
$startedAt = date('Y-m-d H:i:s');
fwrite(STDOUT, "[INFO] {$startedAt} 予約適用バッチ開始\n");

$totalApplied = 0;
$totalErrors = 0;
$totalSkipped = 0;

try {
    // 適用対象を取得（古い順）
    $pending = query(
        "SELECT id, target_table, operation, record_key, change_data, scheduled_at, created_by_id
           FROM master_scheduled_changes
          WHERE status = 'pending'
            AND scheduled_at <= NOW()
          ORDER BY scheduled_at ASC, id ASC"
    );

    fwrite(STDOUT, "[INFO] 適用対象: " . count($pending) . "件\n");

    foreach ($pending as $row) {
        $id = (int)$row['id'];
        $table = $row['target_table'];
        $operation = $row['operation'];
        $recordKey = $row['record_key'];
        $userId = $row['created_by_id'] !== null ? (int)$row['created_by_id'] : null;

        try {
            $changeData = json_decode($row['change_data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            markScheduledChangeError($id, 'change_data の JSON パースに失敗: ' . $e->getMessage());
            fwrite(STDERR, "[ERROR] id={$id} JSON 不正: {$e->getMessage()}\n");
            $totalErrors++;
            continue;
        }

        $after = $changeData['after'] ?? [];
        $changedFields = $changeData['changed_fields'] ?? array_keys($after);

        // 1件ごとに独立したトランザクション
        beginTransaction();
        try {
            // shop_categories は複合キー (shop_code/category_code) のため特別扱い
            if ($table === 'shop_categories') {
                applyScheduledShopCategoryChange($operation, $recordKey);
            } else {
                $keyField = resolveKeyField($table);
                if ($operation === 'insert') {
                    applyScheduledInsert($table, $after);
                } elseif ($operation === 'update') {
                    applyScheduledUpdate($table, $keyField, $recordKey, $after, $changedFields);
                } elseif ($operation === 'delete') {
                    // 通常マスタの delete 予約はフェーズ1未対応
                    throw new RuntimeException('delete 予約はフェーズ1未対応です');
                } else {
                    throw new RuntimeException("未知の operation: {$operation}");
                }
            }

            // master_change_log に記録（cron 由来と分かるよう upload_filename を特殊な値に）
            logMasterChange(
                $table,
                $operation,
                $recordKey,
                [
                    'after'            => $after,
                    'changed_fields'   => $changedFields,
                    'scheduled_origin' => true,
                    'scheduled_id'     => $id,
                ],
                $userId,
                "scheduled_batch:{$id}",
                generateUuid()
            );

            // status='applied' に更新
            execute(
                "UPDATE master_scheduled_changes
                    SET status = 'applied', applied_at = NOW(), error_message = NULL
                  WHERE id = :id",
                [':id' => $id]
            );

            commit();
            $totalApplied++;
            fwrite(STDOUT, "[OK] id={$id} {$table}/{$operation}/{$recordKey} 反映完了\n");

        } catch (Throwable $e) {
            rollback();
            $errMsg = $e->getMessage();
            markScheduledChangeError($id, $errMsg);
            fwrite(STDERR, "[ERROR] id={$id} {$table}/{$operation}/{$recordKey} 失敗: {$errMsg}\n");
            $totalErrors++;
        }
    }

} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] バッチ実行中に致命的エラー: " . $e->getMessage() . "\n");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(3);
}

$endedAt = date('Y-m-d H:i:s');
fwrite(STDOUT, "[INFO] {$endedAt} 予約適用バッチ終了 (applied={$totalApplied} errors={$totalErrors})\n");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);

// ============================================================
// ヘルパー関数
// ============================================================

/**
 * テーブル名からマッチングキーのカラム名を解決
 * Excel UL 時の key_field と同じものを返す
 */
function resolveKeyField(string $table): string
{
    $map = [
        'zones'     => 'code',
        'areas'     => 'code',
        'shops'     => 'code',
        'suppliers' => 'code',
        'products'  => 'code',
        'users'     => 'login_id',
        'budgets'   => 'id',  // budgets はピボット運用、フェーズ1では予約対象外想定
    ];
    if (!isset($map[$table])) {
        throw new RuntimeException("未対応の target_table: {$table}");
    }
    return $map[$table];
}

/**
 * 予約 INSERT を実行
 */
function applyScheduledInsert(string $table, array $after): void
{
    if (empty($after)) {
        throw new RuntimeException('after が空です');
    }
    // created_at / updated_at はテーブル側で自動付与
    $autoFields = ['created_at', 'updated_at'];
    $cols = [];
    $vals = [];
    $params = [];
    foreach ($after as $f => $v) {
        if (in_array($f, $autoFields, true)) continue;
        $cols[] = "`{$f}`";
        $vals[] = ":{$f}";
        $params[":{$f}"] = $v;
    }
    if (empty($cols)) {
        throw new RuntimeException('INSERT 対象カラムがありません');
    }
    $sql = "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    execute($sql, $params);
}

/**
 * 予約 UPDATE を実行
 * changed_fields に指定された列のみ UPDATE
 */
function applyScheduledUpdate(string $table, string $keyField, string $recordKey, array $after, array $changedFields): void
{
    if (empty($changedFields)) {
        // 変更フィールド指定なし → 全カラム UPDATE (after の内容)
        $changedFields = array_keys($after);
    }
    $autoFields = ['created_at', 'updated_at'];
    $sets = [];
    $params = [':_key' => $recordKey];
    foreach ($changedFields as $f) {
        if ($f === $keyField) continue;
        if (in_array($f, $autoFields, true)) continue;
        if (!array_key_exists($f, $after)) continue;
        $sets[] = "`{$f}` = :{$f}";
        $params[":{$f}"] = $after[$f];
    }
    if (empty($sets)) {
        throw new RuntimeException('UPDATE 対象カラムがありません');
    }
    $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$keyField}` = :_key";
    $affected = execute($sql, $params);
    if ($affected === 0) {
        throw new RuntimeException("対象レコードが見つかりません ({$keyField}={$recordKey})");
    }
}

/**
 * shop_categories の予約反映（複合キー対応）
 *
 * record_key は 'shop_code/category_code' 形式。
 * - insert: 既に行が存在すれば skip（UNIQUE 違反回避、並行操作で既追加の場合）
 * - delete: 既に行が無ければ skip（既削除の場合）
 */
function applyScheduledShopCategoryChange(string $operation, string $recordKey): void
{
    $parts = explode('/', $recordKey, 2);
    if (count($parts) !== 2) {
        throw new RuntimeException("shop_categories の record_key 形式不正: {$recordKey}");
    }
    [$shopCode, $catCode] = $parts;
    if ($shopCode === '' || $catCode === '') {
        throw new RuntimeException("shop_categories の record_key 形式不正: {$recordKey}");
    }

    if ($operation === 'insert') {
        // 既に存在していたら skip
        $existing = getOne(
            "SELECT 1 FROM shop_categories
              WHERE shop_code = :sc AND category_code = :cc",
            [':sc' => $shopCode, ':cc' => $catCode]
        );
        if ($existing) {
            fwrite(STDOUT, "[INFO] shop_categories/{$recordKey} は既に存在するため skip\n");
            return;
        }
        execute(
            "INSERT INTO shop_categories (shop_code, category_code) VALUES (:sc, :cc)",
            [':sc' => $shopCode, ':cc' => $catCode]
        );
    } elseif ($operation === 'delete') {
        $affected = execute(
            "DELETE FROM shop_categories
              WHERE shop_code = :sc AND category_code = :cc",
            [':sc' => $shopCode, ':cc' => $catCode]
        );
        if ($affected === 0) {
            fwrite(STDOUT, "[INFO] shop_categories/{$recordKey} は既に存在しないため skip\n");
        }
    } else {
        throw new RuntimeException("shop_categories の未対応 operation: {$operation}");
    }
}

/**
 * 予約レコードを error 状態に
 * 別トランザクションで実行（呼び出し元の rollback とは独立させたいため）
 */
function markScheduledChangeError(int $id, string $message): void
{
    try {
        execute(
            "UPDATE master_scheduled_changes
                SET status = 'error', error_message = :msg
              WHERE id = :id",
            [':id' => $id, ':msg' => mb_substr($message, 0, 1000)]
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] error_message 書込失敗: " . $e->getMessage() . "\n");
    }
}
