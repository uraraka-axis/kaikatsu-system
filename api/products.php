<?php declare(strict_types=1);

/**
 * 快活システム - 商品一覧API
 *
 * GET /api/products.php?category=fitness&search=マット
 *
 * 2026-05-23: 店舗ユーザーは shop_categories で自店カテゴリの商品のみ返す。
 *             admin は従来通り全件。
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
requireMethod('GET');

$user = getCurrentUser();
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$chair = ($_GET['chair'] ?? '') === '1'; // チェア備品発注画面用

$sql = 'SELECT p.id, p.name, p.code, p.price, p.category_code AS category,
               p.recommended, p.image_path, p.image_path2, p.image_path3,
               p.description,
               s.name AS supplier
        FROM products p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.is_active = 1';
$params = [];

// チェア備品の切り分け:
//   chair=1 … チェア備品発注画面（is_chair_item=1 のみ）
//   それ以外 … 通常の備品発注画面（チェア備品は表示しない）
$sql .= $chair ? ' AND p.is_chair_item = 1' : ' AND p.is_chair_item = 0';

// 店舗ユーザー: 自店カテゴリの商品のみ
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'] ?? null;
    if ($shopCode === null) {
        jsonResponse(['success' => true, 'data' => []]);
    }
    $sql .= ' AND p.category_code IN (
                SELECT category_code FROM shop_categories WHERE shop_code = :user_shop
              )';
    $params[':user_shop'] = $shopCode;
}

if ($category !== '') {
    $sql .= ' AND p.category_code = :category';
    $params[':category'] = $category;
}

if ($search !== '') {
    $sql .= ' AND (p.name LIKE :search OR p.code LIKE :search2)';
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}

$sql .= ' ORDER BY p.recommended DESC, p.sort_order, p.name';

$products = query($sql, $params);

jsonResponse([
    'success' => true,
    'data'    => $products,
]);
