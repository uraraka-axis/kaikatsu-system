<?php declare(strict_types=1);

/**
 * 2026-05-28 追加機能の API レベル自動検証スクリプト
 *
 * 検証対象:
 *   A. Phase 1 - 備品単価編集 (admin/system が status=1〜3 で編集可能、estimate_amount 自動再計算)
 *   B. Phase 1 - Excel 列改修 (会社商品コード/仕入先商品コード、見積金額列削除)
 *   C. 論理削除 (cancel.php、cancelled_at フィルタ)
 *   D. Phase 2 - 予算実績の納品月ベース計上
 *   E. ステータス自動遷移バッチ廃止 + to-delivering 備品対応
 *
 * 使い方:
 *   "C:/xampp/php/php.exe" tools/verify_2026_05_28.php
 *
 * 注意:
 *   - DB を変更するテストは元の状態に戻す（rollback）
 *   - localhost (http://localhost/kaikatsu-system/) で Apache 稼働中であること
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

    if ($code !== 200) {
        throw new RuntimeException("login failed for {$loginId}: HTTP {$code}, body={$resp}");
    }
    return $cookieFile;
}

function http_request(string $method, string $url, string $cookieFile, ?array $body = null): array
{
    global $BASE_URL;
    $ch = curl_init($BASE_URL . ltrim($url, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $json = null;
    if (strpos($contentType, 'application/json') !== false) {
        $json = json_decode($resp, true);
    }
    return ['code' => $code, 'body' => $resp, 'json' => $json, 'content_type' => $contentType];
}

// ============================================================
// Test runner
// ============================================================
$results = [];

function test(string $name, callable $fn): void
{
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

function assertEq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $label): void
{
    if (!$cond) throw new RuntimeException($label);
}

// ============================================================
// Login
// ============================================================
echo "== 2026-05-28 機能検証 開始 ==\n\n";

echo "[setup] ログイン中...\n";
$adminCookie  = http_login('admin', 'password');
$systemCookie = http_login('system', 'password');
$shopCookie   = http_login('10303', 'password');
echo "[setup] OK\n\n";

// ============================================================
// Section A: Phase 1 - 単価編集
// ============================================================
echo "== Section A: 備品単価編集 ==\n";

// 編集用フィクスチャ: status=1 の備品発注
$fixA = getOne(
    "SELECT id, shop_code, estimate_amount FROM orders
     WHERE type='equipment' AND status=1 AND cancelled_at IS NULL ORDER BY id LIMIT 1"
);
$fixAItems = query(
    'SELECT id, price, qty FROM order_equipment_items WHERE order_id = :oid ORDER BY id',
    [':oid' => $fixA['id']]
);
$origEstimateA = (int)$fixA['estimate_amount'];
$origPrices    = array_column($fixAItems, 'price', 'id');

test('A1: GET /api/orders.php で equip_items に id プロパティ含まれる', function() use ($adminCookie, $fixA) {
    $r = http_request('GET', 'api/orders.php', $adminCookie);
    assertEq(200, $r['code'], 'HTTP');
    $found = null;
    foreach ($r['json']['data'] ?? [] as $o) {
        if ($o['id'] === $fixA['id']) { $found = $o; break; }
    }
    assertTrue($found !== null, '対象発注 ' . $fixA['id'] . ' がレスポンスに含まれる');
    assertTrue(!empty($found['equip_items']), 'equip_items が存在');
    assertTrue(isset($found['equip_items'][0]['id']), 'equip_items[0].id が含まれる');
});

test('A2: items[] 単価更新 → estimate_amount が Σ(price×qty) で再計算', function() use ($adminCookie, $fixA, $fixAItems) {
    // 1つの明細を +1000 円増額
    $target = $fixAItems[0];
    $newPrice = (int)$target['price'] + 1000;
    $r = http_request('POST', 'api/orders/update-info.php', $adminCookie, [
        'order_id' => $fixA['id'],
        'items'    => [['id' => (int)$target['id'], 'price' => $newPrice]],
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertTrue(!empty($r['json']['success']), 'success=true');

    // DB 確認
    $sum = 0;
    foreach ($fixAItems as $it) {
        $p = ($it['id'] === $target['id']) ? $newPrice : (int)$it['price'];
        $sum += $p * (int)$it['qty'];
    }
    $after = getOne('SELECT estimate_amount FROM orders WHERE id=:id', [':id' => $fixA['id']]);
    assertEq($sum, (int)$after['estimate_amount'], 'estimate_amount が Σ(price×qty)');
});

test('A3: 他発注の item ID を混ぜると拒否される', function() use ($adminCookie, $fixA) {
    // 別発注の item ID を取得
    $otherItem = getOne(
        "SELECT i.id FROM order_equipment_items i
         JOIN orders o ON o.id = i.order_id
         WHERE o.id <> :oid AND o.status >= 1 AND o.cancelled_at IS NULL LIMIT 1",
        [':oid' => $fixA['id']]
    );
    $r = http_request('POST', 'api/orders/update-info.php', $adminCookie, [
        'order_id' => $fixA['id'],
        'items'    => [['id' => (int)$otherItem['id'], 'price' => 99999]],
    ]);
    assertEq(500, $r['code'], '所有チェック違反は 500 (catch)');
    // ※エラー本文を要確認: 「明細ID ... はこの発注に属していません」
});

test('A4: status=0 で items[] 編集は拒否される', function() use ($adminCookie) {
    $fix = getOne("SELECT id FROM orders WHERE type='equipment' AND status=0 AND cancelled_at IS NULL LIMIT 1");
    $item = getOne('SELECT id FROM order_equipment_items WHERE order_id=:o LIMIT 1', [':o' => $fix['id']]);
    $r = http_request('POST', 'api/orders/update-info.php', $adminCookie, [
        'order_id' => $fix['id'],
        'items'    => [['id' => (int)$item['id'], 'price' => 9999]],
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答 (status=0 は編集不可)');
});

test('A5: status=4 で items[] 編集は拒否される', function() use ($adminCookie) {
    $fix = getOne("SELECT id FROM orders WHERE type='equipment' AND status=4 AND cancelled_at IS NULL LIMIT 1");
    if ($fix === null) { return; /* スキップ */ }
    $item = getOne('SELECT id FROM order_equipment_items WHERE order_id=:o LIMIT 1', [':o' => $fix['id']]);
    if ($item === null) { return; }
    $r = http_request('POST', 'api/orders/update-info.php', $adminCookie, [
        'order_id' => $fix['id'],
        'items'    => [['id' => (int)$item['id'], 'price' => 9999]],
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答 (status=4 は編集不可)');
});

