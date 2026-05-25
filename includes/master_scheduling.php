<?php declare(strict_types=1);

/**
 * 快活システム - マスタ予約更新（フェーズ1）共通処理
 *
 * master_excel.php から呼ばれ、Excel UL 時に「適用日」列を見て
 * 各行を「即時反映」と「予約反映」に振り分ける。
 *
 * 設計方針:
 *   - apply_date が空欄 or 当日以前 → 即時反映（既存ロジック）
 *   - apply_date が翌日以降 → master_scheduled_changes に登録
 *   - 同一(target_table, record_key)の既存pendingがあれば cancelled にして
 *     新規予約を登録（競合解決 5a: 最新優先）
 *   - delete は今フェーズではスコープ外（常に即時側に残す）
 *   - パスワード等の機密フィールドは登録前に transform でハッシュ化
 *
 * タイムゾーン: Asia/Tokyo 固定（config.php で設定済み想定）
 */

require_once __DIR__ . '/db.php';

use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

// ============================================================
// 1. 適用日のパース
// ============================================================

/**
 * Excel の「適用日」セル値を DateTimeImmutable (Asia/Tokyo 0:00) に正規化
 *
 *  - null / '' → null（即時扱い）
 *  - 数値（Excel シリアル値）→ Asia/Tokyo の 0:00 に変換
 *  - 文字列 "Y-m-d" / "Y/m/d" / "Y年m月d日" → パース
 *  - 不正値 → false（呼び出し側でエラー化）
 *
 * @return DateTimeImmutable|null|false
 */
function parseScheduledAt($rawValue)
{
    if ($rawValue === null || $rawValue === '') {
        return null;
    }

    $tz = new DateTimeZone('Asia/Tokyo');

    // 1) 数値（Excel シリアル値: 1900-01-01 基準 + うるう年バグ補正済み）
    if (is_int($rawValue) || is_float($rawValue)) {
        try {
            $dt = PhpSpreadsheetDate::excelToDateTimeObject((float)$rawValue);
            // excelToDateTimeObject は UTC で返るため、日付部分だけ取り出して Asia/Tokyo の 0:00 に
            $dateStr = $dt->format('Y-m-d');
            return new DateTimeImmutable($dateStr . ' 00:00:00', $tz);
        } catch (Throwable $e) {
            return false;
        }
    }

    // 2) 文字列
    if (is_string($rawValue)) {
        $s = trim($rawValue);
        if ($s === '') return null;

        // PhpSpreadsheet が日時を ISO 風で返すケース ("2026-05-24 00:00:00" 等)
        $formats = ['Y-m-d', 'Y/m/d', 'Y年m月d日', 'Y-m-d H:i:s', 'Y/m/d H:i:s'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s, $tz);
            if ($dt !== false) {
                // 日付部分のみ採用、時刻は 0:00 に揃える
                return new DateTimeImmutable($dt->format('Y-m-d') . ' 00:00:00', $tz);
            }
        }
        return false;
    }

    // 3) DateTime オブジェクト
    if ($rawValue instanceof DateTimeInterface) {
        return new DateTimeImmutable($rawValue->format('Y-m-d') . ' 00:00:00', $tz);
    }

    return false;
}

/**
 * 「予約とすべきか」判定
 *
 * 翌日以降 → 予約 (true)
 * 当日 or 過去 → 即時反映 (false)
 *
 * @param DateTimeImmutable $applyDate (0:00 始まりであること)
 * @param DateTimeImmutable|null $now 主にテスト用。null なら 'today'
 */
function shouldSchedule(DateTimeImmutable $applyDate, ?DateTimeImmutable $now = null): bool
{
    $tz = new DateTimeZone('Asia/Tokyo');
    $today = $now ?? new DateTimeImmutable('today', $tz);
    $todayMidnight = new DateTimeImmutable($today->format('Y-m-d') . ' 00:00:00', $tz);
    return $applyDate > $todayMidnight;
}

