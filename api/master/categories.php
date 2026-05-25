<?php declare(strict_types=1);

/**
 * 快活システム - カテゴリ一覧API
 *
 * GET /api/master/categories.php
 *   - 共通: is_active=1 のカテゴリを返す
 *   - admin: 全カテゴリ
 *   - 店舗ユーザー: 自店が shop_categories に持つカテゴリのみ
 *
 * 2026-05-23: 店舗ユーザーは自店カテゴリで絞り込むよう変更（カテゴリ拡張対応）
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$user = getCurrentUser();

if (in_array($user['role'], ['admin', 'system'], true)) {
    // 管理者 / システム管理者: 全アクティブカテゴリ
    $categories = query(
        'SELECT code, name, closing_type, closing_day
           FROM categories
          WHERE is_active = 1
          ORDER BY sort_order, code'
    );
} else {
    // 店舗ユーザー: 自店が持つカテゴリのみ（shop_categories から）
    $shopCode = $user['shop_code'] ?? null;
    if ($shopCode === null) {
        jsonResponse(['success' => true, 'data' => []]);
    }
    $categories = query(
        'SELECT c.code, c.name, c.closing_type, c.closing_day
           FROM categories c
           JOIN shop_categories sc ON sc.category_code = c.code
          WHERE c.is_active = 1 AND sc.shop_code = :sc
          ORDER BY c.sort_order, c.code',
        [':sc' => $shopCode]
    );
}

foreach ($categories as &$c) {
    $c['closing_day'] = (int)$c['closing_day'];
}
unset($c);

jsonResponse([
    'success' => true,
    'data'    => $categories,
]);
