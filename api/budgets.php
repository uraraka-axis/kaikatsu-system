<?php declare(strict_types=1);

/**
 * 快活システム - 予算管理API
 *
 * GET /api/budgets.php?year=2026&dept=all&zone=&area=&shop=
 * - 店舗ユーザー: 自店のデータのみ
 * - 管理者: フィルタに応じて全店 or 絞り込み
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
requireMethod('GET');

// --- パラメータ取得 ---
$user       = getCurrentUser();
$fiscalYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentFiscalYear();
$dept       = $_GET['dept'] ?? 'all';
$zoneCode   = $_GET['zone'] ?? '';
$areaCode   = $_GET['area'] ?? '';
$shopCode   = $_GET['shop'] ?? '';

// 部門バリデーション
if (!in_array($dept, ['all', 'fit', 'ig'], true)) {
    jsonError('不正な部門パラメータです');
}

// --- 店舗ユーザーは自店のみ ---
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'];
    // 店舗ユーザーはゾーン・エリアフィルタ無効
    $zoneCode = '';
    $areaCode = '';
}

// --- 対象店舗を取得 ---
$shopSql    = 'SELECT s.code AS shop_code, s.name AS shop_name, s.area_code,
                      a.zone_code
               FROM shops s
               JOIN areas a ON s.area_code = a.code
               WHERE s.is_active = 1';
$shopParams = [];

if ($shopCode !== '') {
    $shopSql .= ' AND s.code = :shop_code';
    $shopParams[':shop_code'] = $shopCode;
}
if ($areaCode !== '') {
    $shopSql .= ' AND s.area_code = :area_code';
    $shopParams[':area_code'] = $areaCode;
}
if ($zoneCode !== '') {
    $shopSql .= ' AND a.zone_code = :zone_code';
    $shopParams[':zone_code'] = $zoneCode;
}

$shopSql .= ' ORDER BY s.sort_order, s.code';
$shops = query($shopSql, $shopParams);

if (empty($shops)) {
    jsonResponse([
        'success' => true,
        'data'    => [],
    ]);
}

// --- 対象店舗コード一覧 ---
$shopCodes = array_column($shops, 'shop_code');

// --- 予算データ取得 ---
$placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
$budgetSql    = "SELECT shop_code, month, budget_amount, actual_amount
                 FROM budgets
                 WHERE fiscal_year = :fiscal_year
                   AND department  = :department
                   AND shop_code IN ({$placeholders})
                 ORDER BY shop_code, month";

$budgetParams = [
    ':fiscal_year' => $fiscalYear,
    ':department'  => $dept,
];
foreach ($shopCodes as $i => $sc) {
    $budgetParams[':sc' . $i] = $sc;
}

$budgetRows = query($budgetSql, $budgetParams);

// --- 店舗ごとにグルーピング ---
$budgetMap = [];
foreach ($budgetRows as $row) {
    $budgetMap[$row['shop_code']][(int)$row['month']] = [
        'budget' => (int)$row['budget_amount'],
        'actual' => (int)$row['actual_amount'],
    ];
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- レスポンス組み立て ---
$data = [];
foreach ($shops as $shop) {
    $code    = $shop['shop_code'];
    $monthly = [];

    foreach ($fiscalMonths as $m) {
        $entry = $budgetMap[$code][$m] ?? ['budget' => 0, 'actual' => 0];
        $monthly[] = [
            'month'  => $m,
            'budget' => $entry['budget'],
            'actual' => $entry['actual'],
        ];
    }

    $data[] = [
        'shop_code'  => $code,
        'shop_name'  => $shop['shop_name'],
        'zone_code'  => $shop['zone_code'],
        'area_code'  => $shop['area_code'],
        'monthly'    => $monthly,
    ];
}

jsonResponse([
    'success'      => true,
    'fiscal_year'  => $fiscalYear,
    'department'   => $dept,
    'fiscalMonths' => $fiscalMonths,
    'data'         => $data,
]);
