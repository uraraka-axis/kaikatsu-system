<?php declare(strict_types=1);

/**
 * 快活システム - 店舗マスタ アップロードAPI
 *
 * POST /api/admin/master/shops.php          確定実行
 * POST /api/admin/master/shops.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=shops.xlsx
 * master-crud-spec.md 参照
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'shops',
    'key_field' => 'code',
    'label'     => '店舗マスタ',
    'columns'   => [
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
    ],
    // アップロード時のFK参照チェック: area_code は areas.code に存在しなければエラー
    'fk_checks_on_upload' => [
        ['field' => 'area_code', 'ref_table' => 'areas', 'ref_column' => 'code'],
    ],
    // ユニーク制約: short_code はDB全体で一意
    'unique_fields' => ['short_code'],
    // 削除時のFK参照チェック: shops を参照する5テーブル
    'fk_checks_on_delete' => [
        ['table' => 'budgets',              'column' => 'shop_code', 'label' => '予算'],
        ['table' => 'orders',               'column' => 'shop_code', 'label' => '発注'],
        ['table' => 'procurement_requests', 'column' => 'shop_code', 'label' => '自店調達'],
        ['table' => 'shop_categories',      'column' => 'shop_code', 'label' => '店舗カテゴリ'],
        ['table' => 'users',                'column' => 'shop_code', 'label' => 'ユーザー'],
    ],
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('shops master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
