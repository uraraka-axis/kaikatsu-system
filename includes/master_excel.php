<?php declare(strict_types=1);

/**
 * 快活システム - マスタCRUD（Excelアップロード方式）共通基盤
 *
 * master-crud-spec.md 参照
 *
 * 各マスタAPI (api/admin/master/{type}.php) はこのファイルから以下を呼び出す:
 *   - parseUploadedXlsx()   : 1) Excel解析
 *   - validateAndNormalize(): 2) バリデーション
 *   - fetchCurrentRecords() : 3) DB現状取得
 *   - computeDiff()         : 4) 差分検出
 *   - checkFkBlocks()       : 5) 削除可否チェック
 *   - applyDiff()           : 6) 適用+監査ログ
 *
 * ダウンロードAPI (api/export/master/{type}.php) は:
 *   - generateMasterXlsx()  : 現状の.xlsx出力
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ============================================================
// 1. Excel解析
// ============================================================

/**
 * アップロードされた .xlsx ファイルを解析して連想配列の配列を返す
 *
 * @param string $filePath 一時保管されたファイルパス
 * @param array<int,array{field:string,header:string}> $columns 期待列定義
 * @return array{rows: array<int,array<string,mixed>>, errors: array<int,array>}
 *         rows[i] = ['__row_num' => 2, 'code' => '100', 'name' => '東日本', ...]
 *         errors = ヘッダ不整合等の致命的エラー（あれば rows は空）
 */
function parseUploadedXlsx(string $filePath, array $columns): array
{
    try {
        $spreadsheet = IOFactory::load($filePath);
    } catch (Throwable $e) {
        return [
            'rows'   => [],
            'errors' => [['row' => 0, 'column' => '', 'value' => '', 'message' => 'Excelファイルが読み込めません: ' . $e->getMessage()]],
        ];
    }

    $sheet = $spreadsheet->getActiveSheet();
    $highest = $sheet->getHighestRowAndColumn();
    $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest['column']);

    // 1行目: ヘッダ検証
    $expectedHeaders = array_column($columns, 'header');
    $actualHeaders = [];
    for ($c = 1; $c <= count($expectedHeaders); $c++) {
        $val = $sheet->getCell([$c, 1])->getValue();
        $actualHeaders[] = is_string($val) ? trim($val) : '';
    }
    if ($actualHeaders !== $expectedHeaders) {
        $expected = implode(' / ', $expectedHeaders);
        $actual = implode(' / ', $actualHeaders);
        return [
            'rows'   => [],
            'errors' => [[
                'row'     => 1,
                'column'  => '',
                'value'   => $actual,
                'message' => "ヘッダ行が期待値と異なります。期待値: [{$expected}] / 実際: [{$actual}]",
            ]],
        ];
    }

    // 2行目以降: データ取得
    $rows = [];
    $highestRow = $highest['row'];
    for ($r = 2; $r <= $highestRow; $r++) {
        $row = ['__row_num' => $r];
        $allEmpty = true;
        foreach ($columns as $i => $col) {
            $val = $sheet->getCell([$i + 1, $r])->getValue();
            if ($val !== null && $val !== '') {
                $allEmpty = false;
            }
            // 数値型でも文字列で受け取れるよう、明示的にstring化はしない（バリデーションで型変換）
            $row[$col['field']] = $val;
        }
        if (!$allEmpty) {
            $rows[] = $row;
        }
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return ['rows' => $rows, 'errors' => []];
}

// ============================================================
// 2. バリデーション + 正規化
// ============================================================

/**
 * パース済み行をフィールド定義に沿ってバリデーション & 正規化
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,array> $columns
 * @param array $extraRules 追加ルール
 *   - 'enums' => ['field' => ['allowed' => ['shop','admin']]]  ENUM チェック
 *   - 'pattern' は columns 側に書く
 * @return array{rows: array, errors: array}
 */
