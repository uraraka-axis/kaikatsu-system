<?php declare(strict_types=1);

/**
 * 快活システム - 予算データCSV出力API
 *
 * GET /api/export/budgets.php?year=2026&dept=all&zone=&area=&shop=
 * - BOM付きUTF-8 CSV（Excel対応）
 * - クエリパラメータは budgets.php と同じ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

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

// 部門ラベル
$deptLabels = [
    'all' => '全体',
    'fit' => 'フィットネス',
    'ig'  => 'インドアゴルフ',
];

// --- 店舗ユーザーは自店のみ ---
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
}

// --- 対象店舗を取得 ---
$shopSql    = 'SELECT s.code AS shop_code, s.name AS shop_name, s.area_code,
                      a.zone_code, a.name AS area_name, z.name AS zone_name
               FROM shops s
               JOIN areas a ON s.area_code = a.code
               JOIN zones z ON a.zone_code = z.code
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

// --- 予算データ取得 ---
$shopCodes = array_column($shops, 'shop_code');

$budgetMap = [];
if (!empty($shopCodes)) {
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
    foreach ($budgetRows as $row) {
        $budgetMap[$row['shop_code']][(int)$row['month']] = [
            'budget' => (int)$row['budget_amount'],
            'actual' => (int)$row['actual_amount'],
        ];
    }
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- CSV出力 ---
$filename = sprintf('budget_%d_%s_%s.csv', $fiscalYear, $dept, date('Ymd'));

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

// BOM付きUTF-8
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

// ヘッダ行
$header = [
    '店舗コード',
    '店舗名',
    'ゾーン',
    'エリア',
    '年度',
    '部門',
];
foreach ($fiscalMonths as $m) {
    $header[] = $m . '月_予算';
    $header[] = $m . '月_実績';
}
$header[] = '年間_予算合計';
$header[] = '年間_実績合計';

fputcsv($output, $header);

// データ行
foreach ($shops as $shop) {
    $code = $shop['shop_code'];
    $row  = [
        $shop['shop_code'],
        $shop['shop_name'],
        $shop['zone_name'],
        $shop['area_name'],
        $fiscalYear . '年度',
        $deptLabels[$dept] ?? $dept,
    ];

    $totalBudget = 0;
    $totalActual = 0;

    foreach ($fiscalMonths as $m) {
        $entry = $budgetMap[$code][$m] ?? ['budget' => 0, 'actual' => 0];
        $row[] = $entry['budget'];
        $row[] = $entry['actual'];
        $totalBudget += $entry['budget'];
        $totalActual += $entry['actual'];
    }

    $row[] = $totalBudget;
    $row[] = $totalActual;

    fputcsv($output, $row);
}

fclose($output);
exit;
