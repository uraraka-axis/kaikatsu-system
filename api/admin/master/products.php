<?php declare(strict_types=1);

/**
 * 快活システム - 商品マスタ アップロードAPI
 *
 * POST /api/admin/master/products.php          確定実行
 * POST /api/admin/master/products.php?dry_run=1 プレビュー
 *
 * リクエスト: multipart/form-data, file=products.xlsx
 *
 * 仕様:
 *   - PK: id (auto_increment) / 運用上のマッチングキー: code (UNIQUE)
 *   - 仕入先は Excel に「仕入先名」を書く → 内部で supplier_id にルックアップ変換
 *     - 空欄なら supplier_id = NULL（仕入先未設定）
 *     - 該当 supplier_name が複数件ヒットしたらエラー（特定不可）
 *     - 該当 supplier_name が見つからなければエラー
 *   - 画像は image_path 列にファイル名のみ記載（実体は別途 uploads/products/ に FTP 等で配置）
 *   - 削除時 FK チェック: order_equipment_items から参照されている商品は削除不可
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

requireAdmin();
requireMethod('POST');

$config = [
    'table'     => 'products',
    'key_field' => 'code',
    'label'     => '商品マスタ',
    'columns'   => [
        [
            'field'       => 'code',
            'header'      => '商品コード',
            'type'        => 'string',
            'required'    => true,
            'max_length'  => 20,
            'pattern'     => '/^[A-Za-z0-9_\-]+$/',
            'pattern_msg' => '半角英数字・ハイフン・アンスコのみ使用できます',
        ],
        [
            'field'      => 'name',
            'header'     => '商品名',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 100,
        ],
        [
            'field'      => 'category_code',
            'header'     => 'カテゴリコード',
            'type'       => 'string',
            'required'   => true,
            'max_length' => 20,
        ],
        [
            'field'      => 'supplier_name',
            'header'     => '仕入先名',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 100,
        ],
        [
            'field'    => 'price',
            'header'   => '単価',
            'type'     => 'int',
            'required' => true,
            'min'      => 0,
        ],
        [
            'field'       => 'jan_code',
            'header'      => 'JANコード',
            'type'        => 'string',
            'required'    => false,
            'max_length'  => 13,
            'pattern'     => '/^(\d{8}|\d{13})$/',
            'pattern_msg' => '8桁または13桁の数字で指定してください',
        ],
        [
            'field'      => 'supplier_product_code',
            'header'     => '仕入先商品コード',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 50,
        ],
        [
            'field'    => 'recommended',
            'header'   => 'おすすめ',
            'type'     => 'bool',
            'required' => true,
        ],
        [
            'field'      => 'image_path',
            'header'     => '画像1',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 255,
        ],
        [
            'field'      => 'image_path2',
            'header'     => '画像2',
            'type'       => 'string',
            'required'   => false,
            'max_length' => 255,
        ],
        [
            'field'      => 'image_path3',
            'header'     => '画像3',
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
            'field'    => 'apply_date',
            'header'   => '適用日',
            'type'     => 'date_optional',
            'required' => false,
        ],
    ],
    // code は UNIQUE
    'unique_fields' => ['code'],
    // category_code は categories.code に存在必須
    'fk_checks_on_upload' => [
        ['field' => 'category_code', 'ref_table' => 'categories', 'ref_column' => 'code'],
    ],
    // 削除時の FK 参照: 備品発注明細から参照されていれば削除不可
    'fk_checks_on_delete' => [
        ['table' => 'order_equipment_items', 'column' => 'product_id', 'label' => '備品発注明細', 'via_key' => 'id'],
    ],
    // 比較対象は実テーブルのカラムのみ（supplier_name は Excel 表示用なので除外）
    'compare_fields' => [
        'code', 'name', 'category_code', 'supplier_id', 'price',
        'jan_code', 'supplier_product_code', 'recommended',
        'image_path', 'image_path2', 'image_path3', 'is_active', 'sort_order',
    ],
    // 前処理: 仕入先名 → supplier_id にルックアップ。Excel 列の supplier_name は最終的に削除する
    'preprocess_rows' => function (array $rows): array {
        // 仕入先名 → ids[] のマップ（同名複数判定用）
        $supplierRows = query('SELECT id, name FROM suppliers');
        $nameToIds = [];
        foreach ($supplierRows as $s) {
            $nameToIds[$s['name']][] = (int)$s['id'];
        }
        foreach ($rows as &$r) {
            $name = $r['supplier_name'] ?? null;
            if ($name === null || $name === '') {
                $r['supplier_id'] = null;
            } elseif (isset($nameToIds[$name]) && count($nameToIds[$name]) === 1) {
                $r['supplier_id'] = $nameToIds[$name][0];
            } else {
                // 重複 or 不在: validate_extra でエラー化するため一旦 null
                $r['supplier_id'] = null;
            }
        }
        unset($r);
        return $rows;
    },
    // 追加バリデーション: 仕入先名の重複・未登録チェック
    'validate_extra' => function (array $rows): array {
        $supplierRows = query('SELECT id, name FROM suppliers');
        $nameToIds = [];
        foreach ($supplierRows as $s) {
            $nameToIds[$s['name']][] = (int)$s['id'];
        }
        $errors = [];
        foreach ($rows as $row) {
            $name = $row['supplier_name'] ?? null;
            if ($name === null || $name === '') continue; // 空欄は OK
            $rowNum = $row['__row_num'] ?? 0;
            if (!isset($nameToIds[$name])) {
                $errors[] = [
                    'row'     => $rowNum,
                    'column'  => '仕入先名',
                    'value'   => (string)$name,
                    'message' => '仕入先マスタに存在しません',
                ];
            } elseif (count($nameToIds[$name]) > 1) {
                $errors[] = [
                    'row'     => $rowNum,
                    'column'  => '仕入先名',
                    'value'   => (string)$name,
                    'message' => '仕入先マスタに同名が複数件あるため特定できません',
                ];
            }
        }
        return $errors;
    },
    // 保存前 transform: supplier_name は DB カラムにないため削除
    'transform' => function (array $row, string $op): array {
        unset($row['supplier_name']);
        return $row;
    },
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

try {
    $result = handleMasterUpload($config, $dryRun);
    jsonResponse($result, $result['success'] ? 200 : 400);
} catch (Throwable $e) {
    error_log('products master upload error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました: ' . $e->getMessage(), 500);
}
