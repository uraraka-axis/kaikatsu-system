<?php declare(strict_types=1);

/**
 * 快活システム - 予算実績(actual_amount)の更新ヘルパ
 *
 * 発注ステータス遷移のタイミングで計上月の budgets.actual_amount を増減する。
 *
 * 【納品月ベース計上 (現行ルール)】
 *  - 加算: status=3 (納品済/修理済) 遷移時 → estimate_amount を += する
 *  - 修正: status=4 (完了) 遷移時 → final_amount - estimate_amount の差分を += する
 *  - 修正: 完了後の final_amount 編集時 → 差分を += する
 *  - 計上月:
 *      備品/部品 → orders.actual_delivery_date
 *      修理     → order_repair_details.repair_completed_date
 *  → applyBudgetActualDeltaByDelivery() / resolveBudgetKeyByDelivery() を使う。
 *
 * 【旧ルール (カテゴリ締め日ベース)】
 *  - applyBudgetActualDelta() / resolveBudgetKey()
 *  - 現在は rebuild_budgets_actual.php の旧バージョン互換用に残置。
 *    新規呼び出しは納品月ベースに統一すること。
 *
 * カテゴリ締めルール (closing_type/closing_day) は発注期限カレンダー用として
 * categories テーブルに残しているが、予算計上には使わない。
 *
 * 対象 budgets 行が存在しない場合は budget_amount=0 で INSERT してから加算する。
 */

require_once __DIR__ . '/db.php';

/**
 * 発注日とカテゴリの締めルールから計上月の任意の日 (DateTimeImmutable) を返す。
 *
 *   monthly: 発注日.day <= closing_day なら当月、超えていれば翌月
 *   weekly:  発注日 以降の最初の closing_day(曜日) の翌日が属する月
 *   none:    発注日の月そのまま
 */
function computeSettlementDate(string $orderDate, string $closingType, int $closingDay): DateTimeImmutable
{
    $d = new DateTimeImmutable($orderDate);

    if ($closingType === 'monthly') {
        $day = (int)$d->format('j');
        if ($day <= $closingDay) {
            return $d;
        }
        return $d->modify('first day of next month');
    }

    if ($closingType === 'weekly') {
        // 0=日, 1=月, ..., 6=土 (PHP の 'w' フォーマットと同一)
        $currentDow = (int)$d->format('w');
        $daysUntilClosing = ($closingDay - $currentDow + 7) % 7;
        return $d->modify('+' . ($daysUntilClosing + 1) . ' days');
    }

    return $d; // none
}

/**
 * 計上月の (shop_code, fiscal_year, month, department) を求める。
 *
 * @return array{shop_code:string, fiscal_year:int, month:int, department:string}
 */
function resolveBudgetKey(array $order): array
{
    $catRow = getOne(
        'SELECT closing_type, closing_day FROM categories WHERE code = :code',
        [':code' => $order['category_code']]
    );
    $closingType = $catRow['closing_type'] ?? 'none';
    $closingDay  = (int)($catRow['closing_day'] ?? 0);

    $settlement = computeSettlementDate($order['date'], $closingType, $closingDay);
    $month = (int)$settlement->format('n');
    $year  = (int)$settlement->format('Y');
    // 年度は4月始まり: 1-3月は前年度
    $fiscalYear = $month >= 4 ? $year : $year - 1;

    return [
        'shop_code'   => (string)$order['shop_code'],
        'fiscal_year' => $fiscalYear,
        'month'       => $month,
        'department'  => (string)$order['category_code'],
    ];
}

/**
 * 指定発注の計上月の budgets.actual_amount に delta を加算する。
 * 行が存在しなければ budget_amount=0 で INSERT してから加算する。
 *
 * @param array $order  orders 行 (id, shop_code, category_code, date を含む)
 * @param int   $delta  加算したい金額 (負数で減算)
 */
function applyBudgetActualDelta(array $order, int $delta): void
{
    if ($delta === 0) return;

    $key = resolveBudgetKey($order);

    // UPSERT: INSERT ... ON DUPLICATE KEY UPDATE で複合UK衝突時は加算
    execute(
        'INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
         VALUES (:s, :y, :m, :d, 0, :a)
         ON DUPLICATE KEY UPDATE actual_amount = actual_amount + VALUES(actual_amount)',
        [
            ':s' => $key['shop_code'],
            ':y' => $key['fiscal_year'],
            ':m' => $key['month'],
            ':d' => $key['department'],
            ':a' => $delta,
        ]
    );
}

