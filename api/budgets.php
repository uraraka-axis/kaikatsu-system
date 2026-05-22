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

// --- カテゴリ別締めルール取得 ---
$categoryRows = query(
    'SELECT code, closing_type, closing_day FROM categories'
);
$closingMap = [];
foreach ($categoryRows as $row) {
    $closingMap[$row['code']] = [
        'type' => $row['closing_type'],
        'day'  => (int)$row['closing_day'],
    ];
}

// --- 発注額の動的集計（締めルールで計上月を決定） ---
// 締めルール適用で前年度3月の発注が当年度4月に繰り上がる、
// または当年度3月末の発注が翌年度4月に繰り下がる可能性があるため、
// 取得範囲を前後1ヶ月ずつ広げる
$orderDateFrom = (new DateTimeImmutable($fiscalYear . '-04-01'))
    ->modify('-1 month')->format('Y-m-d');
$orderDateTo   = (new DateTimeImmutable(($fiscalYear + 1) . '-03-31'))
    ->modify('+1 month')->format('Y-m-d');

$orderSql = "SELECT o.shop_code, o.date, o.category_code,
                    COALESCE(o.final_amount, o.estimate_amount, 0) AS amount
             FROM orders o
             WHERE o.shop_code IN ({$placeholders})
               AND o.date >= :date_from
               AND o.date <= :date_to";

$orderParams = [
    ':date_from' => $orderDateFrom,
    ':date_to'   => $orderDateTo,
];
foreach ($shopCodes as $i => $sc) {
    $orderParams[':sc' . $i] = $sc;
}

// 部門フィルタ: dept はそのまま category_code として扱える（'all'のときのみフィルタなし）
if ($dept !== 'all') {
    $orderSql .= ' AND o.category_code = :cat_code';
    $orderParams[':cat_code'] = $dept;
}

$orderRows = query($orderSql, $orderParams);

// --- PHP側で計上月を計算して集計 ---
$fyStart = new DateTimeImmutable($fiscalYear . '-04-01');
$fyEnd   = new DateTimeImmutable(($fiscalYear + 1) . '-03-31');

$orderMap = [];
foreach ($orderRows as $row) {
    $catCode = $row['category_code'];
    $closing = $closingMap[$catCode] ?? ['type' => 'none', 'day' => 0];

    $settlement = computeSettlementDate($row['date'], $closing['type'], $closing['day']);

    // 計上月が当年度範囲外なら除外
    if ($settlement < $fyStart || $settlement > $fyEnd) {
        continue;
    }

    $m = (int)$settlement->format('n');
    $sc = $row['shop_code'];
    if (!isset($orderMap[$sc][$m])) {
        $orderMap[$sc][$m] = 0;
    }
    $orderMap[$sc][$m] += (int)$row['amount'];
}

/**
 * 発注日とカテゴリの締めルールから「計上月の任意の日」を返す。
 * 戻り値の月のみ集計に使用する。
 */
function computeSettlementDate(string $orderDate, string $closingType, int $closingDay): DateTimeImmutable
{
    $d = new DateTimeImmutable($orderDate);

    if ($closingType === 'monthly') {
        $day = (int)$d->format('j');
        if ($day <= $closingDay) {
            // 当月締め → 当月計上
            return $d;
        }
        // 締め超過 → 翌月計上
        return $d->modify('first day of next month');
    }

    if ($closingType === 'weekly') {
        // 0=日, 1=月, ..., 6=土（PHP の 'w' フォーマットと同一）
        $currentDow = (int)$d->format('w');
        $daysUntilClosing = ($closingDay - $currentDow + 7) % 7;
        // 締め日の翌日が実発注日 → その月を計上月とする
        return $d->modify('+' . ($daysUntilClosing + 1) . ' days');
    }

    // none: 都度発注 → 発注日そのまま
    return $d;
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- レスポンス組み立て ---
$data = [];
foreach ($shops as $shop) {
    $code    = $shop['shop_code'];
    $monthly = [];

    foreach ($fiscalMonths as $m) {
        $entry      = $budgetMap[$code][$m] ?? ['budget' => 0, 'actual' => 0];
        $orderTotal = $orderMap[$code][$m] ?? 0;
        // 締め実績と発注集計の大きい方を採用（締め前の発注を反映）
        $actual = max($entry['actual'], $orderTotal);
        $monthly[] = [
            'month'  => $m,
            'budget' => $entry['budget'],
            'actual' => $actual,
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
