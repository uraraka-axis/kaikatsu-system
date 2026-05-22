<?php declare(strict_types=1);

/**
 * 快活システム - 仕入先マスタ ダウンロードAPI
 *
 * GET /api/export/master/suppliers.php
 * → 現状の suppliers テーブル全レコードを .xlsx で返却
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$config = [
    'table'     => 'suppliers',
    'key_field' => 'code',
    'columns'   => [
        ['field' => 'code',       'header' => '仕入先コード',   'type' => 'string'],
        ['field' => 'name',       'header' => '仕入先名',       'type' => 'string'],
        ['field' => 'contact',    'header' => '担当者',         'type' => 'string'],
        ['field' => 'phone',      'header' => '電話番号',       'type' => 'string'],
        ['field' => 'email',      'header' => 'メールアドレス', 'type' => 'string'],
        ['field' => 'is_active',  'header' => '有効',           'type' => 'bool'],
        ['field' => 'sort_order', 'header' => '表示順',         'type' => 'int'],
    ],
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('suppliers master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