function validateAndNormalize(array $rows, array $columns, array $extraRules = []): array
{
    $errors = [];
    $normalized = [];
    $keyField = $extraRules['key_field'] ?? null;
    $seenKeys = [];

    foreach ($rows as $row) {
        $rowNum = $row['__row_num'];
        $normalizedRow = ['__row_num' => $rowNum];

        foreach ($columns as $col) {
            $field = $col['field'];
            $header = $col['header'];
            $type = $col['type'] ?? 'string';
            $required = $col['required'] ?? false;
            $rawVal = $row[$field] ?? null;

            // 共通: trim
            if (is_string($rawVal)) {
                $rawVal = trim($rawVal);
            }

            // 必須チェック
            $isEmpty = ($rawVal === null || $rawVal === '');
            if ($required && $isEmpty) {
                $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => '', 'message' => '必須項目です'];
                $normalizedRow[$field] = null;
                continue;
            }
            if (!$required && $isEmpty) {
                $normalizedRow[$field] = null;
                continue;
            }

            // 型変換
            $converted = null;
            switch ($type) {
                case 'string':
                    $converted = is_string($rawVal) ? $rawVal : (string)$rawVal;
                    $max = $col['max_length'] ?? null;
                    if ($max !== null && mb_strlen($converted) > $max) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => $converted, 'message' => "{$max}文字以内で指定してください"];
                        $normalizedRow[$field] = $converted;
                        continue 2;
                    }
                    if (isset($col['pattern']) && !preg_match($col['pattern'], $converted)) {
                        $msg = $col['pattern_msg'] ?? '形式が不正です';
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => $converted, 'message' => $msg];
                        $normalizedRow[$field] = $converted;
                        continue 2;
                    }
                    break;

                case 'int':
                    if (is_int($rawVal) || is_float($rawVal)) {
                        $converted = (int)$rawVal;
                    } elseif (is_string($rawVal) && preg_match('/^-?\d+$/', $rawVal)) {
                        $converted = (int)$rawVal;
                    } else {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => (string)$rawVal, 'message' => '整数で指定してください'];
                        $normalizedRow[$field] = $rawVal;
                        continue 2;
                    }
                    if (isset($col['min']) && $converted < $col['min']) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => (string)$converted, 'message' => "{$col['min']}以上で指定してください"];
                    }
                    if (isset($col['max']) && $converted > $col['max']) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => (string)$converted, 'message' => "{$col['max']}以下で指定してください"];
                    }
                    break;

                case 'bool':
                    $converted = normalizeBool($rawVal);
                    if ($converted === null) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => (string)$rawVal, 'message' => 'TRUE / FALSE で指定してください'];
                        $normalizedRow[$field] = $rawVal;
                        continue 2;
                    }
                    break;

                case 'enum':
                    $allowed = $col['allowed'] ?? [];
                    $converted = is_string($rawVal) ? $rawVal : (string)$rawVal;
                    if (!in_array($converted, $allowed, true)) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => $converted, 'message' => '許可された値: ' . implode(' / ', $allowed)];
                    }
                    break;

                case 'date_optional':
                    // マスタ予約更新用「適用日」列。
                    // 空欄→ null（即時扱い）、日付→ DateTimeImmutable に正規化。
                    // 値は別キー __apply_date に格納する（DBカラムと混同しないよう field 自体は null のまま残す）
                    require_once __DIR__ . '/master_scheduling.php';
                    $parsed = parseScheduledAt($rawVal);
                    if ($parsed === false) {
                        $errors[] = ['row' => $rowNum, 'column' => $header, 'value' => (string)$rawVal, 'message' => '日付として認識できません (YYYY-MM-DD 形式で入力してください)'];
                        $normalizedRow[$field] = null;
                        continue 2;
                    }
                    $normalizedRow['__apply_date'] = $parsed;  // DateTimeImmutable or null
                    $converted = null;  // 実テーブル側には保存しない
                    break;

                default:
                    $converted = $rawVal;
            }

            $normalizedRow[$field] = $converted;
        }

        // 主キー重複チェック（Excel内）
        if ($keyField && isset($normalizedRow[$keyField]) && $normalizedRow[$keyField] !== null) {
            $key = (string)$normalizedRow[$keyField];
            if (isset($seenKeys[$key])) {
                $errors[] = ['row' => $rowNum, 'column' => '', 'value' => $key, 'message' => "Excel内で {$keyField} '{$key}' が重複しています（{$seenKeys[$key]}行目と)"];
            } else {
                $seenKeys[$key] = $rowNum;
            }
        }

        $normalized[] = $normalizedRow;
    }

    return ['rows' => $normalized, 'errors' => $errors];
}

/**
 * 真偽値の正規化（TRUE/FALSE/1/0/true/false 等を受け入れる）
 * @return int|null 1/0 or null（不正値）
 */
function normalizeBool($val): ?int
{
    if (is_bool($val)) return $val ? 1 : 0;
    if (is_int($val) || is_float($val)) {
        $n = (int)$val;
        if ($n === 1) return 1;
        if ($n === 0) return 0;
        return null;
    }
    if (is_string($val)) {
        $s = strtolower(trim($val));
        if (in_array($s, ['true', '1', 'yes', 'y', '有効', '○', 'o'], true)) return 1;
        if (in_array($s, ['false', '0', 'no', 'n', '無効', '×', 'x'], true)) return 0;
    }
    return null;
}

// ============================================================
// 3. DB現状取得
// ============================================================

/**
 * テーブルの現状を取得（key_field で連想配列化）
 *
 * @return array<string,array> [key => row]
 */
function fetchCurrentRecords(string $table, string $keyField, array $selectFields): array
{
    $cols = implode(', ', array_map(fn($f) => "`{$f}`", $selectFields));
    $sql = "SELECT {$cols} FROM `{$table}`";
    $rows = query($sql);

    $indexed = [];
    foreach ($rows as $row) {
        $key = (string)$row[$keyField];
        $indexed[$key] = $row;
    }
    return $indexed;
}

// ============================================================
// 4. 差分検出
// ============================================================

/**
 * Excelデータと現状DBを比較して insert/update/delete を出す
 *
 * @param array $newRows  validateAndNormalize 後の rows
 * @param array $existing key_field でインデックスされた現状
 * @param string $keyField マッチングキーのフィールド名
 * @param array $compareFields 比較対象フィールド（key_field 含む）
 * @param array $opts オプション
 *   - 'skip_fields_if_blank' => ['password'] 空欄ならスキップ（更新しない）
 * @return array{insert: array, update: array, delete: array}
 */
