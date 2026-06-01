<?php declare(strict_types=1);

/**
 * 快活システム - 店舗マスタ アップロードAPI
 *
 * POST /api/admin/master/shops.php          確定実行
 * POST /api/admin/master/shops.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=shops.xlsx
 * master-crud-spec.md 参照
 *
 * 2026-05-23: カテゴリ別 TRUE/FALSE 列に対応し、UL確定時に shop_categories を差分同期する。
 *   - DL/UL ともに active な categories の数だけ末尾に列が並ぶ（カテゴリ拡張時は自動で列増減）
 *   - after_apply フックで shop_categories を INSERT/DELETE
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

// アクティブなカテゴリ
$categories = query(
    'SELECT code, name FROM categories WHERE is_active = 1 ORDER BY sort_order, code'
);

// 固定カラム
$columns = [
    [
        'field'       => 'code',
        'header'      => 'コード',
        'type'        => 'string',
        'required'    => true,
        'max_length'  => 5,
        'pattern'     => '/^\d{5}$/',
        'pattern_msg' => '5桁の数字で指定してください',
    ],
    [
        'field'      => 'name',
        'header'     => '店舗名',
        'type'       => 'string',
        'required'   => true,
        'max_length' => 50,
    ],
    [
        'field'       => 'short_code',
        'header'      => '短縮コード',
        'type'        => 'string',
        'required'    => true,
        'max_length'  => 5,
        'pattern'     => '/^[A-Za-z0-9]{1,5}$/',
        'pattern_msg' => '5桁以内の半角英数字で指定してください',
    ],
    [
        'field'       => 'area_code',
        'header'      => 'エリアコード',
        'type'        => 'string',
        'required'    => true,
        'max_length'  => 3,
        'pattern'     => '/^\d{3}$/',
        'pattern_msg' => '3桁の数字で指定してください',
    ],
    [
        'field'    => 'is_active',
        'header'   => '有効',
        'type'     => 'bool',
        'required' => true,
    ],
    [
        'field'    => 'sort_order',
        'header'   => '表示順',
        'type'     => 'int',
        'required' => true,
        'min'      => 0,
    ],
];

// カテゴリ別 bool 列（動的）
foreach ($categories as $cat) {
    $columns[] = [
        'field'    => 'cat_' . $cat['code'],
        'header'   => $cat['name'],
        'type'     => 'bool',
        'required' => true,
    ];
}

// 予約更新用「適用日」列（全マスタ共通: 一番右に配置）
// 注意: shops の場合カテゴリ追加時に位置が動く（ヘッダ名照合のため動作影響なし）
$columns[] = [
    'field'    => 'apply_date',
    'header'   => '適用日',
    'type'     => 'date_optional',
    'required' => false,
];

// カテゴリコード一覧（after_apply で使う）
$catCodes = array_column($categories, 'code');

// shops テーブルに存在する実カラム（cat_* は中間テーブル shop_categories 用なので除外）
$shopsTableFields = ['code', 'name', 'short_code', 'area_code', 'is_active', 'sort_order'];

$config = [
    'table'     => 'shops',
    'key_field' => 'code',
    'label'     => '店舗マスタ',
    'columns'   => $columns,
    // cat_* は shops テーブルのカラムではないため、差分検出 & DB書込み から除外する
    'compare_fields' => $shopsTableFields,
    'transform' => function (array $row) use ($shopsTableFields) {
        $out = [];
        foreach ($shopsTableFields as $f) {
            if (array_key_exists($f, $row)) $out[$f] = $row[$f];
        }
        return $out;
    },
    'fk_checks_on_upload' => [
        ['field' => 'area_code', 'ref_table' => 'areas', 'ref_column' => 'code'],
    ],
    'unique_fields' => ['short_code'],
    // 削除可否チェック対象: 実データの参照のみ（予算/発注/自店調達/ユーザー）。
    // shop_categories は店舗マスタ自身が管理する所有子テーブルで、DB側も
    // ON DELETE CASCADE のため、店舗削除に伴い自動削除される。ここでブロックすると
    // カテゴリを持つ店舗が一切削除できなくなるため対象から除外する。
    'fk_checks_on_delete' => [
        ['table' => 'budgets',              'column' => 'shop_code', 'label' => '予算'],
        ['table' => 'orders',               'column' => 'shop_code', 'label' => '発注'],
        ['table' => 'procurement_requests', 'column' => 'shop_code', 'label' => '自店調達'],
        ['table' => 'users',                'column' => 'shop_code', 'label' => 'ユーザー'],
    ],
    // dry_run 時の差分計算（プレビューの「変更0件」誤表示を回避）
    // ※ 渡される rows は即時反映行のみ（予約行は除外済み）
    'compute_extra_diff' => function (array $rows, array $diff) use ($catCodes) {
        return computeShopCategoriesDiff($rows, $catCodes);
    },
    // 適用後フック: shop_categories を差分同期する（即時反映行のみ）
    'after_apply' => function (array $rows, string $batchId, ?int $userId, string $filename, array $diff, ?array $extraDiff) use ($catCodes) {
        $catDiff = $extraDiff ?? computeShopCategoriesDiff($rows, $catCodes);
        applyShopCategoriesDiff($catDiff, $batchId, $userId, $filename);
    },
    // 予約反映分の shop_categories 差分を計算（フェーズ2A 追加）
    // $scheduledRows: splitDiffByApplyDate の scheduled[]、$rowsByKey: validated rows を key_field で索引化
    'compute_scheduled_extras' => function (array $scheduledRows, array $rowsByKey) use ($catCodes) {
        return computeShopCategoriesScheduledExtras($scheduledRows, $rowsByKey, $catCodes);
    },
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('shops master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}

/**
 * Excel rows と現状の shop_categories を比較して INSERT/DELETE 候補を返す。
 * dry_run 時のプレビューと、確定時の適用 の両方で使う。
 *
 * @return array{insert: array<int,array{shop_code:string,category_code:string}>,
 *               delete: array<int,array{shop_code:string,category_code:string}>}
 */
