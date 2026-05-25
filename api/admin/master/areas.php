<?php declare(strict_types=1);

/**
 * 快活システム - エリアマスタ アップロードAPI
 *
 * POST /api/admin/master/areas.php          確定実行
 * POST /api/admin/master/areas.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=areas.xlsx
 * master-crud-spec.md 参照
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'areas',
    'key_field' => 'code',
    'label'     => 'エリアマスタ',
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
            'header'     => 'エリア名',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 50,
        ],
        [
            'field'       => 'zone_code',
            'header'      => 'ゾーンコード',
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
        [
            // 予約更新用「適用日」列
            'field'    => 'apply_date',
            'header'   => '適用日',
            'type'     => 'date_optional',
            'required' => false,
        ],
    ],
    // アップロード時のFK参照チェック: zone_code は zones.code に存在しなければエラー
    'fk_checks_on_upload' => [
        ['field' => 'zone_code', 'ref_table' => 'zones', 'ref_column' => 'code'],
    ],
    // 削除時のFK参照チェック: 店舗(shops)に参照されているエリアは削除不可
    'fk_checks_on_delete' => [
        ['table' => 'shops', 'column' => 'area_code', 'label' => '店舗'],
    ],
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('areas master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
