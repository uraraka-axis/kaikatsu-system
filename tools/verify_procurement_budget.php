<?php declare(strict_types=1);

/**
 * 自店調達 → 予算実績反映 の検証
 *
 * 検証内容:
 *   - 店舗ユーザーが自店調達申請を POST した瞬間に
 *     budgets.actual_amount が +amount される
 *   - 計上月は申請日（今日）の月
 *   - クリーンアップで申請・予算とも元に戻す
 *
 * 使い方: "C:/xampp/php/php.exe" tools/verify_procurement_budget.php
 */

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('Asia/Tokyo');

$BASE_URL = 'http://localhost/kaikatsu-system/';
$tmpCookieDir = __DIR__ . '/_tmp_verify_cookies';
@mkdir($tmpCookieDir);

function http_login(string $loginId, string $password): string {
    global $BASE_URL, $tmpCookieDir;
    $cookieFile = $tmpCookieDir . '/cookie_' . preg_replace('/\W+/', '_', $loginId) . '.txt';
    @unlink($cookieFile);
    $ch = curl_init($BASE_URL . 'api/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['login_id' => $loginId, 'password' => $password]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("login failed: {$code}");
    return $cookieFile;
}

function http_post_json(string $url, string $cookieFile, array $body): array {
    global $BASE_URL;
    $ch = curl_init($BASE_URL . ltrim($url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'json' => json_decode($resp, true)];
}

echo "== 自店調達 → 予算反映 検証 ==\n\n";

$shopCookie = http_login('10303', 'password'); // 横浜店
$shopCode = '10303';
$category = 'fitness';
$amount = 3500;
$today = date('Y-m-d');
$month = (int)date('n');
$year = (int)date('Y');
$fy = $month >= 4 ? $year : $year - 1;

// Before
$before = getOne(
    'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
    [':s' => $shopCode, ':y' => $fy, ':m' => $month, ':d' => $category]
);
$beforeAmt = (int)($before['actual_amount'] ?? 0);
echo "[before] shop={$shopCode} FY{$fy}/{$month}月 {$category}: actual={$beforeAmt}\n";

// 申請
$r = http_post_json('api/procurement.php', $shopCookie, [
    'category_code' => $category,
    'amount'        => $amount,
    'reason'        => '【検証】rebuild 後の予算反映チェック',
]);
if ($r['code'] !== 200 || empty($r['json']['success'])) {
    echo "FAIL: 申請失敗 HTTP={$r['code']} body={$r['body']}\n";
    exit(1);
}
$reqId = $r['json']['data']['id'];
echo "[create] {$reqId} (¥{$amount}) 作成\n";

// After
$after = getOne(
    'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
    [':s' => $shopCode, ':y' => $fy, ':m' => $month, ':d' => $category]
);
$afterAmt = (int)($after['actual_amount'] ?? 0);
echo "[after]  shop={$shopCode} FY{$fy}/{$month}月 {$category}: actual={$afterAmt}\n";

$pass = ($afterAmt === $beforeAmt + $amount);
echo $pass ? "  PASS  +{$amount} が反映された\n" : "  FAIL  期待: " . ($beforeAmt + $amount) . " / 実際: {$afterAmt}\n";

// Cleanup
execute('DELETE FROM procurement_requests WHERE id=:id', [':id' => $reqId]);
execute(
    'UPDATE budgets SET actual_amount = actual_amount - :a WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
    [':a' => $amount, ':s' => $shopCode, ':y' => $fy, ':m' => $month, ':d' => $category]
);
echo "[cleanup] {$reqId} 削除 + actual_amount -={$amount}\n";

array_map('unlink', glob($tmpCookieDir . '/cookie_*.txt'));
@rmdir($tmpCookieDir);

echo "\n== " . ($pass ? "完了 (PASS)" : "失敗") . " ==\n";
exit($pass ? 0 : 1);