function computeShopCategoriesDiff(array $rows, array $catCodes): array
{
    if (empty($rows) || empty($catCodes)) {
        return ['insert' => [], 'delete' => []];
    }

    $shopCodes = array_values(array_unique(array_map(fn($r) => (string)$r['code'], $rows)));
    if (empty($shopCodes)) {
        return ['insert' => [], 'delete' => []];
    }

    // 現状取得: アップロード対象店舗の shop_categories
    $placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
    $params = [];
    foreach ($shopCodes as $i => $sc) $params[':sc' . $i] = $sc;

    $existingRows = query(
        "SELECT shop_code, category_code FROM shop_categories
          WHERE shop_code IN ({$placeholders})",
        $params
    );
    $existing = []; // [shop][cat] => true
    foreach ($existingRows as $r) {
        $existing[$r['shop_code']][$r['category_code']] = true;
    }

    $inserts = [];
    $deletes = [];

    foreach ($rows as $row) {
        $code = (string)$row['code'];
        foreach ($catCodes as $catCode) {
            $field = 'cat_' . $catCode;
            $wantOn = isset($row[$field]) && (int)$row[$field] === 1;
            $hasNow = !empty($existing[$code][$catCode]);
            if ($wantOn && !$hasNow) {
                $inserts[] = ['shop_code' => $code, 'category_code' => $catCode];
            } elseif (!$wantOn && $hasNow) {
                $deletes[] = ['shop_code' => $code, 'category_code' => $catCode];
            }
        }
    }

    return ['insert' => $inserts, 'delete' => $deletes];
}

