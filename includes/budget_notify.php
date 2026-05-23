<?php declare(strict_types=1);

/**
 * 快活システム - 予算超過通知
 *
 * 四半期予算を「新たに」超過したときだけ、ゾーンマネージャー・エリアマネージャーへ
 * メールを送る。同じ四半期で何度発注を入れても、しきい値クロス時の1回だけ通知する。
 */

require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/mailer.php';

/**
 * delta 加算の前後を比較して「新たに四半期予算超過になった」場合のみ通知。
 *
 * 呼び出し側で applyBudgetActualDelta() の "直前と直後" にこの関数を挟むのではなく、
 * 1回呼び出すだけで before/after 差分判定からメール送信まで完結させる。
 *
 * @param array $order orders 行 (shop_code, category_code, date 必須)
 * @param int   $delta 加算済みの差分額（applyBudgetActualDelta で適用したのと同じ値）
 *                     正の値: actual が増えた / 負の値: 減った
 */
function notifyIfQuarterBudgetCrossed(array $order, int $delta): void
{
    if ($delta <= 0) return; // 加算でなければ通知不要

    $key = resolveBudgetKey($order);
    $after = getQuarterlyBudgetTotal(
        $key['shop_code'], $key['fiscal_year'], $key['month'], $key['department']
    );

    // 「加算前は予算内、加算後は超過」の場合のみ通知
    $beforeActual = $after['actual'] - $delta;
    $budget       = $after['budget'];
    $wasOver      = $beforeActual > $budget;
    $isOver       = $after['actual'] > $budget;

    if ($wasOver || !$isOver) {
        return;
    }

    sendQuarterlyOverBudgetMail($order, $key, $after, $delta);
}

/**
 * 四半期予算超過の通知メールを実際に組み立てて送る。
 */
function sendQuarterlyOverBudgetMail(array $order, array $key, array $totals, int $delta): void
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
        error_log('budget over notification: no zone/area manager emails for shop ' . $key['shop_code']);
        return;
    }

    $qLabel = getQuarterLabel($key['month']);
    $qRange = getQuarterRange($key['month']);

    $budget = (int)$totals['budget'];
    $actual = (int)$totals['actual'];
    $over   = $actual - $budget;

    $body  = "{$shopName}（{$key['shop_code']}）の{$qLabel}（{$qRange}）{$catName}予算が超過しました。\n\n";
    $body .= "■ 店舗:        {$shopName} ({$key['shop_code']})\n";
    $body .= "■ カテゴリ:    {$catName}\n";
    $body .= "■ 四半期:      {$qLabel}（{$qRange}）／{$key['fiscal_year']}年度\n";
    $body .= "■ 四半期予算:  ¥" . number_format($budget) . "\n";
    $body .= "■ 四半期実績:  ¥" . number_format($actual) . "\n";
    $body .= "■ 超過額:      ¥" . number_format($over) . "\n";
    $body .= "■ 起点発注:    {$order['id']}（¥" . number_format($delta) . "）\n\n";
    $body .= "発注内容のご確認をお願いします。\n";

    sendMail(
        $emails,
        "【予算超過】{$shopName} {$catName} {$qLabel}（¥" . number_format($over) . " 超過）",
        $body,
        ['html' => false]
    );
}
