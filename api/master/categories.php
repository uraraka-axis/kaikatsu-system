<?php declare(strict_types=1);

/**
 * 快活システム - カテゴリ一覧API
 *
 * GET /api/master/categories.php
 * - is_active = 1 のみ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$categories = query(
    'SELECT code, name, closing_type, closing_day
     FROM categories
     WHERE is_active = 1
     ORDER BY sort_order, code'
);

foreach ($categories as &$c) {
    $c['closing_day'] = (int)$c['closing_day'];
}
unset($c);

jsonResponse([
    'success' => true,
    'data'    => $categories,
]);
