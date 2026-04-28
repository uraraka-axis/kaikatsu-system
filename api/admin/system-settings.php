<?php declare(strict_types=1);

/**
 * 快活システム - システム設定API（管理画面用）
 *
 * GET  /api/admin/system-settings.php
 *   - fiscal_start_month を返す
 * POST /api/admin/system-settings.php
 *   - body: { fiscal_start_month: int(1-12), categories: [{code, name, closing_type, closing_day, original_code}, ...] }
 *   - fiscal_start_month の更新
 *   - categories の差分適用（追加・更新・削除）
 *   - 削除はデータが残っているコードについては不可
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAdmin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    jsonError('Method Not Allowed', 405);
}

function handleGet(): void
{
    $row = getOne("SELECT value FROM system_settings WHERE `key` = 'fiscal_start_month'");
    $month = $row ? (int)$row['value'] : 4;

    jsonResponse([
        'success' => true,
        'data'    => [
            'fiscal_start_month' => $month,
        ],
    ]);
}

function handlePost(): void
{
    $body = getJsonInput();

    $fiscalStartMonth = filter_var(
        $body['fiscal_start_month'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 12]]
    );
    if ($fiscalStartMonth === false) {
        jsonError('期の開始月は1〜12で指定してください', 400);
    }

    $categories = $body['categories'] ?? null;
    if (!is_array($categories) || count($categories) === 0) {
        jsonError('カテゴリは1件以上必要です', 400);
    }

    $normalized = [];
    $seenCodes = [];
    foreach ($categories as $i => $cat) {
        if (!is_array($cat)) {
            jsonError("カテゴリ#{$i}の形式が不正です", 400);
        }
        $code = trim((string)($cat['code'] ?? ''));
        $name = trim((string)($cat['name'] ?? ''));
        $type = (string)($cat['closing_type'] ?? 'none');
        $day  = (int)($cat['closing_day'] ?? 0);
        $originalCode = trim((string)($cat['original_code'] ?? ''));

        if ($code === '' || $name === '') {
            jsonError("カテゴリ#{$i}: コードと名前は必須です", 400);
        }
        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            jsonError("カテゴリコード '{$code}' は英小文字・数字・_ のみ使用可", 400);
        }
        if (mb_strlen($code) > 20 || mb_strlen($name) > 50) {
            jsonError("カテゴリ#{$i}: 文字数超過", 400);
        }
        if (!in_array($type, ['none', 'monthly', 'weekly'], true)) {
            jsonError("カテゴリ '{$name}': 締めタイプが不正です", 400);
        }
        if ($type === 'monthly' && ($day < 1 || $day > 31)) {
            jsonError("カテゴリ '{$name}': 締め日は1〜31で指定してください", 400);
        }
        if ($type === 'weekly' && ($day < 0 || $day > 6)) {
            jsonError("カテゴリ '{$name}': 締め曜日が不正です", 400);
        }
        if ($type === 'none') {
            $day = 0;
        }
        if (isset($seenCodes[$code])) {
            jsonError("カテゴリコード '{$code}' が重複しています", 400);
        }
        $seenCodes[$code] = true;

        $normalized[] = [
            'code'          => $code,
            'name'          => $name,
            'closing_type'  => $type,
            'closing_day'   => $day,
            'original_code' => $originalCode,
            'sort_order'    => $i + 1,
        ];
    }

    $existingRows = query('SELECT code FROM categories');
    $existingCodes = array_column($existingRows, 'code');

    $submittedCodes = array_column($normalized, 'code');
    $codesToDelete = array_diff($existingCodes, $submittedCodes);

    // FK制約があるテーブルで使用中なら削除不可
    foreach ($codesToDelete as $delCode) {
        $checks = [
            'orders'                => '発注',
            'products'              => '商品マスタ',
            'procurement_requests'  => '自店調達申請',
            'shop_categories'       => '店舗別カテゴリ',
        ];
        foreach ($checks as $table => $label) {
            $row = getOne(
                "SELECT COUNT(*) AS c FROM {$table} WHERE category_code = :c",
                [':c' => $delCode]
            );
            if ((int)$row['c'] > 0) {
                jsonError("カテゴリ '{$delCode}' は{$label}で使用中のため削除できません", 400);
            }
        }
    }

    try {
        beginTransaction();

        execute(
            "INSERT INTO system_settings (`key`, value, description)
             VALUES ('fiscal_start_month', :v, '期の開始月（1-12）')
             ON DUPLICATE KEY UPDATE value = :v2",
            [':v' => (string)$fiscalStartMonth, ':v2' => (string)$fiscalStartMonth]
        );

        foreach ($codesToDelete as $delCode) {
            execute('DELETE FROM categories WHERE code = :c', [':c' => $delCode]);
        }

        foreach ($normalized as $row) {
            execute(
                'INSERT INTO categories (code, name, closing_type, closing_day, sort_order, is_active)
                 VALUES (:code, :name, :type, :day, :sort, 1)
                 ON DUPLICATE KEY UPDATE
                   name = VALUES(name),
                   closing_type = VALUES(closing_type),
                   closing_day = VALUES(closing_day),
                   sort_order = VALUES(sort_order),
                   is_active = 1',
                [
                    ':code' => $row['code'],
                    ':name' => $row['name'],
                    ':type' => $row['closing_type'],
                    ':day'  => $row['closing_day'],
                    ':sort' => $row['sort_order'],
                ]
            );
        }

        commit();
    } catch (Throwable $e) {
        rollback();
        error_log('system-settings save error: ' . $e->getMessage());
        jsonError('保存処理でエラーが発生しました', 500);
    }

    jsonResponse([
        'success' => true,
        'data'    => ['saved' => true],
    ]);
}
