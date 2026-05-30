<?php declare(strict_types=1);

/**
 * 予算超過見込み通知の網羅テストスクリプト（2026-05-30 改訂）
 *
 * 検証対象:
 *   1. includes/budget_notify.php :: quarterBudgetCrossedUpward()  … 純関数（DB不要）
 *   2. includes/budget.php        :: getInflightPipelineTotal()    … トランザクション内で検証→rollback
 *
 * 旧仕様（納品/完了時の確定メール notifyIfQuarterBudgetCrossed）は廃止し、
 * マネージャー通知は「店舗の備品発注時（status=0）の仮計上クロス」に一本化した。
 * 本テストはその新ロジックの中核（クロス判定＋未納品見込み集計）を検証する。
 *
 * 使い方:
 *   cd C:\Users\ssasa\kaikatsu-system
 *   php tools/test_budget_notify.php
 *
 * DB安全性:
 *   - Part 1 は純関数のため DB を触らない。
 *   - Part 2 は beginTransaction() → INSERT → 検証 → rollback() で、一切永続化しない。
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/budget.php';
require_once __DIR__ . '/../includes/budget_notify.php';

date_default_timezone_set('Asia/Tokyo');

function colorize(string $s, string $color): string {
    $codes = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    return ($codes[$color] ?? '') . $s . $codes['reset'];
}

$results = [];
function record(array &$results, string $name, bool $pass, string $detail = ''): void {
    $results[] = ['name' => $name, 'pass' => $pass];
    $mark = $pass ? colorize('PASS', 'green') : colorize('FAIL', 'red');
    echo "  {$mark}  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

// ─────────────────────────────────────────
// Part 1: quarterBudgetCrossedUpward() 純関数テスト
//   送信条件 = before <= budget かつ after > budget（budget ちょうどは超過に含めない）
// ─────────────────────────────────────────
echo colorize("=== Part 1: quarterBudgetCrossedUpward()（予算 ¥30,000 基準）===\n", 'yellow');

$BUD = 30000;
$predCases = [
    // [before, after, budget, expect]
    ['1. 予算内→超過 (25,000→35,000)',      25000, 35000, $BUD, true],
    ['2. 既超過でさらに加算 (40,000→45,000)', 40000, 45000, $BUD, false],
    ['3. 加算しても予算内 (20,000→25,000)',   20000, 25000, $BUD, false],
    ['4. before=after（変化なし・予算内）',    20000, 20000, $BUD, false],
    ['5. 減算（35,000→20,000）',             35000, 20000, $BUD, false],
    ['6. ぴったり予算 (20,000→30,000)',       20000, 30000, $BUD, false], // =budget は超過ではない
    ['7. 1円超え (30,000→30,001)',           30000, 30001, $BUD, true],
    ['8. before>budget で after も超過',       31000, 32000, $BUD, false],
];
foreach ($predCases as $c) {
    [$name, $before, $after, $budget, $expect] = $c;
    $actual = quarterBudgetCrossedUpward($before, $after, $budget);
    record($results, $name, $actual === $expect, "expect=" . var_export($expect, true) . " actual=" . var_export($actual, true));
}

// ─────────────────────────────────────────
// Part 2: getInflightPipelineTotal() トランザクション内テスト
//   shop=10301(新宿東口), category=fitness, fy=2026, month=5(Q1=4-6月)
//   既存データに依存しないよう「baseline → INSERT → after」の差分で検証する。
// ─────────────────────────────────────────
echo colorize("\n=== Part 2: getInflightPipelineTotal()（shop=10301, fitness, fy=2026, Q1）===\n", 'yellow');

const T_SHOP  = '10301';
const T_CAT   = 'fitness';
const T_FY    = 2026;
const T_MONTH = 5; // Q1

// 前提: 対象店舗・カテゴリが存在すること
$shopOk = getOne('SELECT code FROM shops WHERE code = :c', [':c' => T_SHOP]);
$catOk  = getOne('SELECT code FROM categories WHERE code = :c', [':c' => T_CAT]);
if (!$shopOk || !$catOk) {
    echo colorize("[SKIP] shop=" . T_SHOP . " / category=" . T_CAT . " が存在しないため Part 2 をスキップ\n", 'red');
} else {
    // 投入する未納品オーダー（差分で 5000 + 8000 + 4000 = 17000 を期待）
    //   B: repair      status=1 estimate=5000 date=2026-05-10            → Q1 計上 (5000)
    //   C: equipment   status=0 estimate=8000 date=2026-06-20            → Q1 計上 (8000)
    //   H: equipment   status=1 estimate=4000 date=2026-08-01 納品予定=2026-06-25 → delivery_date優先で Q1 (4000)
    //   A: parts       status=0 estimate=NULL date=2026-05-10            → 金額0 (0)
    //   E: repair      status=3 estimate=9000 date=2026-05-10            → 除外（status=3）
    //   F: equipment   status=0 estimate=7000 date=2026-05-10 取消済      → 除外（cancelled）
    //   G: equipment   status=1 estimate=6000 date=2026-08-10            → 除外（Q2）
    $EXPECTED_DELTA = 17000;

    $rows = [
        ['T-INF-B', 'repair',    1, 5000, '2026-05-10', null,         null],
        ['T-INF-C', 'equipment', 0, 8000, '2026-06-20', null,         null],
        ['T-INF-H', 'equipment', 1, 4000, '2026-08-01', '2026-06-25', null],
        ['T-INF-A', 'parts',     0, null, '2026-05-10', null,         null],
        ['T-INF-E', 'repair',    3, 9000, '2026-05-10', null,         null],
        ['T-INF-F', 'equipment', 0, 7000, '2026-05-10', null,         'cancel'],
        ['T-INF-G', 'equipment', 1, 6000, '2026-08-10', null,         null],
    ];

    $inserted = false;
    try {
        beginTransaction();

        $baseline = getInflightPipelineTotal(T_SHOP, T_CAT, T_FY, T_MONTH);

        foreach ($rows as $r) {
            [$id, $type, $status, $estimate, $date, $deliveryDate, $flag] = $r;
            execute(
                "INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, delivery_date, cancelled_at)
                 VALUES (:id, :type, :cat, :st, :shop, :date, :est, :dd, :cancelled)",
                [
                    ':id'        => $id,
                    ':type'      => $type,
                    ':cat'       => T_CAT,
                    ':st'        => $status,
                    ':shop'      => T_SHOP,
                    ':date'      => $date,
                    ':est'       => $estimate,
                    ':dd'        => $deliveryDate,
                    ':cancelled' => $flag === 'cancel' ? date('Y-m-d H:i:s') : null,
                ]
            );
        }
        $inserted = true;

        $after = getInflightPipelineTotal(T_SHOP, T_CAT, T_FY, T_MONTH);
        $delta = $after - $baseline;

        record($results, 'Part2. 見込み集計の差分が期待値と一致', $delta === $EXPECTED_DELTA,
            "baseline={$baseline} after={$after} delta={$delta} expected={$EXPECTED_DELTA}");

    } catch (Throwable $e) {
        record($results, 'Part2. 見込み集計（例外発生）', false, $e->getMessage());
    } finally {
        // 何があっても rollback（永続化しない）
        try { rollback(); } catch (Throwable $e) { /* no active tx */ }
    }
    echo "  （投入データは rollback 済み・DB は不変）\n";
}

// ─────────────────────────────────────────
// サマリー
// ─────────────────────────────────────────
$total  = count($results);
$passed = count(array_filter($results, fn($r) => $r['pass']));
$failed = $total - $passed;

echo colorize("\n=== サマリー ===\n", 'yellow');
echo "総数: {$total}, " . colorize("PASS: {$passed}", 'green') . ", " . colorize("FAIL: {$failed}", $failed > 0 ? 'red' : 'green') . "\n";

if ($failed > 0) {
    echo colorize("\n失敗:\n", 'red');
    foreach ($results as $r) {
        if (!$r['pass']) echo "  - {$r['name']}\n";
    }
}

exit($failed > 0 ? 1 : 0);