function computeDiff(array $newRows, array $existing, string $keyField, array $compareFields, array $opts = []): array
{
    $skipBlank = $opts['skip_fields_if_blank'] ?? [];

    $insert = [];
    $update = [];
    $delete = [];
    $seenKeys = [];

    foreach ($newRows as $row) {
        if (!isset($row[$keyField]) || $row[$keyField] === null) continue;
        $key = (string)$row[$keyField];
        $seenKeys[$key] = true;

        if (!isset($existing[$key])) {
            // 新規
            $clean = $row;
            unset($clean['__row_num']);
            $insert[] = $clean;
        } else {
            // 既存: 差分検出
            $before = $existing[$key];
            $after = $before; // ベースは既存値
            $changed = [];

            foreach ($compareFields as $f) {
                if (!array_key_exists($f, $row)) continue;
                $newVal = $row[$f];
                // 空欄スキップ対象なら現状維持
                if (in_array($f, $skipBlank, true) && ($newVal === null || $newVal === '')) {
                    continue;
                }
                $oldVal = $before[$f] ?? null;
                // 比較用に文字列化（DBはMySQL経由でstringになるため）
                $oldNorm = is_bool($oldVal) ? (int)$oldVal : $oldVal;
                $newNorm = is_bool($newVal) ? (int)$newVal : $newVal;
                if ((string)$oldNorm !== (string)$newNorm) {
                    $after[$f] = $newVal;
                    $changed[] = $f;
                }
            }

            if (!empty($changed)) {
                $update[] = [
                    'key'             => $key,
                    'before'          => $before,
                    'after'           => $after,
                    'changed_fields'  => $changed,
                ];
            }
        }
    }

    // 削除候補: 既存にあって Excel にないもの
    foreach ($existing as $key => $row) {
        if (!isset($seenKeys[$key])) {
            $delete[] = $row;
        }
    }

    return ['insert' => $insert, 'update' => $update, 'delete' => $delete];
}

// ============================================================
// 5. FK制約による削除可否チェック
// ============================================================

/**
 * 削除候補レコードがFK参照されていないかチェック
 *
 * @param array $deleteRows
 * @param string $keyField
 * @param array $fkChecks [{table, column, label}]
 * @return array{warnings: array, blocked: bool}
 */
function checkFkBlocks(array $deleteRows, string $keyField, array $fkChecks): array
{
    $warnings = [];
    $blocked = false;

    foreach ($deleteRows as $row) {
        $displayKey = $row[$keyField]; // 表示用は常にビジネスキー
        foreach ($fkChecks as $check) {
            // 参照側カラムが PK ではなく別の列（例: products.supplier_id → suppliers.id）を参照している場合は via_key で実際の値を取り出す
            $matchKey = isset($check['via_key']) ? ($row[$check['via_key']] ?? null) : $displayKey;
            if ($matchKey === null || $matchKey === '') continue;

            $sql = "SELECT COUNT(*) AS c FROM `{$check['table']}` WHERE `{$check['column']}` = :k";
            $r = getOne($sql, [':k' => $matchKey]);
            $count = (int)($r['c'] ?? 0);
            if ($count > 0) {
                $warnings[] = [
                    'key'     => $displayKey,
                    'message' => "{$displayKey} は {$check['label']}({$check['table']}) で {$count}件参照されているため削除できません",
                ];
                $blocked = true;
            }
        }
    }

    return ['warnings' => $warnings, 'blocked' => $blocked];
}

/**
 * アップロード値にFK参照があれば、参照先テーブルに存在するか検証
 * （例: areas.zone_code は zones.code に存在しなければエラー）
 *
 * @param array $rows validateAndNormalize 後の rows
 * @param array $columns カラム定義（headerをエラー表示に使用）
 * @param array $fkRefs [{field, ref_table, ref_column}]
 * @param array $headerMap field => header
 * @return array エラーリスト
 */
function checkFkReferences(array $rows, array $fkRefs, array $headerMap): array
{
    $errors = [];

    // 参照先テーブルの値を1回だけキャッシュ
    $refCache = [];
    foreach ($fkRefs as $fk) {
        $cacheKey = $fk['ref_table'] . '.' . $fk['ref_column'];
        if (!isset($refCache[$cacheKey])) {
            $rs = query("SELECT `{$fk['ref_column']}` AS k FROM `{$fk['ref_table']}`");
            $refCache[$cacheKey] = array_column($rs, 'k');
        }
    }

    foreach ($rows as $row) {
        $rowNum = $row['__row_num'] ?? 0;
        foreach ($fkRefs as $fk) {
            $val = $row[$fk['field']] ?? null;
            if ($val === null || $val === '') continue; // NULL/空は別途必須チェックでカバー
            $cacheKey = $fk['ref_table'] . '.' . $fk['ref_column'];
            if (!in_array((string)$val, array_map('strval', $refCache[$cacheKey]), true)) {
                $header = $headerMap[$fk['field']] ?? $fk['field'];
                $errors[] = [
                    'row'     => $rowNum,
                    'column'  => $header,
                    'value'   => (string)$val,
                    'message' => "{$fk['ref_table']} に存在しないコードです",
                ];
            }
        }
    }

    return $errors;
}

/**
 * ユニーク制約チェック（PK 以外のユニーク列が重複していないかを確認）
 *
 *  - アップロードファイル内での重複（同じ short_code が複数行に存在）
 *  - 既存DB との衝突（自分の PK 以外の行と同じユニーク値）
 *
 * @param array $rows         validateAndNormalize 通過済みの行（__row_num 付き）
 * @param array $uniqueFields ['short_code', 'login_id'] のような単一列ユニーク制約のフィールド名
 * @param string $table       テーブル名
 * @param string $keyField    PK列名（自分自身と衝突したと誤判定しないため）
 * @param array $headerMap    field => header の対応表（エラーメッセージ用）
 * @return array エラー配列
 */