// クリーンアップ: A1-A2 で変更した状態を元に戻す
foreach ($origPrices as $itemId => $origPrice) {
    execute('UPDATE order_equipment_items SET price = :p WHERE id = :id',
        [':p' => $origPrice, ':id' => $itemId]);
}
execute('UPDATE orders SET estimate_amount = :e WHERE id = :id',
    [':e' => $origEstimateA, ':id' => $fixA['id']]);

// ============================================================
// Section B: Excel 列改修
// ============================================================
echo "\n== Section B: Excel 列改修 ==\n";

test('B1: GET /api/export/orders.php で XLSX が返る (admin)', function() use ($adminCookie) {
    $r = http_request('GET', 'api/export/orders.php', $adminCookie);
    assertEq(200, $r['code'], 'HTTP');
    assertTrue(strpos($r['content_type'], 'spreadsheet') !== false || strpos($r['content_type'], 'excel') !== false,
        'Content-Type が spreadsheet/excel: 実際=' . $r['content_type']);
});

test('B2: 列ヘッダーに「会社商品コード」「仕入先商品コード」あり、「見積金額」なし', function() {
    // $headers 配列の中身だけを抽出（コメント等は除外）
    $src = file_get_contents(__DIR__ . '/../api/export/orders.php');
    if (!preg_match('/\$headers\s*=\s*\[(.*?)\];/s', $src, $m)) {
        throw new RuntimeException('$headers 配列が見つからない');
    }
    $headersBlock = $m[1];
    assertTrue(strpos($headersBlock, '会社商品コード') !== false, '「会社商品コード」ヘッダー定義');
    assertTrue(strpos($headersBlock, '仕入先商品コード') !== false, '「仕入先商品コード」ヘッダー定義');
    assertTrue(strpos($headersBlock, '見積金額') === false, '「見積金額」列がヘッダーから削除されている');
});

// ============================================================
// Section C: 論理削除
// ============================================================
echo "\n== Section C: 論理削除 (取消) ==\n";

// 取消テスト用に status=0 の備品を確保
$fixC = getOne("SELECT id FROM orders WHERE type='equipment' AND status=0 AND cancelled_at IS NULL ORDER BY id DESC LIMIT 1");
$cancelTargetId = $fixC['id'];

