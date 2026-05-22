<?php declare(strict_types=1);

/**
 * 快活システム - ゾーンマスタ アップロードAPI
 *
 * POST /api/admin/master/zones.php          確定実行
 * POST /api/admin/master/zones.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=zones.xlsx
 * master-crud-spec.md 参照
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'zones',
    'key_field' => 'code',
    'label'     => 'ゾーンマスタ',
    'columns'   => [
        [
            'field'       => 'code',
            'header'      => 'コード',
            'type'        => 'string',
            'required'    => true,
            'max_length'  => 3,
            'pattern'     => '/^\d{3}$/',
            'pattern_msg' => '3桁の数字で指定してください',
        ],
        [
            'field'      => 'name',
            'header'     => 'ゾーン名',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 50,
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
    ],
    'fk_checks_on_delete' => [
        ['table' => 'areas', 'column' => 'zone_code', 'label' => 'エリア'],
    ],
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('zones master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
