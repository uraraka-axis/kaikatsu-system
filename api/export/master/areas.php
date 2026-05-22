<?php declare(strict_types=1);

/**
 * 快活システム - エリアマスタ ダウンロードAPI
 *
 * GET /api/export/master/areas.php
 * → 現状の areas テーブル全レコードを .xlsx で返却
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$config = [
    'table'     => 'areas',
    'key_field' => 'code',
    'columns'   => [
        ['field' => 'code',       'header' => 'コード',       'type' => 'string'],
        ['field' => 'name',       'header' => 'エリア名',     'type' => 'string'],
        ['field' => 'zone_code',  'header' => 'ゾーンコード', 'type' => 'string'],
        ['field' => 'is_active',  'header' => '有効',         'type' => 'bool'],
        ['field' => 'sort_order', 'header' => '表示順',       'type' => 'int'],
    ],
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('areas master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