function checkUniqueFields(array $rows, array $uniqueFields, string $table, string $keyField, array $headerMap): array
{
    $errors = [];
    if (empty($uniqueFields)) return $errors;

    // 1) アップロードファイル内での重複検出
    foreach ($uniqueFields as $field) {
        $seen = []; // value => first row_num
        foreach ($rows as $row) {
            $val = $row[$field] ?? null;
            if ($val === null || $val === '') continue;
            $val = (string)$val;
            $rowNum = $row['__row_num'] ?? 0;
            if (isset($seen[$val])) {
                $errors[] = [
                    'row'     => $rowNum,
                    'column'  => $headerMap[$field] ?? $field,
                    'value'   => $val,
                    'message' => "ファイル内で重複しています（{$seen[$val]}行目と同じ値）",
                ];
            } else {
                $seen[$val] = $rowNum;
            }
        }
    }

    // 2) 既存 DB との衝突検出（自分の PK 以外で同じユニーク値を持つレコードが存在するか）
    foreach ($uniqueFields as $field) {
        $existing = query("SELECT `{$keyField}` AS k, `{$field}` AS v FROM `{$table}` WHERE `{$field}` IS NOT NULL");
        $existingMap = []; // value => key
        foreach ($existing as $rec) {
            if ($rec['v'] === null || $rec['v'] === '') continue;
            $existingMap[(string)$rec['v']] = (string)$rec['k'];
        }
        foreach ($rows as $row) {
            $val = $row[$field] ?? null;
            if ($val === null || $val === '') continue;
            $val = (string)$val;
            $myKey = (string)($row[$keyField] ?? '');
            if (isset($existingMap[$val]) && $existingMap[$val] !== $myKey) {
                $errors[] = [
                    'row'     => $row['__row_num'] ?? 0,
                    'column'  => $headerMap[$field] ?? $field,
                    'value'   => $val,
                    'message' => "他のレコード（{$keyField}={$existingMap[$val]}）で既に使用されている値です",
                ];
            }
        }
    }

    return $errors;
}

// ============================================================
// 6. 差分適用 + 監査ログ
// ============================================================

/**
 * 差分をトランザクションで適用し、監査ログに記録
 *
 * @param string $table テーブル名
 * @param string $keyField マッチングキー
 * @param array $diff computeDiff の戻り値
 * @param string $uploadFilename ログ用ファイル名
 * @param int|null $userId 変更者ユーザーID
 * @param array $opts
 *   - 'mask_fields' => ['password'] ログ書き込み時にマスクするフィールド
 *   - 'auto_fields' => insert/update時にDB側で自動設定するフィールド（例: ['created_at','updated_at']）
 *   - 'transform' => callable(array $row, string $op): array  保存前の最終変換（password→hash等）
 * @return array{batch_id: string, applied: int}
 */
