<?php declare(strict_types=1);

/**
 * 快活システム - 店舗一覧API
 *
 * GET /api/master/shops.php?area_code=103
 * - area_code: オプション（指定時は該当エリアの店舗のみ）
 * - is_active = 1 のみ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$areaCode = $_GET['area_code'] ?? '';

$sql    = 'SELECT s.code AS shop_code, s.name AS shop_name, s.area_code, a.zone_code,
                  (SELECT GROUP_CONCAT(sc.category_code)
                     FROM shop_categories sc WHERE sc.shop_code = s.code) AS categories
           FROM shops s
           JOIN areas a ON s.area_code = a.code
           WHERE s.is_active = 1';
$params = [];

if ($areaCode !== '') {
    $sql .= ' AND s.area_code = :area_code';
    $params[':area_code'] = $areaCode;
}

$sql .= ' ORDER BY s.sort_order, s.code';

$shops = query($sql, $params);

jsonResponse([
    'success' => true,
    'data'    => $shops,
]);