// ============================================================
// 2. 行ごとの振り分け（即時 / 予約 / エラー）
// ============================================================

/**
 * validateAndNormalize 後の rows と computeDiff 後の diff を受け取り、
 * 「即時反映用 diff」と「予約用 rows」に分割する。
 *
 *  - delete は常に即時側に残す（フェーズ1スコープ外）
 *  - insert / update: 行内の __apply_date を見て振り分け
 *  - 振り分け後、即時側 diff から予約行を除外
 *
 * @param array $diff computeDiff の戻り値 {insert, update, delete}
 * @param array $rowsByKey __apply_date 含む validated rows を key_field でインデックス化したもの
 * @param string $keyField
 * @return array{immediate_diff: array, scheduled: array<int,array>}
 *   scheduled[i] = ['operation' => 'insert'|'update', 'key' => ..., 'after' => ..., 'changed_fields' => [...], 'apply_date' => DateTimeImmutable]
 */
function splitDiffByApplyDate(array $diff, array $rowsByKey, string $keyField): array
{
    $immediateInsert = [];
    $immediateUpdate = [];
    $scheduled = [];

    foreach ($diff['insert'] as $row) {
        $key = (string)($row[$keyField] ?? '');
        $applyDate = $rowsByKey[$key]['__apply_date'] ?? null;
        if ($applyDate instanceof DateTimeImmutable && shouldSchedule($applyDate)) {
            $scheduled[] = [
                'operation'      => 'insert',
                'key'            => $key,
                'after'          => $row,
                'changed_fields' => array_keys($row),
                'apply_date'     => $applyDate,
            ];
        } else {
            $immediateInsert[] = $row;
        }
    }

    foreach ($diff['update'] as $entry) {
        $key = (string)$entry['key'];
        $applyDate = $rowsByKey[$key]['__apply_date'] ?? null;
        if ($applyDate instanceof DateTimeImmutable && shouldSchedule($applyDate)) {
            $scheduled[] = [
                'operation'      => 'update',
                'key'            => $key,
                'after'          => $entry['after'],
                'before'         => $entry['before'],
                'changed_fields' => $entry['changed_fields'],
                'apply_date'     => $applyDate,
            ];
        } else {
            $immediateUpdate[] = $entry;
        }
    }

    return [
        'immediate_diff' => [
            'insert' => $immediateInsert,
            'update' => $immediateUpdate,
            'delete' => $diff['delete'], // delete は常に即時
        ],
        'scheduled' => $scheduled,
    ];
}

// ============================================================
// 3. 競合検出（dry_run プレビュー用）
// ============================================================

/**
 * 予約しようとしている (target_table, record_key) ペアごとに、
 * 既存の pending な予約を取得する。dry_run 時にプレビューで
 * 「N 件の既存予約を上書きします」と警告するため。
 *
 * @param string $table target_table
 * @param array $scheduledRows splitDiffByApplyDate の 'scheduled' 戻り値
 * @return array<int,array> [{id, record_key, scheduled_at, operation, change_data}, ...]
 */
function findConflictingPendingChanges(string $table, array $scheduledRows): array
{
    if (empty($scheduledRows)) return [];

    $keys = array_unique(array_map(function($r) { return (string)$r['key']; }, $scheduledRows));
    if (empty($keys)) return [];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $params = array_merge([$table], $keys);

    $rows = query(
        "SELECT id, record_key, scheduled_at, operation, change_data
           FROM master_scheduled_changes
          WHERE target_table = ?
            AND status = 'pending'
            AND record_key IN ({$placeholders})
          ORDER BY scheduled_at ASC, id ASC",
        $params
    );

    return $rows;
}

// ============================================================
// 4. 予約レコードの INSERT（競合解決 5a 含む）
// ============================================================