function applyDiff(string $table, string $keyField, array $diff, string $uploadFilename, ?int $userId, array $opts = []): array
{
    $batchId = generateUuid();
    $maskFields = $opts['mask_fields'] ?? [];
    $autoFields = $opts['auto_fields'] ?? ['created_at', 'updated_at'];
    $transform = $opts['transform'] ?? null;

    $applied = 0;

    beginTransaction();
    try {
        // INSERT
        foreach ($diff['insert'] as $row) {
            $saveRow = $transform ? $transform($row, 'insert') : $row;
            $cols = [];
            $vals = [];
            $params = [];
            foreach ($saveRow as $f => $v) {
                if (in_array($f, $autoFields, true)) continue;
                $cols[] = "`{$f}`";
                $vals[] = ":{$f}";
                $params[":{$f}"] = $v;
            }
            $sql = "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            execute($sql, $params);

            logMasterChange($table, 'insert', (string)$row[$keyField], [
                'after' => maskRow($row, $maskFields),
            ], $userId, $uploadFilename, $batchId);
            $applied++;
        }

        // UPDATE
        foreach ($diff['update'] as $entry) {
            $key = $entry['key'];
            $after = $entry['after'];
            $changedFields = $entry['changed_fields'];
            $saveRow = $transform ? $transform($after, 'update') : $after;

            $sets = [];
            $params = [':_key' => $key];
            foreach ($saveRow as $f => $v) {
                if ($f === $keyField) continue; // PKは更新しない
                if (in_array($f, $autoFields, true)) continue;
                // 変更されたフィールドだけ更新
                if (!in_array($f, $changedFields, true)) continue;
                $sets[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $v;
            }
            if (empty($sets)) {
                // 変更点がauto_fieldsだけだった場合スキップ
                continue;
            }
            $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$keyField}` = :_key";
            execute($sql, $params);

            logMasterChange($table, 'update', $key, [
                'before'         => maskRow($entry['before'], $maskFields),
                'after'          => maskRow($after, $maskFields),
                'changed_fields' => $changedFields,
            ], $userId, $uploadFilename, $batchId);
            $applied++;
        }

        // DELETE
        foreach ($diff['delete'] as $row) {
            $key = $row[$keyField];
            execute("DELETE FROM `{$table}` WHERE `{$keyField}` = :k", [':k' => $key]);

            logMasterChange($table, 'delete', (string)$key, [
                'before' => maskRow($row, $maskFields),
            ], $userId, $uploadFilename, $batchId);
            $applied++;
        }

        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }

    return ['batch_id' => $batchId, 'applied' => $applied];
}

/**
 * master_change_log への1行記録
 */
function logMasterChange(string $table, string $operation, string $recordKey, array $changeData, ?int $userId, string $filename, string $batchId): void
{
    execute(
        "INSERT INTO master_change_log
           (target_table, operation, record_key, change_data, changed_by_id, upload_filename, upload_batch_id)
         VALUES
           (:t, :op, :rk, :cd, :uid, :fn, :bid)",
        [
            ':t'   => $table,
            ':op'  => $operation,
            ':rk'  => $recordKey,
            ':cd'  => json_encode($changeData, JSON_UNESCAPED_UNICODE),
            ':uid' => $userId,
            ':fn'  => $filename,
            ':bid' => $batchId,
        ]
    );
}

/**
 * 指定フィールドをマスクしたコピーを返す
 */
function maskRow(array $row, array $maskFields): array
{
    foreach ($maskFields as $f) {
        if (array_key_exists($f, $row)) {
            $row[$f] = '********';
        }
    }
    return $row;
}

// ============================================================
// 7. ダウンロード用 .xlsx 生成
// ============================================================

/**
 * 現状の.xlsx ファイルを生成してダウンロード送出
 *
 * @param array $columns
 * @param array $rows
 * @param string $filename
 * @param array $opts
 *   - 'mask_fields' => ['password'] ダウンロード時にマスクするフィールド
 *   - 'transform' => callable(array $row): array  出力前の変換
 */
function generateMasterXlsx(array $columns, array $rows, string $filename, array $opts = []): void
{
    $maskFields = $opts['mask_fields'] ?? [];
    $transform = $opts['transform'] ?? null;

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('マスタ');

    // ヘッダ
    $col = 1;
    foreach ($columns as $c) {
        $sheet->setCellValue([$col, 1], $c['header']);
        $col++;
    }
    $lastCol = $col - 1;
    $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);

    $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(24);

    // データ
    $rowNum = 2;
    foreach ($rows as $row) {
        $output = $transform ? $transform($row) : $row;
        $output = maskRow($output, $maskFields);

        $col = 1;
        foreach ($columns as $c) {
            $field = $c['field'];
            $type = $c['type'] ?? 'string';
            $val = $output[$field] ?? null;

            // 型ごとの整形
            if ($type === 'bool') {
                $val = ($val === 1 || $val === true || $val === '1') ? 'TRUE' : 'FALSE';
            }

            $sheet->setCellValue([$col, $rowNum], $val);
            $col++;
        }
        $rowNum++;
    }

    $lastRow = $rowNum - 1;
    if ($lastRow >= 2) {
        $sheet->getStyle('A2:' . $lastColLetter . $lastRow)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'font' => ['size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    // 列幅自動調整
    for ($c = 1; $c <= $lastCol; $c++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getColumnDimension($letter)->setAutoSize(true);
    }

    $sheet->freezePane('A2');

    // 出力
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
}

// ============================================================
// 8. アップロードファイル保管
// ============================================================

/**
 * アップロードされたファイルを一時保管ディレクトリに保存
 *
 * @return array{path: string, original_name: string}
 */
function saveUploadedFile(array $fileEntry, int $userId, string $type): array
{
    if (!isset($fileEntry['tmp_name']) || !is_uploaded_file($fileEntry['tmp_name'])) {
        throw new RuntimeException('アップロードファイルが不正です');
    }
    if (($fileEntry['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('アップロードエラー: code=' . $fileEntry['error']);
    }
    // サイズチェック（10MB）
    $maxSize = 10 * 1024 * 1024;
    if (($fileEntry['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('ファイルサイズが上限(10MB)を超えています');
    }
    // 拡張子チェック
    $origName = $fileEntry['name'] ?? 'upload.xlsx';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        throw new RuntimeException('.xlsx形式のファイルをアップロードしてください');
    }
    // MIME 確認（誤検知あるため緩め: 拡張子優先）
    $mime = mime_content_type($fileEntry['tmp_name']) ?: '';
    $allowedMimes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',          // xlsx は zip 形式
        'application/octet-stream', // 一部環境
    ];
    if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('Excelファイル(.xlsx)以外はアップロードできません');
    }

    // 保存先
    $tempDir = __DIR__ . '/../uploads/master/temp';
    if (!is_dir($tempDir)) {
        if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('一時ディレクトリを作成できません: ' . $tempDir);
        }
    }
    $savedName = sprintf('%d_%s_%s.xlsx', $userId, $type, date('Ymd_His'));
    $savedPath = $tempDir . '/' . $savedName;
    if (!move_uploaded_file($fileEntry['tmp_name'], $savedPath)) {
        throw new RuntimeException('ファイル保存に失敗しました');
    }

    // 1時間以上前の temp ファイルを掃除（best-effort）
    cleanupOldTempFiles($tempDir, 3600);

    return ['path' => $savedPath, 'original_name' => $origName];
}

function cleanupOldTempFiles(string $dir, int $maxAgeSec): void
{
    $cutoff = time() - $maxAgeSec;
    $files = @scandir($dir) ?: [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (is_file($path) && @filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

// ============================================================
// 9. ユーティリティ
// ============================================================

/**
 * RFC 4122 v4 UUID 生成
 */
function generateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // v4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 標準的なアップロード処理を実行するヘルパー
 * 各 api/admin/master/{type}.php から呼ぶ
 *
 * @param array $config マスタ設定
 *   - 'table' => 'zones'
 *   - 'key_field' => 'code'
 *   - 'label' => 'ゾーンマスタ'
 *   - 'columns' => [...]
 *   - 'fk_checks_on_delete' => [...]
 *   - 'mask_fields' => []
 *   - 'transform' => callable
 *   - 'validate_extra' => callable($rows): array $errors  追加バリデーション
 */
function handleMasterUpload(array $config, bool $dryRun): array
{
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;

    // アップロードファイル取得・保存
    if (!isset($_FILES['file'])) {
        jsonError('ファイルが添付されていません', 400);
    }
    try {
        $saved = saveUploadedFile($_FILES['file'], (int)$userId, $config['table']);
    } catch (Throwable $e) {
        jsonError($e->getMessage(), 400);
    }
    $filePath = $saved['path'];
    $origName = $saved['original_name'];

    try {
        // 1) パース
        $parsed = parseUploadedXlsx($filePath, $config['columns']);
        if (!empty($parsed['errors'])) {
            return [
                'success' => false,
                'error'   => 'Excelファイルにエラーがあります',
                'data'    => ['errors' => $parsed['errors']],
            ];
        }

        // 2) バリデーション
        $validated = validateAndNormalize($parsed['rows'], $config['columns'], [
            'key_field' => $config['key_field'],
        ]);
        $errors = $validated['errors'];

        // 2a-post) 行の前処理（preprocess_rows）: マスク値の正規化、条件付き値の調整等を最初に適用
        if (isset($config['preprocess_rows']) && is_callable($config['preprocess_rows'])) {
            $validated['rows'] = $config['preprocess_rows']($validated['rows']);
        }

        // 2b) FK参照チェック（アップロードデータ内のFK値が参照先に存在するか）
        if (!empty($config['fk_checks_on_upload'])) {
            $headerMap = [];
            foreach ($config['columns'] as $c) {
                $headerMap[$c['field']] = $c['header'];
            }
            $fkErrors = checkFkReferences($validated['rows'], $config['fk_checks_on_upload'], $headerMap);
            $errors = array_merge($errors, $fkErrors);
        }

        // 2c) ユニーク制約チェック（unique_fields 設定があれば）
        if (!empty($config['unique_fields'])) {
            if (!isset($headerMap)) {
                $headerMap = [];
                foreach ($config['columns'] as $c) {
                    $headerMap[$c['field']] = $c['header'];
                }
            }
            $uniqErrors = checkUniqueFields(
                $validated['rows'],
                $config['unique_fields'],
                $config['table'],
                $config['key_field'],
                $headerMap
            );
            $errors = array_merge($errors, $uniqErrors);
        }

        // 2d) 追加バリデーション（カスタムコールバック）
        if (isset($config['validate_extra']) && is_callable($config['validate_extra'])) {
            $extraErrors = $config['validate_extra']($validated['rows']);
            $errors = array_merge($errors, $extraErrors);
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'error'   => 'Excelファイルにエラーが ' . count($errors) . ' 件あります',
                'data'    => ['errors' => $errors],
            ];
        }

        // 4) 差分検出と現状取得に使うフィールドリスト
        // compare_fields が指定されていればそれを使う（cat_*等のテーブル外フィールドを除外する用途）
        $compareFields = $config['compare_fields'] ?? array_column($config['columns'], 'field');
        // apply_date 列（date_optional 型）は実テーブルに存在しないので必ず除外
        $compareFields = array_values(array_filter($compareFields, function($f) use ($config) {
            foreach ($config['columns'] as $c) {
                if ($c['field'] === $f && ($c['type'] ?? 'string') === 'date_optional') {
                    return false;
                }
            }
            return true;
        }));

        // 3) 現状取得（compare_fields = 実テーブルのカラムリスト と同じセットを使う）
        $selectFields = $compareFields;
        // fk_checks_on_delete で via_key 指定があれば、その列も取得対象に追加（例: suppliers の id）
        foreach ($config['fk_checks_on_delete'] ?? [] as $fk) {
            if (!empty($fk['via_key']) && !in_array($fk['via_key'], $selectFields, true)) {
                $selectFields[] = $fk['via_key'];
            }
        }
        $existing = fetchCurrentRecords($config['table'], $config['key_field'], $selectFields);
        $diff = computeDiff(
            $validated['rows'],
            $existing,
            $config['key_field'],
            $compareFields,
            ['skip_fields_if_blank' => $config['skip_fields_if_blank'] ?? []]
        );

        // 4a) 予約振り分け: apply_date 列があるマスタの場合、即時/予約に分割
        require_once __DIR__ . '/master_scheduling.php';
        $hasApplyDateColumn = false;
        foreach ($config['columns'] as $c) {
            if (($c['type'] ?? 'string') === 'date_optional') {
                $hasApplyDateColumn = true;
                break;
            }
        }
        $scheduled = [];
        $scheduledExtras = []; // shop_categories 等の中間テーブル予約エントリ
        $immediateDiff = $diff;
        $rowsByKey = [];
        if ($hasApplyDateColumn) {
            // key_field でインデックス化（__apply_date 参照用）
            foreach ($validated['rows'] as $r) {
                $k = (string)($r[$config['key_field']] ?? '');
                if ($k !== '') {
                    $rowsByKey[$k] = $r;
                }
            }
            $split = splitDiffByApplyDate($diff, $rowsByKey, $config['key_field']);
            $immediateDiff = $split['immediate_diff'];
            $scheduled = $split['scheduled'];

            // 中間テーブル（shop_categories 等）の予約差分計算
            // 注意: 本体に変更がない行（cat_* のみ変更）も対象にするため、
            //       scheduled[] ではなく「apply_date が翌日以降の全行」を渡す
            if (isset($config['compute_scheduled_extras']) && is_callable($config['compute_scheduled_extras'])) {
                $scheduledRowsForExtras = [];
                foreach ($validated['rows'] as $r) {
                    $applyDate = $r['__apply_date'] ?? null;
                    if ($applyDate instanceof DateTimeImmutable && shouldSchedule($applyDate)) {
                        $k = (string)($r[$config['key_field']] ?? '');
                        if ($k !== '') {
                            $scheduledRowsForExtras[] = ['key' => $k, 'apply_date' => $applyDate];
                        }
                    }
                }
                if (!empty($scheduledRowsForExtras)) {
                    $scheduledExtras = $config['compute_scheduled_extras']($scheduledRowsForExtras, $rowsByKey);
                }
            }
        }

        // 5) FK削除可否（即時側 delete のみ対象）
        $warnings = [];
        $blocked = false;
        if (!empty($config['fk_checks_on_delete'])) {
            $check = checkFkBlocks($immediateDiff['delete'], $config['key_field'], $config['fk_checks_on_delete']);
            $warnings = $check['warnings'];
            $blocked = $check['blocked'];
        }

        // 付随する別テーブル更新の差分計算（例: 店舗マスタにおける shop_categories）
        // 即時反映行のみ対象とする（予約された shop の shop_categories はフェーズ1スコープ外）
        $extraDiff = null;
        if (isset($config['compute_extra_diff']) && is_callable($config['compute_extra_diff'])) {
            $immediateRows = [];
            if ($hasApplyDateColumn) {
                foreach ($validated['rows'] as $r) {
                    $applyDate = $r['__apply_date'] ?? null;
                    if (!($applyDate instanceof DateTimeImmutable) || !shouldSchedule($applyDate)) {
                        $immediateRows[] = $r;
                    }
                }
            } else {
                $immediateRows = $validated['rows'];
            }
            $extraDiff = $config['compute_extra_diff']($immediateRows, $immediateDiff);
        }

        $summary = [
            'insert' => count($immediateDiff['insert']),
            'update' => count($immediateDiff['update']),
            'delete' => count($immediateDiff['delete']),
            'total'  => count($immediateDiff['insert']) + count($immediateDiff['update']) + count($immediateDiff['delete']),
            'scheduled' => count($scheduled),
            'scheduled_extras' => count($scheduledExtras),
        ];

        // extra_diff の件数を summary に反映 (UI で「変更なし」誤表示を避けるため)
        if ($extraDiff !== null) {
            $extraInsert = count($extraDiff['insert'] ?? []);
            $extraDelete = count($extraDiff['delete'] ?? []);
            // shops テーブル自体に変更がない店舗だけが追加変更を持つ場合を考慮し、件数を加算
            $summary['extra_insert'] = $extraInsert;
            $summary['extra_delete'] = $extraDelete;
            $summary['total'] += $extraInsert + $extraDelete;
        }

        if ($dryRun) {
            // 予約分のプレビュー用表示データを整形
            $scheduledPreview = [];
            foreach ($scheduled as $s) {
                $scheduledPreview[] = [
                    'operation'      => $s['operation'],
                    'key'            => $s['key'],
                    'apply_date'     => $s['apply_date']->format('Y-m-d'),
                    'changed_fields' => $s['changed_fields'] ?? [],
                    'after'          => array_diff_key($s['after'], array_flip(['__apply_date', '__row_num'])),
                    'before'         => $s['before'] ?? null,
                ];
            }
            // 予約Extras（shop_categories 等）のプレビュー整形
            $scheduledExtrasPreview = [];
            foreach ($scheduledExtras as $ex) {
                $scheduledExtrasPreview[] = [
                    'target_table'   => $ex['target_table'] ?? null,
                    'operation'      => $ex['operation'],
                    'key'            => $ex['key'],
                    'apply_date'     => $ex['apply_date']->format('Y-m-d'),
                    'after'          => $ex['after'] ?? null,
                    'before'         => $ex['before'] ?? null,
                ];
            }

            // 競合する既存pendingの一覧（プレビュー警告用）
            // shops 本体と shop_categories 両方の競合をチェック
            $conflicting = [];
            if (!empty($scheduled)) {
                $conflictingRows = findConflictingPendingChanges($config['table'], $scheduled);
                foreach ($conflictingRows as $c) {
                    $cd = json_decode((string)$c['change_data'], true);
                    $conflicting[] = [
                        'id'             => (int)$c['id'],
                        'target_table'   => $c['target_table'] ?? $config['table'],
                        'record_key'     => $c['record_key'],
                        'scheduled_at'   => $c['scheduled_at'],
                        'operation'      => $c['operation'],
                        'changed_fields' => $cd['changed_fields'] ?? [],
                        'after'          => $cd['after'] ?? [],
                        'before'         => $cd['before'] ?? null,
                    ];
                }
            }
            // scheduledExtras の競合も検出（target_table が異なる場合）
            if (!empty($scheduledExtras)) {
                // target_table ごとにまとめて検索
                $byTable = [];
                foreach ($scheduledExtras as $ex) {
                    $tbl = $ex['target_table'] ?? $config['table'];
                    $byTable[$tbl][] = $ex;
                }
                foreach ($byTable as $tbl => $entries) {
                    if ($tbl === $config['table']) continue; // 本体は上で処理済み
                    $extraConflict = findConflictingPendingChanges($tbl, $entries);
                    foreach ($extraConflict as $c) {
                        $cd = json_decode((string)$c['change_data'], true);
                        $conflicting[] = [
                            'id'             => (int)$c['id'],
                            'target_table'   => $tbl,
                            'record_key'     => $c['record_key'],
                            'scheduled_at'   => $c['scheduled_at'],
                            'operation'      => $c['operation'],
                            'changed_fields' => $cd['changed_fields'] ?? [],
                            'after'          => $cd['after'] ?? [],
                            'before'         => $cd['before'] ?? null,
                        ];
                    }
                }
            }
            $summary['scheduled_overwrites'] = count($conflicting);
            return [
                'success' => !$blocked,
                'error'   => $blocked ? '削除できないレコードがあります' : null,
                'data'    => [
                    'summary'           => $summary,
                    'errors'            => [],
                    'warnings'          => $warnings,
                    'diff'              => $immediateDiff,
                    'extra_diff'        => $extraDiff,
                    'scheduled'         => $scheduledPreview,
                    'scheduled_extras'  => $scheduledExtrasPreview,
                    'conflicting'       => $conflicting,
                ],
            ];
        }

        // 確定実行
        if ($blocked) {
            return [
                'success' => false,
                'error'   => '削除できないレコードがあるため適用できません',
                'data'    => ['warnings' => $warnings],
            ];
        }

        // 即時反映分を適用（行数0なら applyDiff は空動作）
        $applied = applyDiff(
            $config['table'],
            $config['key_field'],
            $immediateDiff,
            $origName,
            $userId,
            [
                'mask_fields' => $config['mask_fields'] ?? [],
                'transform'   => $config['transform'] ?? null,
            ]
        );

        // 適用後の追加処理（中間テーブル更新など）。事前計算した extraDiff も渡す
        // 渡すのは即時反映行のみ（予約された行は cron 適用時に処理）
        if (isset($config['after_apply']) && is_callable($config['after_apply'])) {
            $immediateRowsForAfter = [];
            if ($hasApplyDateColumn) {
                foreach ($validated['rows'] as $r) {
                    $applyDate = $r['__apply_date'] ?? null;
                    if (!($applyDate instanceof DateTimeImmutable) || !shouldSchedule($applyDate)) {
                        $immediateRowsForAfter[] = $r;
                    }
                }
            } else {
                $immediateRowsForAfter = $validated['rows'];
            }
            $config['after_apply']($immediateRowsForAfter, $applied['batch_id'], $userId, $origName, $immediateDiff, $extraDiff);
        }

        // 予約分（本体）を master_scheduled_changes に登録
        $scheduledResult = ['inserted' => 0, 'cancelled' => 0, 'ids' => []];
        if (!empty($scheduled)) {
            $scheduledResult = insertScheduledChanges(
                $config['table'],
                $scheduled,
                $userId,
                [
                    'mask_fields' => $config['mask_fields'] ?? [],
                    'transform'   => $config['transform'] ?? null,
                ]
            );
        }

        // 予約Extras（shop_categories 等の中間テーブル）を別途登録
        // target_table は entry 内に指定されているため defaultTable は使われない
        $scheduledExtrasResult = ['inserted' => 0, 'cancelled' => 0, 'ids' => []];
        if (!empty($scheduledExtras)) {
            $scheduledExtrasResult = insertScheduledChanges(
                $config['table'],   // defaultTable（entry の target_table が優先される）
                $scheduledExtras,
                $userId,
                []  // transform/mask は本体テーブル用のため適用しない
            );
        }

        return [
            'success' => true,
            'data'    => [
                'summary'  => $summary,
                'batch_id' => $applied['batch_id'],
                'scheduled_inserted'  => $scheduledResult['inserted'] + $scheduledExtrasResult['inserted'],
                'scheduled_cancelled' => $scheduledResult['cancelled'] + $scheduledExtrasResult['cancelled'],
            ],
        ];
    } finally {
        // 一時ファイルを削除
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}

/**
 * ダウンロード処理ヘルパー
 */
function handleMasterDownload(array $config): void
{
    $sql = "SELECT * FROM `{$config['table']}` ORDER BY sort_order, `{$config['key_field']}`";
    $rows = query($sql);

    $filename = sprintf('%s_master_%s.xlsx', $config['table'], date('Ymd'));
    generateMasterXlsx($config['columns'], $rows, $filename, [
        'mask_fields' => $config['mask_fields_on_download'] ?? [],
        'transform'   => $config['transform_on_download'] ?? null,
    ]);
}
