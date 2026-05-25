<?php declare(strict_types=1);

/**
 * 快活システム - 仕入先マスタ アップロードAPI
 *
 * POST /api/admin/master/suppliers.php          確定実行
 * POST /api/admin/master/suppliers.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=suppliers.xlsx
 * master-crud-spec.md 参照
 *
 * 注意: DBスキーマ上は id が PK で code は NULL 可・UNIQUE なしだが、
 *      Excel運用では code を必須・ユニーク扱いとしてマッチングキーに用いる（運用ルール）
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'suppliers',
    'key_field' => 'code',
    'label'     => '仕入先マスタ',
    'columns'   => [
        [
            'field'      => 'code',
            'header'     => '仕入先コード',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 20,
        ],
        [
            'field'      => 'name',
            'header'     => '仕入先名',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 100,
        ],
        [
            'field'      => 'contact',
            'header'     => '担当者',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 100,
        ],
        [
            'field'      => 'phone',
            'header'     => '電話番号',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 20,
        ],
        [
            'field'      => 'email',
            'header'     => 'メールアドレス',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 100,
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
    // code はDB制約上 UNIQUE ではないが、運用上ユニーク扱いとする（重複は警告）
    'unique_fields' => ['code'],
    // 削除時のFK参照チェック: 商品(products)から参照されている仕入先は削除不可
    'fk_checks_on_delete' => [
        ['table' => 'products', 'column' => 'supplier_id', 'label' => '商品', 'via_key' => 'id'],
    ],
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('suppliers master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