/**
 * 予約行を master_scheduled_changes に INSERT。
 * 同一(target_table, record_key) の既存 pending があれば cancelled にする。
 *
 * 注意: トランザクションは呼び出し側で管理する想定。
 *       handleMasterUpload は applyDiff の外側でこの関数を呼ぶため、
 *       本関数内で独自にトランザクションを張る。
 *
 * @param string $table target_table
 * @param array $scheduledRows splitDiffByApplyDate の戻り値の 'scheduled'
 * @param int|null $userId
 * @param array $opts
 *   - 'transform' => callable(array $row, string $op): array  保存前変換（password→hashなど）
 *   - 'auto_fields' => 除外フィールド（created_at, updated_at）デフォルト同じ
 * @return array{inserted: int, cancelled: int, ids: int[]}
 */
function insertScheduledChanges(string $table, array $scheduledRows, ?int $userId, array $opts = []): array
{
    if (empty($scheduledRows)) {
        return ['inserted' => 0, 'cancelled' => 0, 'ids' => []];
    }

    $transform = $opts['transform'] ?? null;
    $autoFields = $opts['auto_fields'] ?? ['created_at', 'updated_at'];
    $maskFields = $opts['mask_fields'] ?? [];  // change_data 内マスク（passwordなどは事前にhash化される想定だが二重保険）

    $insertedIds = [];
    $cancelledCount = 0;

    beginTransaction();
    try {
        foreach ($scheduledRows as $entry) {
            $key = $entry['key'];
            $operation = $entry['operation'];
            $applyDate = $entry['apply_date'];  // DateTimeImmutable

            // 競合解決 5a: 既存 pending を cancelled に
            $existingIds = query(
                "SELECT id FROM master_scheduled_changes
                  WHERE target_table = :t AND record_key = :k AND status = 'pending'
                  FOR UPDATE",
                [':t' => $table, ':k' => $key]
            );
            if (!empty($existingIds)) {
                $ids = array_column($existingIds, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                execute(
                    "UPDATE master_scheduled_changes
                        SET status = 'cancelled', updated_at = NOW()
                      WHERE id IN ({$placeholders})",
                    $ids
                );
                $cancelledCount += count($ids);
            }

            // change_data 構築
            // - after: 保存前 transform を通した行（password→hash 後）
            // - changed_fields: cron 反映時にどの列を UPDATE するかの指針
            // - before: update 時のみ（参考情報）
            $after = $entry['after'];
            // __apply_date など制御列を落とす
            unset($after['__apply_date'], $after['__row_num']);
            if ($transform) {
                $after = $transform($after, $operation);
            }
            // auto_fields は cron 側でも自動付与されるため change_data からは外す
            foreach ($autoFields as $f) {
                unset($after[$f]);
            }
            // mask_fields は cron で UPDATE 時に必要なので落とせない。
            // ここでマスクしないこと（パスワードは事前 transform で hash 化済み）。

            $changeData = [
                'operation'      => $operation,
                'after'          => $after,
                'changed_fields' => $entry['changed_fields'] ?? [],
            ];
            if ($operation === 'update' && isset($entry['before'])) {
                $beforeClean = $entry['before'];
                // before からも機密フィールドはマスクで残す（参考表示用）
                foreach ($maskFields as $f) {
                    if (array_key_exists($f, $beforeClean)) {
                        $beforeClean[$f] = '********';
                    }
                }
                $changeData['before'] = $beforeClean;
            }

            execute(
                "INSERT INTO master_scheduled_changes
                   (target_table, operation, record_key, change_data, scheduled_at, status, created_by_id)
                 VALUES
                   (:t, :op, :k, :cd, :sa, 'pending', :uid)",
                [
                    ':t'   => $table,
                    ':op'  => $operation,
                    ':k'   => $key,
                    ':cd'  => json_encode($changeData, JSON_UNESCAPED_UNICODE),
                    ':sa'  => $applyDate->format('Y-m-d H:i:s'),
                    ':uid' => $userId,
                ]
            );
            $insertedIds[] = (int)lastInsertId();
        }
        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }

    return [
        'inserted'  => count($insertedIds),
        'cancelled' => $cancelledCount,
        'ids'       => $insertedIds,
    ];
}

