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
        'max_length'  => 3,
        'pattern'     => '/^[A-Za-z0-9]{1,3}$/',
        'pattern_msg' => '3桁以内の半角英数字で指定してください',
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
    'fk_checks_on_delete' => [
        ['table' => 'budgets',              'column' => 'shop_code', 'label' => '予算'],
        ['table' => 'orders',               'column' => 'shop_code', 'label' => '発注'],
        ['table' => 'procurement_requests', 'column' => 'shop_code', 'label' => '自店調達'],
        ['table' => 'shop_categories',      'column' => 'shop_code', 'label' => '店舗カテゴリ'],
        ['table' => 'users',                'column' => 'shop_code', 'label' => 'ユーザー'],
    ],
    // dry_run 時の差分計算（プレビューの「変更0件」誤表示を回避）
    'compute_extra_diff' => function (array $rows, array $diff) use ($catCodes) {
        return computeShopCategoriesDiff($rows, $catCodes);
    },
    // 適用後フック: shop_categories を差分同期する
    'after_apply' => function (array $rows, string $batchId, ?int $userId, string $filename, array $diff, ?array $extraDiff) use ($catCodes) {
        // dry_run 用に既に extraDiff が計算済みなら再利用、なければ計算
        $catDiff = $extraDiff ?? computeShopCategoriesDiff($rows, $catCodes);
        applyShopCategoriesDiff($catDiff, $batchId, $userId, $filename);
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
