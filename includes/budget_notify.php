<?php declare(strict_types=1);

/**
 * 快活システム - 予算超過見込み通知
 *
 * 店舗が備品発注（status=0）した時点で、四半期予算の「仮計上合計」が
 * 予算を新たに跨いだ（≤予算 → >予算）場合だけ、ゾーンマネージャー・エリアマネージャーへ
 * 【予算超過見込み】メールを送る。
 *
 * 【仮計上合計の定義】
 *   仮計上合計 = budgets.actual_amount（納品済以上・全種別の確定実績）
 *              + 未納品の発注見込み（status 0/1/2・全種別・getInflightPipelineTotal）
 *   ※ 判定対象の新規発注は、作成時点で既に status=0 として DB に存在するため
 *      getInflightPipelineTotal の戻り値に含まれる（= after 相当）。
 *
 * 【通知ポリシー】
 *   - 状態フラグは持たず、発注のたびに before/after を都度計算する。
 *     → 跨いだ瞬間に 1 回だけ送信。既に超過済みなら再送しない。
 *     → 取消で予算内に戻り、再発注で再び跨いだら再送する（自然な挙動）。
 *   - 取消されても送信済みメールは撤回しない（見込み時点のスナップショット通知）。
 *   - 備品発注のみが発火点（修理/部品/シート交換は対象外）。
 */

require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/mailer.php';

/**
 * 四半期合計が「予算内 → 超過」へ上向きにクロスしたかを判定する純関数。
 *
 *   before <= budget かつ after > budget のときだけ true。
 *   （budget ちょうどは "超過" に含めない。定義は > budget）
 */
function quarterBudgetCrossedUpward(int $before, int $after, int $budget): bool
{
    return $before <= $budget && $after > $budget;
}

/**
 * 仮計上ベースで「予算超過見込み」を判定し、新たに跨いだ場合のみ通知する。
 *
 * @param array $order       orders 行（id, shop_code, category_code 必須。メール本文用）
 * @param int   $orderAmount 今回の発注見込み額（備品のカート合計 = estimate_amount）
 * @param array $key         計上月キー（resolveBudgetKeyByDate() の戻り値）
 */
function notifyIfProvisionalQuarterBudgetCrossed(array $order, int $orderAmount, array $key): void
{
    if ($orderAmount <= 0) return;

    $totals = getQuarterlyBudgetTotal(
        $key['shop_code'], $key['fiscal_year'], $key['month'], $key['department']
    );
    // after: 確定実績 + 未納品見込み（今回の発注を含む）
    $pipeline = getInflightPipelineTotal(
        $key['shop_code'], $key['department'], $key['fiscal_year'], $key['month']
    );
    $after  = $totals['actual'] + $pipeline;
    $before = $after - $orderAmount;
    $budget = $totals['budget'];

    if (!quarterBudgetCrossedUpward($before, $after, $budget)) {
        return;
    }

    sendProvisionalOverBudgetMail($order, $key, ['budget' => $budget, 'provisional' => $after], $orderAmount);
}

/**
 * 予算超過見込みの通知メールを組み立てて送る。
 */
function sendProvisionalOverBudgetMail(array $order, array $key, array $totals, int $orderAmount): void
{
    $shopRow = getOne(
        'SELECT s.name AS shop_name, s.area_code, a.zone_code
           FROM shops s JOIN areas a ON s.area_code = a.code
          WHERE s.code = :c',
        [':c' => $key['shop_code']]
    );
    $shopName = $shopRow['shop_name'] ?? $key['shop_code'];

    $catRow  = getOne('SELECT name FROM categories WHERE code = :c', [':c' => $key['department']]);
    $catName = $catRow['name'] ?? $key['department'];

    // 通知先メアド: その店舗のユーザー行に紐づく zone_manager / area_manager メアド
    $userRow = getOne(
        "SELECT zone_manager_email, area_manager_email
           FROM users
          WHERE shop_code = :sc AND role = 'shop' AND is_active = 1",
        [':sc' => $key['shop_code']]
    );
    $emails = [];
    if (!empty($userRow['zone_manager_email'])) $emails[] = $userRow['zone_manager_email'];
    if (!empty($userRow['area_manager_email'])) $emails[] = $userRow['area_manager_email'];
    if (empty($emails)) {
        error_log('budget over (provisional) notification: no zone/area manager emails for shop ' . $key['shop_code']);
        return;
    }

    $qLabel = getQuarterLabel($key['month']);
    $qRange = getQuarterRange($key['month']);

    $budget      = (int)$totals['budget'];
    $provisional = (int)$totals['provisional'];
    $over        = $provisional - $budget;

    $body  = "{$shopName}（{$key['shop_code']}）の{$qLabel}（{$qRange}）{$catName}予算が、未納品の発注を含めると超過する見込みです。\n\n";
    $body .= "■ 店舗:            {$shopName} ({$key['shop_code']})\n";
    $body .= "■ カテゴリ:        {$catName}\n";
    $body .= "■ 四半期:          {$qLabel}（{$qRange}）／{$key['fiscal_year']}年度\n";
    $body .= "■ 四半期予算:      ¥" . number_format($budget) . "\n";
    $body .= "■ 仮計上合計:      ¥" . number_format($provisional) . "（実績額＋未納品の発注額）\n";
    $body .= "■ 超過見込み額:    ¥" . number_format($over) . "\n\n";
    $body .= "※ これは納品前の見込み値です。発注の取消・金額変動により最終実績は前後します。\n";

    sendMail(
        $emails,
        "【予算超過見込み】{$shopName}（{$key['shop_code']}） {$catName} {$qLabel}（¥" . number_format($over) . " 超過見込み）",
        $body,
        ['html' => false]
    );
}
