<?php declare(strict_types=1);

/**
 * 快活システム - エリア一覧API
 *
 * GET /api/master/areas.php?zone_code=100
 * - zone_code: オプション（指定時は該当ゾーンのエリアのみ）
 * - is_active = 1 のみ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$zoneCode = $_GET['zone_code'] ?? '';

$sql    = 'SELECT code AS area_code, name AS area_name, zone_code
           FROM areas
           WHERE is_active = 1';
$params = [];

if ($zoneCode !== '') {
    $sql .= ' AND zone_code = :zone_code';
    $params[':zone_code'] = $zoneCode;
}

$sql .= ' ORDER BY sort_order, code';

$areas = query($sql, $params);

jsonResponse([
    'success' => true,
    'data'    => $areas,
]);
