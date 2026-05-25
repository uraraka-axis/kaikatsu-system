<?php declare(strict_types=1);

/**
 * 快活システム - ユーザーマスタ ダウンロードAPI
 *
 * GET /api/export/master/users.php
 * → 現状の users テーブル全レコードを .xlsx で返却（password はマスク）
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('GET');

$config = [
    'table'     => 'users',
    'key_field' => 'login_id',
    'columns'   => [
        ['field' => 'login_id',           'header' => 'ログインID',             'type' => 'string'],
        ['field' => 'name',               'header' => 'ユーザー名',             'type' => 'string'],
        ['field' => 'role',               'header' => 'ロール',                 'type' => 'string'],
        ['field' => 'shop_code',          'header' => '所属店舗コード',         'type' => 'string'],
        ['field' => 'password',           'header' => 'パスワード',             'type' => 'string'],
        ['field' => 'zone_manager_email', 'header' => 'ゾーンマネージャーメアド', 'type' => 'string'],
        ['field' => 'area_manager_email', 'header' => 'エリアマネージャーメアド', 'type' => 'string'],
        ['field' => 'is_active',          'header' => '有効',                   'type' => 'bool'],
        ['field' => 'sort_order',         'header' => '表示順',                 'type' => 'int'],
        ['field' => 'apply_date',         'header' => '適用日',                 'type' => 'date_optional'],
    ],
    // DL時はpasswordをマスク（ハッシュ値を露出させない）
    'mask_fields_on_download' => ['password'],
];

try {
    handleMasterDownload($config);
} catch (Throwable $e) {
    error_log('users master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
