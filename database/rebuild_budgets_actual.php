<?php declare(strict_types=1);

/**
 * 一回限り実行: budgets.actual_amount を実発注 (status>=1) から再集計してリセットする。
 *
 * Plan B 移行用: api/budgets.php の動的集計を撤廃するに伴い、
 * budgets.actual_amount を Single Source of Truth にするための baseline 作成。
 *
 * 使い方:
 *   "C:/xampp/php/php.exe" rebuild_budgets_actual.php
 *
 * 仕様:
 *   - status = 0 (依頼中) の発注は actual に乗らない
 *   - status >= 1 の発注:
 *       - final_amount が NULL でなければ final_amount を採用
 *       - そうでなければ estimate_amount
 *       - 両方 NULL なら 0 (スキップ)
 *   - 計上月はカテゴリの締めルール (closing_type/closing_day) で計算
 *   - budgets 行が無ければ budget_amount=0 で作成して加算
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/budget.php';

echo "== Plan B: budgets.actual_amount 再集計 開始 ==\n";

// ステップ1: 既存の actual_amount をゼロクリア
$reset = execute('UPDATE budgets SET actual_amount = 0');
echo "[reset] actual_amount = 0 を全行に適用\n";

// ステップ2: status >= 1 の発注を取得
$orders = query(
    'SELECT id, shop_code, category_code, date, status, estimate_amount, final_amount
     FROM orders
     WHERE status >= 1
     ORDER BY date, id'
);
echo sprintf("[fetch] 対象 orders 件数: %d\n", count($orders));

// ステップ3: 各 order の計上月に金額を加算
$applied  = 0;
$skipped  = 0;
foreach ($orders as $o) {
    $amount = $o['final_amount'] !== null ? (int)$o['final_amount']
            : ($o['estimate_amount'] !== null ? (int)$o['estimate_amount'] : 0);

    if ($amount <= 0) {
        $skipped++;
        continue;
    }

    try {
        applyBudgetActualDelta($o, $amount);
        $applied++;
    } catch (Throwable $e) {
        echo sprintf("[error] order=%s: %s\n", $o['id'], $e->getMessage());
        $skipped++;
    }
}

echo sprintf("[done] 反映: %d 件 / スキップ: %d 件\n", $applied, $skipped);

// 確認用: 反映後の budgets サマリ
$summary = query(
    'SELECT shop_code, fiscal_year, department,
            SUM(budget_amount) AS budget, SUM(actual_amount) AS actual
       FROM budgets
      GROUP BY shop_code, fiscal_year, department
      HAVING actual > 0
      ORDER BY shop_code, fiscal_year, department'
);
echo "\n== 再集計後の actual > 0 のサマリ ==\n";
foreach ($summary as $r) {
    echo sprintf(
        "  shop=%s year=%d dept=%-10s budget=%s actual=%s\n",
        $r['shop_code'], $r['fiscal_year'], $r['department'],
        number_format((int)$r['budget']), number_format((int)$r['actual'])
    );
}

echo "\n== 完了 ==\n";