/**
 * 予約行の shop_categories 差分を「予約エントリ配列」として返す。
 *
 * 各 scheduled エントリの key (shop_code) に対して、その行で指定された cat_* フラグと
 * 現状の shop_categories を比較し、INSERT/DELETE 用の予約エントリを生成。
 *
 * 戻り値の各エントリは insertScheduledChanges() がそのまま受け取れる形式:
 *   ['target_table' => 'shop_categories', 'operation' => 'insert'|'delete',
 *    'key' => '10501/fitness', 'after' or 'before' => [...], 'apply_date' => DateTimeImmutable, ...]
 */
function computeShopCategoriesScheduledExtras(array $scheduledRows, array $rowsByKey, array $catCodes): array
{
    if (empty($scheduledRows) || empty($catCodes)) return [];

    // 対象店舗コード
    $shopCodes = [];
    foreach ($scheduledRows as $s) {
        $shopCodes[(string)$s['key']] = true;
    }
    $shopCodeList = array_keys($shopCodes);
    if (empty($shopCodeList)) return [];

    // 現状の shop_categories
    $placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodeList)));
    $params = [];
    foreach ($shopCodeList as $i => $sc) {
        $params[':sc' . $i] = $sc;
    }
    $existingRows = query(
        "SELECT shop_code, category_code FROM shop_categories
          WHERE shop_code IN ({$placeholders})",
        $params
    );
    $existing = [];
    foreach ($existingRows as $r) {
        $existing[$r['shop_code']][$r['category_code']] = true;
    }

    $extras = [];
    foreach ($scheduledRows as $s) {
        $shopCode = (string)$s['key'];
        $applyDate = $s['apply_date'];
        $row = $rowsByKey[$shopCode] ?? null;
        if (!$row) continue;
        foreach ($catCodes as $catCode) {
            $wantOn = isset($row['cat_' . $catCode]) && (int)$row['cat_' . $catCode] === 1;
            $hasNow = !empty($existing[$shopCode][$catCode]);
            if ($wantOn && !$hasNow) {
                $extras[] = [
                    'target_table'   => 'shop_categories',
                    'operation'      => 'insert',
                    'key'            => $shopCode . '/' . $catCode,
                    'after'          => ['shop_code' => $shopCode, 'category_code' => $catCode],
                    'changed_fields' => ['shop_code', 'category_code'],
                    'apply_date'     => $applyDate,
                ];
            } elseif (!$wantOn && $hasNow) {
                $extras[] = [
                    'target_table'   => 'shop_categories',
                    'operation'      => 'delete',
                    'key'            => $shopCode . '/' . $catCode,
                    'before'         => ['shop_code' => $shopCode, 'category_code' => $catCode],
                    'changed_fields' => [],
                    'apply_date'     => $applyDate,
                ];
            }
        }
    }
    return $extras;
}

/**
 * 計算済みの shop_categories 差分を実DBに適用する。確定時のみ呼ぶ。
 */
function applyShopCategoriesDiff(array $catDiff, string $batchId, ?int $userId, string $filename): void
{
    $inserts = $catDiff['insert'] ?? [];
    $deletes = $catDiff['delete'] ?? [];
    if (empty($inserts) && empty($deletes)) return;

    beginTransaction();
    try {
        foreach ($inserts as $ins) {
            execute(
                'INSERT INTO shop_categories (shop_code, category_code)
                 VALUES (:sc, :cc)',
                [':sc' => $ins['shop_code'], ':cc' => $ins['category_code']]
            );
            logMasterChange(
                'shop_categories',
                'insert',
                $ins['shop_code'] . '/' . $ins['category_code'],
                ['after' => $ins],
                $userId,
                $filename,
                $batchId
            );
        }
        foreach ($deletes as $del) {
            execute(
                'DELETE FROM shop_categories
                  WHERE shop_code = :sc AND category_code = :cc',
                [':sc' => $del['shop_code'], ':cc' => $del['category_code']]
            );
            logMasterChange(
                'shop_categories',
                'delete',
                $del['shop_code'] . '/' . $del['category_code'],
                ['before' => $del],
                $userId,
                $filename,
                $batchId
            );
        }
        commit();
    } catch (Throwable $e) {
        rollback();
        throw $e;
    }
}
