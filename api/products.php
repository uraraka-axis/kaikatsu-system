<?php declare(strict_types=1);

/**
 * 快活システム - 商品一覧API
 *
 * GET /api/products.php?category=fitness&search=マット
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
requireMethod('GET');

$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';

$sql = 'SELECT p.id, p.name, p.code, p.price, p.category_code AS category,
               p.recommended, p.image_path, p.description,
               s.name AS supplier
        FROM products p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.is_active = 1';
$params = [];

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
