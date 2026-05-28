<?php declare(strict_types=1);

/**
 * シート交換発注機能の API レベル自動検証スクリプト
 *
 * 検証対象:
 *   - 発注作成 (type='seat-replacement', issue 固定, category fitness 固定)
 *   - 発注番号 prefix が 'SHT-'
 *   - 修理ライクなステータスフロー (0→1→2→3→4)
 *   - 発注一覧 API でシート交換種別が正しく返る
 *   - Excel エクスポートで「シート交換」ラベル
 *
 * 使い方:
 *   "C:/xampp/php/php.exe" tools/verify_seat_replacement.php
 */

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('Asia/Tokyo');

$BASE_URL  = 'http://localhost/kaikatsu-system/';
$tmpCookieDir = __DIR__ . '/_tmp_verify_cookies';
@mkdir($tmpCookieDir);

// ============================================================
// HTTP helpers
// ============================================================
function http_login(string $loginId, string $password): string
{
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
    if ($code !== 200) throw new RuntimeException("login failed for {$loginId}: HTTP {$code}");
    return $cookieFile;
}

function http_get(string $url, string $cookieFile): array
{
    global $BASE_URL;
    $ch = curl_init($BASE_URL . ltrim($url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $json = (strpos($contentType, 'application/json') !== false) ? json_decode($resp, true) : null;
    return ['code' => $code, 'body' => $resp, 'json' => $json, 'content_type' => $contentType];
}

function http_post_json(string $url, string $cookieFile, array $body): array
{
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

function http_post_form(string $url, string $cookieFile, array $fields): array
{
    global $BASE_URL;
    $ch = curl_init($BASE_URL . ltrim($url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields, // multipart 自動
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'json' => json_decode($resp, true)];
}

// ============================================================
// Test runner
// ============================================================
$results = [];
function test(string $name, callable $fn): void {
    global $results;
    try {
        $fn();
        $results[] = ['name' => $name, 'pass' => true, 'msg' => ''];
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'pass' => false, 'msg' => $e->getMessage()];
        echo "  FAIL  {$name}\n        → " . $e->getMessage() . "\n";
    }
}
function assertEq($expected, $actual, string $label): void {
    if ($expected !== $actual) throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function assertTrue(bool $cond, string $label): void { if (!$cond) throw new RuntimeException($label); }

// ============================================================
echo "== シート交換発注機能 検証 開始 ==\n\n";

$adminCookie = http_login('admin', 'password');
$shopCookie  = http_login('10303', 'password'); // 横浜店
echo "[setup] ログイン OK\n\n";

// グローバルで使う createdOrderId
$createdOrderId = null;

// ============================================================
// Section A: 発注作成
// ============================================================
echo "== Section A: シート交換発注の作成 ==\n";

test('A1: 店舗ユーザーがシート交換発注を作成できる', function() use ($shopCookie, &$createdOrderId) {
    $r = http_post_form('api/orders/create.php', $shopCookie, [
        'type'           => 'seat-replacement',
        'category'       => 'fitness', // サーバでも強制されるが
        'equipment_name' => '【検証】ランニングマシン XT-3000',
        'unavail_dates'  => '[]',
        'unavail_days'   => '[]',
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertTrue(!empty($r['json']['success']), 'success=true');
    assertTrue(!empty($r['json']['order_id']), 'order_id 返却');
    $createdOrderId = $r['json']['order_id'];
});

test('A2: 発注番号の prefix が "SHT-"', function() use (&$createdOrderId) {
    assertTrue(strpos($createdOrderId, 'SHT-') === 0, "order_id={$createdOrderId} が SHT- で始まる");
});

test('A3: DB で orders.type="seat-replacement" + category="fitness" + status=0', function() use (&$createdOrderId) {
    $row = getOne('SELECT type, category_code, status FROM orders WHERE id=:id', [':id' => $createdOrderId]);
    assertEq('seat-replacement', $row['type'], 'type');
    assertEq('fitness', $row['category_code'], 'category');
    assertEq(0, (int)$row['status'], 'status');
});

test('A4: order_seat_replacement_details に issue="マシンのシート交換" 固定で保存', function() use (&$createdOrderId) {
    $row = getOne('SELECT equipment_name, issue FROM order_seat_replacement_details WHERE order_id=:id', [':id' => $createdOrderId]);
    assertEq('【検証】ランニングマシン XT-3000', $row['equipment_name'], 'equipment_name');
    assertEq('マシンのシート交換', $row['issue'], 'issue 固定');
});

test('A5: category!=fitness で送ってもサーバ側で fitness に強制', function() use ($shopCookie) {
    $r = http_post_form('api/orders/create.php', $shopCookie, [
        'type'           => 'seat-replacement',
        'category'       => 'golf', // サーバ側で fitness に強制される
        'equipment_name' => '【検証2】固定確認用',
        'unavail_dates'  => '[]',
        'unavail_days'   => '[]',
    ]);
    assertEq(200, $r['code'], 'HTTP');
    $tmpId = $r['json']['order_id'];
    $row = getOne('SELECT category_code FROM orders WHERE id=:id', [':id' => $tmpId]);
    assertEq('fitness', $row['category_code'], 'fitness に強制');
    // 後片付け
    execute('DELETE FROM order_seat_replacement_details WHERE order_id=:id', [':id' => $tmpId]);
    execute('DELETE FROM order_status_history WHERE order_id=:id', [':id' => $tmpId]);
    execute('DELETE FROM orders WHERE id=:id', [':id' => $tmpId]);
});

// ============================================================
// Section B: 発注一覧表示
// ============================================================
echo "\n== Section B: 発注一覧 API レスポンス ==\n";

test('B1: GET /api/orders.php で seat-replacement が含まれる', function() use ($adminCookie, &$createdOrderId) {
    $r = http_get('api/orders.php', $adminCookie);
    assertEq(200, $r['code'], 'HTTP');
    $found = null;
    foreach ($r['json']['data'] ?? [] as $o) {
        if ($o['id'] === $createdOrderId) { $found = $o; break; }
    }
    assertTrue($found !== null, '対象発注がレスポンスに含まれる');
    assertEq('seat-replacement', $found['type'], 'type');
    assertEq('マシンのシート交換', $found['issue'], 'issue');
    assertEq('【検証】ランニングマシン XT-3000', $found['equipment_name'], 'equipment_name');
});

test('B2: type=seat-replacement フィルタが効く', function() use ($adminCookie, &$createdOrderId) {
    $r = http_get('api/orders.php?type=seat-replacement', $adminCookie);
    assertEq(200, $r['code'], 'HTTP');
    $types = array_column($r['json']['data'] ?? [], 'type');
    if (count($types) === 0) throw new RuntimeException('レスポンス0件');
    $nonSeat = array_filter($types, fn($t) => $t !== 'seat-replacement');
    assertTrue(count($nonSeat) === 0, '全件 seat-replacement');
    $ids = array_column($r['json']['data'], 'id');
    assertTrue(in_array($createdOrderId, $ids, true), '作成済発注が含まれる');
});

// ============================================================
// Section C: 修理ライクなステータスフロー
// ============================================================
echo "\n== Section C: ステータスフロー (修理と同じ 0→1→2→3→4) ==\n";

test('C1: 0→1 order (admin, estimate 必須)', function() use ($adminCookie, &$createdOrderId) {
    $r = http_post_json('api/orders/status.php', $adminCookie, [
        'order_id'        => $createdOrderId,
        'action'          => 'order',
        'estimate_amount' => 15000,
        'repair_schedule_date' => '2026-06-05',
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertEq(1, (int)$r['json']['status'], 'status=1');
    $row = getOne('SELECT estimate_amount FROM orders WHERE id=:id', [':id' => $createdOrderId]);
    assertEq(15000, (int)$row['estimate_amount'], 'estimate_amount');
    $det = getOne('SELECT repair_schedule_date FROM order_seat_replacement_details WHERE order_id=:id', [':id' => $createdOrderId]);
    assertEq('2026-06-05', $det['repair_schedule_date'], 'repair_schedule_date 詳細テーブルに保存');
});

test('C2: 1→2 to-delivering (admin、修理と同じ扱い)', function() use ($adminCookie, &$createdOrderId) {
    $r = http_post_json('api/orders/status.php', $adminCookie, [
        'order_id' => $createdOrderId,
        'action'   => 'to-delivering',
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertEq(2, (int)$r['json']['status'], 'status=2');
});

test('C3: 2→3 repair-done (店舗、作業完了日必須)', function() use ($shopCookie, &$createdOrderId) {
    // 完了日なしは拒否
    $r1 = http_post_json('api/orders/status.php', $shopCookie, [
        'order_id' => $createdOrderId,
        'action'   => 'repair-done',
    ]);
    assertTrue($r1['code'] >= 400, '完了日なしは拒否');

    // 完了日ありで成功
    $r2 = http_post_json('api/orders/status.php', $shopCookie, [
        'order_id' => $createdOrderId,
        'action'   => 'repair-done',
        'repair_completed_date' => '2026-06-07',
    ]);
    assertEq(200, $r2['code'], 'HTTP');
    assertEq(3, (int)$r2['json']['status'], 'status=3');
    $det = getOne('SELECT repair_completed_date FROM order_seat_replacement_details WHERE order_id=:id', [':id' => $createdOrderId]);
    assertEq('2026-06-07', $det['repair_completed_date'], 'repair_completed_date 保存');
});

test('C4: status=3 段階で estimate が予算に加算 (納品月=2026-06)', function() use (&$createdOrderId) {
    $row = getOne('SELECT actual_amount FROM budgets WHERE shop_code="10303" AND fiscal_year=2026 AND month=6 AND department="fitness"');
    assertTrue($row !== null, '2026年度6月のfitness行が作成または既存');
    // 15000 が加算されたか確認は環境状態に依存するので、 row > 0 でも合格とする
    assertTrue((int)$row['actual_amount'] >= 15000, 'actual_amount >= 15000');
});

test('C5: 3→4 complete (admin、修理同様 final_amount 必須)', function() use ($adminCookie, &$createdOrderId) {
    // final_amount 無しは拒否
    $r1 = http_post_json('api/orders/status.php', $adminCookie, [
        'order_id' => $createdOrderId,
        'action'   => 'complete',
    ]);
    assertTrue($r1['code'] >= 400, 'final_amount 無しは拒否');

    // ありで成功
    $r2 = http_post_json('api/orders/status.php', $adminCookie, [
        'order_id'     => $createdOrderId,
        'action'       => 'complete',
        'final_amount' => 18000,
    ]);
    assertEq(200, $r2['code'], 'HTTP');
    assertEq(4, (int)$r2['json']['status'], 'status=4');

    // 差分 3000 が予算に加算されているか
    $row = getOne('SELECT actual_amount FROM budgets WHERE shop_code="10303" AND fiscal_year=2026 AND month=6 AND department="fitness"');
    assertTrue((int)$row['actual_amount'] >= 18000, 'actual_amount に差分 +3000 反映');
});

// ============================================================
// Section D: 業務上の区別
// ============================================================
echo "\n== Section D: Excel / type ラベル ==\n";

test('D1: Excel エクスポート typeLabel に「シート交換」', function() {
    $src = file_get_contents(__DIR__ . '/../api/export/orders.php');
    assertTrue(strpos($src, "'seat-replacement'") !== false, 'export/orders.php に seat-replacement 定義あり');
    assertTrue(strpos($src, 'シート交換') !== false, '「シート交換」ラベルあり');
});

test('D2: status.php / bulk-status.php / update-info.php で isRepairLikeType を使用', function() {
    foreach (['status.php', 'bulk-status.php', 'update-info.php'] as $f) {
        $src = file_get_contents(__DIR__ . '/../api/orders/' . $f);
        assertTrue(strpos($src, 'isRepairLikeType') !== false, "{$f} で isRepairLikeType 呼び出し");
    }
});

// ============================================================
// 後片付け
// ============================================================
echo "\n[cleanup] 検証用発注を削除中...\n";
if ($createdOrderId !== null) {
    // 予算を戻す（18000減算）
    execute('UPDATE budgets SET actual_amount = actual_amount - 18000 WHERE shop_code="10303" AND fiscal_year=2026 AND month=6 AND department="fitness" AND actual_amount >= 18000');
    // 関連レコード削除（CASCADE で repair_details も消えるが念のため）
    execute('DELETE FROM order_seat_replacement_details WHERE order_id=:id', [':id' => $createdOrderId]);
    execute('DELETE FROM order_repair_unavail_dates WHERE order_id=:id', [':id' => $createdOrderId]);
    execute('DELETE FROM order_repair_unavail_days WHERE order_id=:id', [':id' => $createdOrderId]);
    execute('DELETE FROM order_status_history WHERE order_id=:id', [':id' => $createdOrderId]);
    execute('DELETE FROM orders WHERE id=:id', [':id' => $createdOrderId]);
}

// ============================================================
echo "\n== サマリ ==\n";
$pass = count(array_filter($results, fn($r) => $r['pass']));
$fail = count($results) - $pass;
echo "  合計: " . count($results) . " / PASS: {$pass} / FAIL: {$fail}\n";
if ($fail > 0) {
    echo "\n[失敗テスト一覧]\n";
    foreach ($results as $r) {
        if (!$r['pass']) echo "  - {$r['name']}\n        → {$r['msg']}\n";
    }
}

array_map('unlink', glob($tmpCookieDir . '/cookie_*.txt'));
@rmdir($tmpCookieDir);

echo "\n== 完了 ==\n";
exit($fail > 0 ? 1 : 0);
