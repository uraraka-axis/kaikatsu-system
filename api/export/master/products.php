<?php declare(strict_types=1);

/**
 * 快活システム - 商品マスタ ダウンロードAPI
 *
 * GET /api/export/master/products.php
 * → 現状の products テーブル全レコードを .xlsx で返却
 *
 * 列構成は api/admin/master/products.php と同じ。supplier_id は JOIN で
 * supplier_name に変換して出力する。
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$columns = [
    ['field' => 'code',                  'header' => '商品コード',       'type' => 'string'],
    ['field' => 'name',                  'header' => '商品名',           'type' => 'string'],
    ['field' => 'category_code',         'header' => 'カテゴリコード',   'type' => 'string'],
    ['field' => 'supplier_name',         'header' => '仕入先名',         'type' => 'string'],
    ['field' => 'price',                 'header' => '単価',             'type' => 'int'],
    ['field' => 'jan_code',              'header' => 'JANコード',        'type' => 'string'],
    ['field' => 'supplier_product_code', 'header' => '仕入先商品コード', 'type' => 'string'],
    ['field' => 'recommended',           'header' => 'おすすめ',         'type' => 'bool'],
    ['field' => 'image_path',            'header' => '画像1',            'type' => 'string'],
    ['field' => 'image_path2',           'header' => '画像2',            'type' => 'string'],
    ['field' => 'image_path3',           'header' => '画像3',            'type' => 'string'],
    ['field' => 'is_active',             'header' => '有効',             'type' => 'bool'],
    ['field' => 'sort_order',            'header' => '表示順',           'type' => 'int'],
];

// products + suppliers を JOIN して取得（仕入先名を含める）
$rows = query(
    'SELECT p.code, p.name, p.category_code, s.name AS supplier_name,
            p.price, p.jan_code, p.supplier_product_code,
            p.recommended, p.image_path, p.image_path2, p.image_path3,
            p.is_active, p.sort_order
       FROM products p
       LEFT JOIN suppliers s ON p.supplier_id = s.id
       ORDER BY p.sort_order, p.code'
);

// handleMasterDownload は SELECT * を使うため、独自に generateMasterXlsx を呼ぶ
$filename = sprintf('products_master_%s.xlsx', date('Ymd'));

try {
    generateMasterXlsx($columns, $rows, $filename);
} catch (Throwable $e) {
    error_log('products master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
