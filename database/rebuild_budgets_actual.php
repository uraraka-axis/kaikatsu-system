<?php declare(strict_types=1);

/**
 * 一回限り実行: budgets.actual_amount を実発注から再集計してリセットする。
 *
 * 【現行ルール（納品月ベース計上）】
 *   - status = 0,1,2: 未納品のため actual に乗らない
 *   - status = 3 (納品済/修理済): estimate_amount を加算
 *   - status = 4 (完了)         : final_amount を加算（NULL なら estimate）
 *   - 計上月:
 *       備品/部品 → orders.actual_delivery_date
 *       修理     → order_repair_details.repair_completed_date
 *   - 取消発注 (cancelled_at IS NOT NULL) は除外
 *
 * 使い方:
 *   "C:/xampp/php/php.exe" rebuild_budgets_actual.php
 *
 * 備考:
 *   旧ルール（発注日 + カテゴリ締めルール）で計上していたものを、今回の改修で
 *   納品月ベースに切り替えるための baseline 作成にも使用する。
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/budget.php';

echo "== 納品月ベース: budgets.actual_amount 再集計 開始 ==\n";

// ステップ1: 既存の actual_amount をゼロクリア
execute('UPDATE budgets SET actual_amount = 0');
echo "[reset] actual_amount = 0 を全行に適用\n";

// ステップ2: status >= 3 かつ取消されていない発注を取得
//          修理の場合は repair_completed_date を JOIN で取得
$orders = query(
    "SELECT o.id, o.type, o.shop_code, o.category_code, o.date,
            o.status, o.estimate_amount, o.final_amount, o.actual_delivery_date,
            rd.repair_completed_date
       FROM orders o
       LEFT JOIN order_repair_details rd ON rd.order_id = o.id
      WHERE o.status >= 3
        AND o.cancelled_at IS NULL
      ORDER BY o.date, o.id"
);
echo sprintf("[fetch] 対象 orders 件数 (status>=3, 未取消): %d\n", count($orders));

$applied = 0;
$skipped = 0;
$noDateSkipped = 0;

foreach ($orders as $o) {
    $status   = (int)$o['status'];
    $estimate = (int)($o['estimate_amount'] ?? 0);
    $finalRaw = $o['final_amount'];

    // 採用金額: status=3 は estimate / status=4 は final (NULLなら estimate)
    if ($status === 3) {
        $amount = $estimate;
    } else { // status >= 4
        $amount = $finalRaw !== null ? (int)$finalRaw : $estimate;
    }

    if ($amount <= 0) {
        $skipped++;
        continue;
    }

    // 納品月ベースで加算（修理は repair_completed_date を使うため
    // applyBudgetActualDeltaByDelivery が DB を再参照する）
    try {
        $ok = applyBudgetActualDeltaByDelivery($o, $amount);
        if ($ok) {
            $applied++;
        } else {
            // 日付未設定でスキップされた件は警告として記録
            $noDateSkipped++;
            $dateField = ($o['type'] === 'repair') ? 'repair_completed_date' : 'actual_delivery_date';
            echo sprintf("[warn] %s: %s 未設定のため除外 (status=%d)\n", $o['id'], $dateField, $status);
        }
    } catch (Throwable $e) {
        echo sprintf("[error] order=%s: %s\n", $o['id'], $e->getMessage());
        $skipped++;
    }
}

echo sprintf(
    "[done] 反映: %d 件 / 日付未設定スキップ: %d 件 / その他スキップ: %d 件\n",
    $applied, $noDateSkipped, $skipped
);

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