/**
 * 納品月ベースの計上月キーを返す（現行ルール）。
 *
 *   備品/部品 : orders.actual_delivery_date
 *   修理      : order_repair_details.repair_completed_date
 *
 * 該当日付が NULL の場合は null を返す（呼び出し側で扱いを決める）。
 *
 * @param array $order orders 行 (id, shop_code, category_code, type を必須)
 *                     actual_delivery_date が配列に含まれていればそれを優先（DB再取得を回避）。
 * @return array{shop_code:string, fiscal_year:int, month:int, department:string}|null
 */
function resolveBudgetKeyByDelivery(array $order): ?array
{
    $type = $order['type'] ?? '';

    if ($type === 'repair') {
        $row = getOne(
            'SELECT repair_completed_date FROM order_repair_details WHERE order_id = :oid',
            [':oid' => $order['id']]
        );
        $dateStr = $row['repair_completed_date'] ?? null;
    } else {
        // equipment / parts
        $dateStr = $order['actual_delivery_date'] ?? null;
        if ($dateStr === null) {
            $row = getOne(
                'SELECT actual_delivery_date FROM orders WHERE id = :oid',
                [':oid' => $order['id']]
            );
            $dateStr = $row['actual_delivery_date'] ?? null;
        }
    }

    if ($dateStr === null || $dateStr === '') {
        return null;
    }

    $d = new DateTimeImmutable($dateStr);
    $month = (int)$d->format('n');
    $year  = (int)$d->format('Y');
    $fiscalYear = $month >= 4 ? $year : $year - 1;

    return [
        'shop_code'   => (string)$order['shop_code'],
        'fiscal_year' => $fiscalYear,
        'month'       => $month,
        'department'  => (string)$order['category_code'],
    ];
}

/**
 * 納品月ベースで budgets.actual_amount に delta を加算する。
 * 納品日（修理は完了日）がセットされていない場合は false を返してスキップする。
 *
 * @param array $order orders 行
 * @param int   $delta 加算したい金額（負数で減算）
 * @return bool true=反映 / false=日付未設定でスキップ
 */
function applyBudgetActualDeltaByDelivery(array $order, int $delta): bool
{
    if ($delta === 0) return true;

    $key = resolveBudgetKeyByDelivery($order);
    if ($key === null) {
        return false;
    }

    execute(
        'INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
         VALUES (:s, :y, :m, :d, 0, :a)
         ON DUPLICATE KEY UPDATE actual_amount = actual_amount + VALUES(actual_amount)',
        [
            ':s' => $key['shop_code'],
            ':y' => $key['fiscal_year'],
            ':m' => $key['month'],
            ':d' => $key['department'],
            ':a' => $delta,
        ]
    );
    return true;
}

/**
 * 計上月が属する四半期 (Q1=4-6 / Q2=7-9 / Q3=10-12 / Q4=1-3) の月配列を返す。
 */
function getQuarterMonths(int $month): array
{
    if ($month >= 4  && $month <= 6)  return [4, 5, 6];
    if ($month >= 7  && $month <= 9)  return [7, 8, 9];
    if ($month >= 10 && $month <= 12) return [10, 11, 12];
    return [1, 2, 3]; // Q4
}

function getQuarterLabel(int $month): string
{
    if ($month >= 4  && $month <= 6)  return 'Q1';
    if ($month >= 7  && $month <= 9)  return 'Q2';
    if ($month >= 10 && $month <= 12) return 'Q3';
    return 'Q4';
}

function getQuarterRange(int $month): string
{
    if ($month >= 4  && $month <= 6)  return '4-6月';
    if ($month >= 7  && $month <= 9)  return '7-9月';
    if ($month >= 10 && $month <= 12) return '10-12月';
    return '1-3月';
}

/**
 * 指定 (shop_code, fiscal_year, 計上月, department) が属する四半期の
 * budget_amount / actual_amount 合計を返す。
 *
 * @return array{budget:int, actual:int}
 */
function getQuarterlyBudgetTotal(string $shopCode, int $fiscalYear, int $month, string $department): array
{
    $months = getQuarterMonths($month);
    $placeholders = implode(',', array_map(fn($i) => ':m' . $i, array_keys($months)));

    $params = [
        ':s' => $shopCode,
        ':y' => $fiscalYear,
        ':d' => $department,
    ];
    foreach ($months as $i => $m) {
        $params[':m' . $i] = $m;
    }

    $row = getOne(
        "SELECT COALESCE(SUM(budget_amount), 0) AS budget,
                COALESCE(SUM(actual_amount), 0) AS actual
           FROM budgets
          WHERE shop_code = :s AND fiscal_year = :y AND department = :d
            AND month IN ({$placeholders})",
        $params
    );
    return [
        'budget' => (int)($row['budget'] ?? 0),
        'actual' => (int)($row['actual'] ?? 0),
    ];
}
