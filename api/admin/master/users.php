<?php declare(strict_types=1);

/**
 * 快活システム - ユーザーマスタ アップロードAPI
 *
 * POST /api/admin/master/users.php          確定実行
 * POST /api/admin/master/users.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=users.xlsx
 * master-crud-spec.md §2-5 参照
 *
 * 特殊事情:
 *   - PK は id (auto_increment) だが、Excel運用ではマッチングキーに login_id を用いる
 *   - パスワード:
 *       * DL時: ******** にマスク
 *       * UL時: 空欄 or ******** なら現状維持、平文ならbcryptハッシュ化、新規ユーザーは必須
 *   - role=shop の時 shop_code 必須 / role=admin の時 shop_code は NULL 強制
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'users',
    'key_field' => 'login_id',
    'label'     => 'ユーザーマスタ',
    'columns'   => [
        [
            'field'      => 'login_id',
            'header'     => 'ログインID',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 50,
        ],
        [
            'field'      => 'name',
            'header'     => 'ユーザー名',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 50,
        ],
        [
            'field'       => 'role',
            'header'      => 'ロール',
            'type'        => 'string',
            'required'    => true,
            'pattern'     => '/^(shop|admin)$/',
            'pattern_msg' => 'shop または admin で指定してください',
        ],
        [
            // role=shop時必須、role=admin時NULL（preprocess_rowsで強制）
            'field'      => 'shop_code',
            'header'     => '所属店舗コード',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 5,
        ],
        [
            'field'      => 'password',
            'header'     => 'パスワード',
            'type'       => 'string',
            'required'   => false, // 既存ユーザーは空欄OK（現状維持）。新規必須は validate_extra で
            'max_length' => 255,
        ],
        [
            'field'      => 'zone_manager_email',
            'header'     => 'ゾーンマネージャーメアド',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 255,
        ],
        [
            'field'      => 'area_manager_email',
            'header'     => 'エリアマネージャーメアド',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 255,
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
            // password は transform で bcrypt 化済みで保存される設計
            'field'    => 'apply_date',
            'header'   => '適用日',
            'type'     => 'date_optional',
            'required' => false,
        ],
    ],
    // login_id はユニーク
    'unique_fields' => ['login_id'],
    // shop_code は shops.code に存在必須（NULL/空欄はスキップ）
    'fk_checks_on_upload' => [
        ['field' => 'shop_code', 'ref_table' => 'shops', 'ref_column' => 'code'],
    ],
    // 削除時のFK参照チェック: id 経由で4テーブル
    'fk_checks_on_delete' => [
        ['table' => 'master_change_log',         'column' => 'changed_by_id', 'label' => 'マスタ変更履歴',   'via_key' => 'id'],
        ['table' => 'master_scheduled_changes',  'column' => 'created_by_id', 'label' => 'マスタ予約変更',   'via_key' => 'id'],
        ['table' => 'orders',                    'column' => 'created_by',    'label' => '発注',             'via_key' => 'id'],
        ['table' => 'procurement_requests',      'column' => 'created_by',    'label' => '自店調達',         'via_key' => 'id'],
    ],
    // 空欄なら現状維持
    'skip_fields_if_blank' => ['password'],
    // 監査ログ書き込み時にマスク
    'mask_fields' => ['password'],
    // 前処理: マスク値の正規化、role別の shop_code 整形
    'preprocess_rows' => function(array $rows): array {
        foreach ($rows as &$row) {
            // DLマスク値 '********' は空欄扱い（既存維持→skip_fields_if_blankが効く）
            if (isset($row['password']) && $row['password'] === '********') {
                $row['password'] = '';
            }
            // role=admin の shop_code は強制的に NULL
            if (($row['role'] ?? null) === 'admin') {
                $row['shop_code'] = null;
            }
        }
        unset($row);
        return $rows;
    },
    // 追加バリデーション
    'validate_extra' => function(array $rows): array {
        $errors = [];
        // 新規ユーザー判定のため既存login_idを取得
        $existing = array_column(query("SELECT login_id FROM users"), 'login_id');

        foreach ($rows as $row) {
            $rowNum = $row['__row_num'] ?? 0;
            $loginId = $row['login_id'] ?? null;
            $role = $row['role'] ?? null;
            $shopCode = $row['shop_code'] ?? null;
            $password = $row['password'] ?? null;

            // role=shop は shop_code 必須
            if ($role === 'shop' && ($shopCode === null || $shopCode === '')) {
                $errors[] = [
                    'row'     => $rowNum,
                    'column'  => '所属店舗コード',
                    'value'   => '',
                    'message' => 'ロール=shop の場合は所属店舗コードが必須です',
                ];
            }
            // 新規ユーザーはパスワード必須（既存ユーザーは空欄OK = 既存維持）
            if ($loginId !== null && !in_array((string)$loginId, $existing, true)) {
                if ($password === null || $password === '') {
                    $errors[] = [
                        'row'     => $rowNum,
                        'column'  => 'パスワード',
                        'value'   => '',
                        'message' => '新規ユーザーはパスワード必須です',
                    ];
                }
            }
        }
        return $errors;
    },
    // 保存前のtransform: 平文パスワードをbcryptハッシュ化
    'transform' => function(array $row, string $op): array {
        if (!empty($row['password'])) {
            // bcrypt ハッシュ形式でなければハッシュ化（重複ハッシュ化を防ぐ）
            if (!preg_match('/^\$2[ayb]\$\d{2}\$/', (string)$row['password'])) {
                $row['password'] = password_hash($row['password'], PASSWORD_BCRYPT);
            }
        }
        return $row;
    },
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('users master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
