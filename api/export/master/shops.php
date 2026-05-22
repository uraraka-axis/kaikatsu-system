<?php declare(strict_types=1);

/**
 * 快活システム - 店舗マスタ ダウンロードAPI
 *
 * GET /api/export/master/shops.php
 * → 現状の shops テーブル全レコードを .xlsx で返却
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$config = [
    'table'     => 'shops',
    'key_field' => 'code',
    'columns'   => [
        ['field' => 'code',       'header' => 'コード',       'type' => 'string'],
        ['field' => 'name',       'header' => '店舗名',       'type' => 'string'],
        ['field' => 'short_code', 'header' => '短縮コード',   'type' => 'string'],
        ['field' => 'area_code',  'header' => 'エリアコード', 'type' => 'string'],
        ['field' => 'is_active',  'header' => '有効',         'type' => 'bool'],
        ['field' => 'sort_order', 'header' => '表示順',       'type' => 'int'],
    ],
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('shops master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