test('C1: admin で cancel.php (status=0) → 成功、cancelled_at セット、履歴に【取消】', function() use ($adminCookie, $cancelTargetId) {
    $r = http_request('POST', 'api/orders/cancel.php', $adminCookie, [
        'order_id'      => $cancelTargetId,
        'cancel_reason' => '検証スクリプトによる取消',
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertTrue(!empty($r['json']['success']), 'success=true');

    $row = getOne('SELECT cancelled_at, cancelled_by, cancel_reason FROM orders WHERE id=:id', [':id' => $cancelTargetId]);
    assertTrue($row['cancelled_at'] !== null, 'cancelled_at セット');
    assertTrue($row['cancelled_by'] !== null && $row['cancelled_by'] !== '', 'cancelled_by セット');
    assertEq('検証スクリプトによる取消', $row['cancel_reason'], 'cancel_reason 保存');

    $hist = getOne(
        'SELECT memo FROM order_status_history WHERE order_id=:o ORDER BY id DESC LIMIT 1',
        [':o' => $cancelTargetId]
    );
    assertTrue(strpos($hist['memo'] ?? '', '【取消】') === 0, '履歴 memo が【取消】で始まる');
});

test('C2: 取消発注は GET /api/orders.php に含まれない', function() use ($adminCookie, $cancelTargetId) {
    $r = http_request('GET', 'api/orders.php', $adminCookie);
    $ids = array_column($r['json']['data'] ?? [], 'id');
    assertTrue(!in_array($cancelTargetId, $ids, true), '取消発注がレスポンスに含まれない');
});

test('C3: 取消発注のステータス変更は拒否', function() use ($adminCookie, $cancelTargetId) {
    $r = http_request('POST', 'api/orders/status.php', $adminCookie, [
        'order_id' => $cancelTargetId,
        'action'   => 'order',
        'estimate_amount' => 5000,
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答');
});

test('C4: 取消発注の update-info も拒否', function() use ($adminCookie, $cancelTargetId) {
    $r = http_request('POST', 'api/orders/update-info.php', $adminCookie, [
        'order_id' => $cancelTargetId,
        'memo'     => 'test',
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答');
});

test('C5: cancel.php (status>=1) は拒否', function() use ($adminCookie) {
    $fix = getOne("SELECT id FROM orders WHERE status>=1 AND cancelled_at IS NULL LIMIT 1");
    $r = http_request('POST', 'api/orders/cancel.php', $adminCookie, [
        'order_id'      => $fix['id'],
        'cancel_reason' => 'should fail',
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答');
});

test('C6: shop ロールの cancel.php は 403', function() use ($shopCookie) {
    $fix = getOne("SELECT id FROM orders WHERE status=0 AND cancelled_at IS NULL LIMIT 1");
    $r = http_request('POST', 'api/orders/cancel.php', $shopCookie, [
        'order_id'      => $fix['id'],
        'cancel_reason' => 'should fail',
    ]);
    assertEq(403, $r['code'], 'HTTP 403');
});

test('C7: 取消理由なし → 拒否', function() use ($adminCookie) {
    $fix = getOne("SELECT id FROM orders WHERE status=0 AND cancelled_at IS NULL LIMIT 1");
    $r = http_request('POST', 'api/orders/cancel.php', $adminCookie, [
        'order_id' => $fix['id'],
    ]);
    assertTrue($r['code'] >= 400, 'エラー応答');
});

// クリーンアップ: C1 で取消した発注を復元
execute('UPDATE orders SET cancelled_at = NULL, cancelled_by = NULL, cancel_reason = NULL WHERE id = :id',
    [':id' => $cancelTargetId]);
execute('DELETE FROM order_status_history WHERE order_id = :o AND memo LIKE :m',
    [':o' => $cancelTargetId, ':m' => '【取消】%']);

// ============================================================
// Section D: Phase 2 - 納品月ベース予算
// ============================================================
echo "\n== Section D: 予算実績の納品月ベース計上 ==\n";

test('D1: delivery-done で actual_delivery_date 未指定 → 400', function() use ($shopCookie) {
    // 自店(10303)の status=2 備品/部品 を探す
    $fix = getOne(
        "SELECT id FROM orders WHERE type='equipment' AND status=2 AND shop_code='10303' AND cancelled_at IS NULL LIMIT 1"
    );
    if ($fix === null) {
        // 部品でも試す
        $fix = getOne("SELECT id FROM orders WHERE type='parts' AND status=2 AND shop_code='10303' AND cancelled_at IS NULL LIMIT 1");
    }
    if ($fix === null) { throw new RuntimeException('テスト用 status=2 発注が10303に無い'); }

    $r = http_request('POST', 'api/orders/status.php', $shopCookie, [
        'order_id' => $fix['id'],
        'action'   => 'delivery-done',
        // actual_delivery_date 未指定
    ]);
    assertEq(400, $r['code'], 'HTTP 400');
    assertTrue(strpos($r['json']['error'] ?? '', '納品実績日は必須') !== false, 'エラーメッセージに「納品実績日は必須」');
});

// D2-D4 用のフィクスチャ: 自前で備品発注を1件作る (検証クリーンアップを保証するため)
// 既存発注を流用すると元の estimate/status を戻すロジックが複雑なため、シンプルに既存を直接操作 + ロールバック方式にする
$fixD = getOne(
    "SELECT id, shop_code, category_code, type, estimate_amount FROM orders
     WHERE type='equipment' AND status=2 AND shop_code='10303' AND cancelled_at IS NULL LIMIT 1"
);
$origStatusD = 2;
$testDeliveryDate = '2026-04-15'; // 2026年度・4月計上
$budgetKeyD = [
    'shop_code'   => $fixD['shop_code'],
    'fiscal_year' => 2026,
    'month'       => 4,
    'department'  => $fixD['category_code'],
];
$estD = (int)$fixD['estimate_amount'];

$budgetBeforeD = getOne(
    'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
    [':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':d' => $budgetKeyD['department']]
);
$beforeActualD = (int)($budgetBeforeD['actual_amount'] ?? 0);

test('D2: 2→3 delivery-done で budgets.actual_amount += estimate (納品月)', function() use ($shopCookie, $fixD, $testDeliveryDate, $estD, $budgetKeyD, $beforeActualD) {
    $r = http_request('POST', 'api/orders/status.php', $shopCookie, [
        'order_id'             => $fixD['id'],
        'action'               => 'delivery-done',
        'actual_delivery_date' => $testDeliveryDate,
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertTrue(!empty($r['json']['success']), 'success');
    assertEq(3, (int)$r['json']['status'], 'status=3');

    $row = getOne(
        'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
        [':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':d' => $budgetKeyD['department']]
    );
    $afterActual = (int)($row['actual_amount'] ?? 0);
    assertEq($beforeActualD + $estD, $afterActual, "actual_amount が +{$estD} 加算");
});

test('D3: 3→4 complete (final=estimate+5000) で差分が同月加算', function() use ($adminCookie, $fixD, $estD, $budgetKeyD, $beforeActualD) {
    $newFinal = $estD + 5000;
    $r = http_request('POST', 'api/orders/status.php', $adminCookie, [
        'order_id'     => $fixD['id'],
        'action'       => 'complete',
        'final_amount' => $newFinal,
    ]);
    assertEq(200, $r['code'], 'HTTP');
    assertEq(4, (int)$r['json']['status'], 'status=4');

    $row = getOne(
        'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
        [':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':d' => $budgetKeyD['department']]
    );
    // D2 で +estD 済み、D3 で更に +5000
    assertEq($beforeActualD + $estD + 5000, (int)($row['actual_amount'] ?? 0), 'actual_amount が +5000 追加');
});

test('D4: status=4 で update-info の final_amount 変更 → 差分加算', function() use ($systemCookie, $fixD, $estD, $budgetKeyD, $beforeActualD) {
    // 現在 final = estD + 5000、これを estD + 5000 - 3000 = estD + 2000 に変更 → 差分 -3000
    $newFinal = $estD + 2000;
    $r = http_request('POST', 'api/orders/update-info.php', $systemCookie, [
        'order_id'     => $fixD['id'],
        'final_amount' => $newFinal,
    ]);
    assertEq(200, $r['code'], 'HTTP');

    $row = getOne(
        'SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
        [':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':d' => $budgetKeyD['department']]
    );
    assertEq($beforeActualD + $estD + 2000, (int)($row['actual_amount'] ?? 0), 'actual_amount が -3000 (差分)');
});

// クリーンアップ: D2-D4 で変更した発注を元に戻す
execute('UPDATE orders SET status=:s, actual_delivery_date=NULL, final_amount=NULL WHERE id=:id',
    [':s' => $origStatusD, ':id' => $fixD['id']]);
// budgets を元に戻す (delta = -(estD + 2000))
$totalDelta = $estD + 2000;
$exists = getOne('SELECT actual_amount FROM budgets WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:d',
    [':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':d' => $budgetKeyD['department']]);
if ($exists !== null) {
    execute('UPDATE budgets SET actual_amount = actual_amount - :d WHERE shop_code=:s AND fiscal_year=:y AND month=:m AND department=:dep',
        [':d' => $totalDelta, ':s' => $budgetKeyD['shop_code'], ':y' => $budgetKeyD['fiscal_year'], ':m' => $budgetKeyD['month'], ':dep' => $budgetKeyD['department']]);
}
// D2-D3 で order_status_history に 2件追加されたので、status=3 と 4 の最新行を削除
// （ロール名は user.name 依存で不安定なため id 範囲で確実に消す）
$toDelete = query(
    "SELECT id FROM order_status_history WHERE order_id=:o AND status IN (3, 4) ORDER BY id DESC LIMIT 2",
    [':o' => $fixD['id']]
);
foreach ($toDelete as $row) {
    execute('DELETE FROM order_status_history WHERE id = :id', [':id' => (int)$row['id']]);
}

// ============================================================
// Section E: バッチ廃止 + 手動化
// ============================================================
echo "\n== Section E: ステータス自動遷移バッチ廃止 + to-delivering 備品対応 ==\n";

test('E1: setup/auto_advance_status.php が存在しない', function() {
    $exists = file_exists(__DIR__ . '/../setup/auto_advance_status.php');
    assertTrue(!$exists, 'ファイル削除済み');
});

test('E2: to-delivering で備品が許可される (status.php)', function() use ($adminCookie) {
    // 備品 status=1 発注を選び 1→2 を試す
    $fix = getOne("SELECT id FROM orders WHERE type='equipment' AND status=1 AND cancelled_at IS NULL LIMIT 1");
    $origStatus = 1;
    $r = http_request('POST', 'api/orders/status.php', $adminCookie, [
        'order_id' => $fix['id'],
        'action'   => 'to-delivering',
    ]);
    // クリーンアップしてから assert
    execute('UPDATE orders SET status=:s WHERE id=:id', [':s' => $origStatus, ':id' => $fix['id']]);
    $toDel = query("SELECT id FROM order_status_history WHERE order_id=:o AND status=2 ORDER BY id DESC LIMIT 1", [':o' => $fix['id']]);
    foreach ($toDel as $row) { execute('DELETE FROM order_status_history WHERE id=:id', [':id' => (int)$row['id']]); }

    assertEq(200, $r['code'], 'HTTP 200');
    assertTrue(!empty($r['json']['success']), 'success=true');
});

test('E3: to-delivering 一括変更で備品も処理対象に含まれる (bulk-status.php)', function() use ($adminCookie) {
    $fix = getOne("SELECT id FROM orders WHERE type='equipment' AND status=1 AND cancelled_at IS NULL LIMIT 1");
    $origStatus = 1;
    $r = http_request('POST', 'api/orders/bulk-status.php', $adminCookie, [
        'order_ids' => [$fix['id']],
        'action'    => 'to-delivering',
    ]);
    // クリーンアップ
    execute('UPDATE orders SET status=:s WHERE id=:id', [':s' => $origStatus, ':id' => $fix['id']]);
    $toDel = query("SELECT id FROM order_status_history WHERE order_id=:o AND status=2 ORDER BY id DESC LIMIT 1", [':o' => $fix['id']]);
    foreach ($toDel as $row) { execute('DELETE FROM order_status_history WHERE id=:id', [':id' => (int)$row['id']]); }

    assertEq(200, $r['code'], 'HTTP 200');
    $processed = $r['json']['processed'] ?? [];
    assertTrue(in_array($fix['id'], $processed, true), '備品が processed に含まれる');
});

// ============================================================
// サマリ
// ============================================================
echo "\n== サマリ ==\n";
$pass = count(array_filter($results, fn($r) => $r['pass']));
$fail = count($results) - $pass;
echo "  合計: " . count($results) . " / PASS: {$pass} / FAIL: {$fail}\n";
if ($fail > 0) {
    echo "\n[失敗テスト一覧]\n";
    foreach ($results as $r) {
        if (!$r['pass']) {
            echo "  - {$r['name']}\n        → {$r['msg']}\n";
        }
    }
}

// 後片付け: cookies 削除
array_map('unlink', glob($tmpCookieDir . '/cookie_*.txt'));
@rmdir($tmpCookieDir);

echo "\n== 完了 ==\n";
exit($fail > 0 ? 1 : 0);
