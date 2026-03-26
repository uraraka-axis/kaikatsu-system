<?php declare(strict_types=1);

/**
 * 快活システム - ゾーン一覧API
 *
 * GET /api/master/zones.php
 * - is_active = 1 のみ
 * - sort_order, code 順
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$zones = query(
    'SELECT code AS zone_code, name AS zone_name
     FROM zones
     WHERE is_active = 1
     ORDER BY sort_order, code'
);

jsonResponse([
    'success' => true,
    'data'    => $zones,
]);
