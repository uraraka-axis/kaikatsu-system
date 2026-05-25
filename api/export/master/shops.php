<?php declare(strict_types=1);

/**
 * 快活システム - 店舗マスタ ダウンロードAPI
 *
 * GET /api/export/master/shops.php
 * → 現状の shops テーブル全レコードを .xlsx で返却
 *
 * 2026-05-23: カテゴリ別TRUE/FALSE列を動的追加。
 *   - active な categories マスタを参照して、カテゴリ名の列を末尾に自動付与
 *   - 各セルは shop_categories の有無で TRUE / FALSE
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

// 1) アクティブなカテゴリ一覧（sort_order順）
$categories = query(
    'SELECT code, name FROM categories WHERE is_active = 1 ORDER BY sort_order, code'
);

// 2) shop_categories のマップ: [shop_code => [cat_code => true, ...]]
$shopCatRows = query('SELECT shop_code, category_code FROM shop_categories');
$shopCatMap = [];
foreach ($shopCatRows as $r) {
    $shopCatMap[$r['shop_code']][$r['category_code']] = true;
}

// 3) 動的カラム構成（固定6列 + カテゴリ別bool列）
$columns = [
    ['field' => 'code',       'header' => 'コード',       'type' => 'string'],
    ['field' => 'name',       'header' => '店舗名',       'type' => 'string'],
    ['field' => 'short_code', 'header' => '短縮コード',   'type' => 'string'],
    ['field' => 'area_code',  'header' => 'エリアコード', 'type' => 'string'],
    ['field' => 'is_active',  'header' => '有効',         'type' => 'bool'],
    ['field' => 'sort_order', 'header' => '表示順',       'type' => 'int'],
];
foreach ($categories as $cat) {
    $columns[] = [
        'field'  => 'cat_' . $cat['code'],
        'header' => $cat['name'],
        'type'   => 'bool',
    ];
}
// 予約更新用「適用日」列（全マスタ共通: 一番右に配置）
$columns[] = ['field' => 'apply_date', 'header' => '適用日', 'type' => 'date_optional'];

$config = [
    'table'     => 'shops',
    'key_field' => 'code',
    'columns'   => $columns,
    // 各 shops 行に対し、shop_categories から cat_* フィールドを埋める
    'transform_on_download' => function (array $row) use ($categories, $shopCatMap) {
        $code = $row['code'];
        foreach ($categories as $cat) {
            $row['cat_' . $cat['code']] = !empty($shopCatMap[$code][$cat['code']]);
        }
        return $row;
    },
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('shops master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
