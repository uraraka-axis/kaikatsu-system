<?php declare(strict_types=1);

/**
 * 快活システム - 予算管理API
 *
 * GET /api/budgets.php?year=2026&dept=all&zone=&area=&shop=
 * - 店舗ユーザー: 自店のデータのみ
 * - 管理者: フィルタに応じて全店 or 絞り込み
 *
 * 発注の計上月はカテゴリの締めルール（closing_type/closing_day）に基づき、
 * 「実発注日（＝締め日の翌日）」が属する月に振り分ける。
 *   - monthly: order.date.day <= closing_day なら当月、超えていれば翌月
 *   - weekly:  order.date 以降の最初の closing_day(曜日) の翌日が属する月
 *   - none:    order.date の月（従来通り）
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
requireMethod('GET');

// --- 年度一覧取得 ---
$action = $_GET['action'] ?? '';
if ($action === 'years') {
    $rows = query(
        'SELECT DISTINCT fiscal_year FROM budgets ORDER BY fiscal_year DESC'
    );
    $years = array_map(fn($r) => (int)$r['fiscal_year'], $rows);
    jsonResponse([
        'success' => true,
        'data'    => $years,
    ]);
}

// --- パラメータ取得 ---
$user       = getCurrentUser();
$fiscalYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentFiscalYear();
$dept       = $_GET['dept'] ?? 'all';
$zoneCode   = $_GET['zone'] ?? '';
$areaCode   = $_GET['area'] ?? '';
$shopCode   = $_GET['shop'] ?? '';

// 部門バリデーション: 'all' または categories.code のいずれか
$validCategoryCodes = array_column(query('SELECT code FROM categories WHERE is_active = 1'), 'code');
$validDeptValues    = array_merge(['all'], $validCategoryCodes);
if (!in_array($dept, $validDeptValues, true)) {
    jsonError('不正な部門パラメータです');
}

// --- ロール別の閲覧スコープ ---
// shop: 自店のみ / admin/system: 全店 / zone: 管轄ゾーン配下 / area: 管轄エリア配下
if ($user['role'] === 'shop') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
} elseif ($user['role'] === 'zone') {
    // zone は自身の管轄ゾーンに強制。配下エリア/店舗はフロントから絞り込み可。
    $zoneCode = $user['zone_code'];
} elseif ($user['role'] === 'area') {
    $areaCode = $user['area_code'];
    $zoneCode = '';
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
// 'all' 行は廃止済み。dept='all' のときは shop+month で SUM し、
// 部門指定時はその部門の行のみ取得する。
$placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
if ($dept === 'all') {
    $budgetSql = "SELECT shop_code, month,
                         SUM(budget_amount) AS budget_amount,
                         SUM(actual_amount) AS actual_amount
                  FROM budgets
                  WHERE fiscal_year = :fiscal_year
                    AND shop_code IN ({$placeholders})
                  GROUP BY shop_code, month
                  ORDER BY shop_code, month";
    $budgetParams = [':fiscal_year' => $fiscalYear];
} else {
    $budgetSql = "SELECT shop_code, month, budget_amount, actual_amount
                  FROM budgets
                  WHERE fiscal_year = :fiscal_year
                    AND department  = :department
                    AND shop_code IN ({$placeholders})
                  ORDER BY shop_code, month";
    $budgetParams = [
        ':fiscal_year' => $fiscalYear,
        ':department'  => $dept,
    ];
}
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

// --- 店舗の取扱カテゴリを取得 ---
// 店舗ごとのタブを「その店舗が取り扱うカテゴリ」だけに絞り込むため、
// shop_categories から各店舗のカテゴリコード一覧を取得する。
$catMap = [];
if (!empty($shopCodes)) {
    $catSql = "SELECT sc.shop_code, sc.category_code
                 FROM shop_categories sc
                 JOIN categories c ON sc.category_code = c.code
                WHERE c.is_active = 1
                  AND sc.shop_code IN ({$placeholders})
                ORDER BY c.sort_order, c.code";
    $catParams = [];
    foreach ($shopCodes as $i => $sc) {
        $catParams[':sc' . $i] = $sc;
    }
    $catRows = query($catSql, $catParams);
    foreach ($catRows as $row) {
        $catMap[$row['shop_code']][] = $row['category_code'];
    }
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- レスポンス組み立て ---
// 2026-05-23: Plan B 適用。orders の動的集計は廃止し、budgets.actual_amount を Single Source of Truth とする。
// 発注確定 (status=発注済) 時に api/orders/status.php → applyBudgetActualDelta() で actual_amount が更新される。
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
        'categories' => $catMap[$code] ?? [],
    ];
}

jsonResponse([
    'success'      => true,
    'fiscal_year'  => $fiscalYear,
    'department'   => $dept,
    'fiscalMonths' => $fiscalMonths,
    'data'         => $data,
]);
