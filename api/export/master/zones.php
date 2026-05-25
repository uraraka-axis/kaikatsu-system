<?php declare(strict_types=1);

/**
 * 快活システム - ゾーンマスタ ダウンロードAPI
 *
 * GET /api/export/master/zones.php
 * → 現状の zones テーブル全レコードを .xlsx で返却
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$config = [
    'table'     => 'zones',
    'key_field' => 'code',
    'columns'   => [
        ['field' => 'code',       'header' => 'コード',   'type' => 'string'],
        ['field' => 'name',       'header' => 'ゾーン名', 'type' => 'string'],
        ['field' => 'is_active',  'header' => '有効',     'type' => 'bool'],
        ['field' => 'sort_order', 'header' => '表示順',   'type' => 'int'],
        // 予約更新用「適用日」列（DL時は常に空欄）
        ['field' => 'apply_date', 'header' => '適用日',   'type' => 'date_optional'],
    ],
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('zones master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
