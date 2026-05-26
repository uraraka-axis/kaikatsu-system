<?php declare(strict_types=1);

/**
 * 予算超過通知の網羅テストスクリプト
 *
 * 検証対象: includes/budget_notify.php :: notifyIfQuarterBudgetCrossed()
 *
 * 使い方:
 *   cd C:\Users\ssasa\kaikatsu-system
 *   php tools/test_budget_notify.php
 *
 * 動作:
 *   - MAIL_LOG_ONLY を強制 ON にして logs/mail.log にメール内容を吐き出す
 *   - テスト対象の店舗・部門の budget/actual を一時的にテスト用値に書き換える
 *   - notifyIfQuarterBudgetCrossed() を各シナリオで呼び出し、期待通り送信される/されないか判定
 *   - 終了時に DB を元の状態に復元（finally で必ず実行）
 *
 * テスト対象:
 *   shop_code = '10301' (新宿東口), fiscal_year = 2026, department = 'fitness', Q1 (4-6月)
 *   ※ 既存データは退避してから書き換える
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

// MAIL_LOG_ONLY を強制 ON（config.php で上書きされない）
define('MAIL_LOG_ONLY', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/budget.php';
require_once __DIR__ . '/../includes/budget_notify.php';
require_once __DIR__ . '/../includes/mailer.php';

date_default_timezone_set('Asia/Tokyo');

// ─────────────────────────────────────────
// テスト対象 & 共通定数
// ─────────────────────────────────────────
const TEST_SHOP   = '10301';
const TEST_FY     = 2026;
const TEST_DEPT   = 'fitness';
const TEST_DATE   = '2026-05-15'; // → 計上月 6 (fitness は monthly closing_day=8、5/15 は超過なので翌月計上) → Q1
const TEST_QUARTER_MONTHS = [4, 5, 6];
const TEST_BUDGET_PER_MONTH = 10000;   // 四半期合計 ¥30,000
const TEST_QUARTER_BUDGET   = 30000;

$logFile = __DIR__ . '/../logs/mail.log';

// ─────────────────────────────────────────
// ヘルパ
// ─────────────────────────────────────────

function colorize(string $s, string $color): string {
    $codes = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'reset' => "\033[0m"];
    return ($codes[$color] ?? '') . $s . $codes['reset'];
}

function snapshotBudget(): array {
    $rows = query(
        "SELECT month, budget_amount, actual_amount
         FROM budgets
         WHERE shop_code = :s AND fiscal_year = :y AND department = :d
           AND month IN (4, 5, 6)",
        [':s' => TEST_SHOP, ':y' => TEST_FY, ':d' => TEST_DEPT]
    );
    return $rows;
}

function snapshotUserEmails(): array {
    $row = getOne(
        "SELECT id, zone_manager_email, area_manager_email
         FROM users WHERE shop_code = :s AND role = 'shop' AND is_active = 1 LIMIT 1",
        [':s' => TEST_SHOP]
    );
    return $row ?: [];
}

function restoreBudget(array $backup): void {
    foreach ($backup as $row) {
        execute(
            "UPDATE budgets SET budget_amount = :b, actual_amount = :a
             WHERE shop_code = :s AND fiscal_year = :y AND department = :d AND month = :m",
            [
                ':b' => (int)$row['budget_amount'],
                ':a' => (int)$row['actual_amount'],
                ':s' => TEST_SHOP, ':y' => TEST_FY, ':d' => TEST_DEPT,
                ':m' => (int)$row['month'],
            ]
        );
    }
}

function restoreUserEmails(array $backup): void {
    if (empty($backup)) return;
    execute(
        "UPDATE users SET zone_manager_email = :z, area_manager_email = :a WHERE id = :id",
        [
            ':z' => $backup['zone_manager_email'],
            ':a' => $backup['area_manager_email'],
            ':id' => (int)$backup['id'],
        ]
    );
}

/**
 * テスト用に四半期予算と実績をセット。全月で $budgetPerMonth、$actual は最初の月にまとめて入れる。
 */
function setQuarterBudgetAndActual(int $budgetPerMonth, int $totalActual): void {
    foreach (TEST_QUARTER_MONTHS as $i => $m) {
        $actual = $i === 0 ? $totalActual : 0;
        execute(
            "INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
             VALUES (:s, :y, :m, :d, :b, :a)
             ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount), actual_amount = VALUES(actual_amount)",
            [
                ':s' => TEST_SHOP, ':y' => TEST_FY, ':m' => $m, ':d' => TEST_DEPT,
                ':b' => $budgetPerMonth, ':a' => $actual,
            ]
        );
    }
}

function setUserEmails(?string $zone, ?string $area): void {
    execute(
        "UPDATE users SET zone_manager_email = :z, area_manager_email = :a
         WHERE shop_code = :s AND role = 'shop'",
        [':z' => $zone, ':a' => $area, ':s' => TEST_SHOP]
    );
}

function countMailLogEntries(string $logFile): int {
    if (!file_exists($logFile)) return 0;
    $content = file_get_contents($logFile);
    return substr_count($content, "\n=== ");
}

function tailMailLog(string $logFile, int $lines = 20): string {
    if (!file_exists($logFile)) return '(mail.log なし)';
    $content = file_get_contents($logFile);
    $all = explode("\n", $content);
    return implode("\n", array_slice($all, -$lines));
}

// ─────────────────────────────────────────
// テストケース定義
// ─────────────────────────────────────────

$cases = [
    [
        'name' => '1. 境界クロス: 予算内→超過 (¥25,000 → +¥10,000 = ¥35,000、予算¥30,000)',
        'setup_actual_after' => 35000,      // 加算後 actual
        'delta'              => 10000,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => true,       // メール送信される
        'note'               => 'before=25000 (内), after=35000 (超過) → 通知'
    ],
    [
        'name' => '2. 既超過状態でさらに加算 (¥40,000 → +¥5,000 = ¥45,000)',
        'setup_actual_after' => 45000,
        'delta'              => 5000,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => false,
        'note'               => 'before=40000 (既超過), after=45000 → 再送なし'
    ],
    [
        'name' => '3. 加算しても予算内のまま (¥20,000 → +¥5,000 = ¥25,000)',
        'setup_actual_after' => 25000,
        'delta'              => 5000,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => false,
        'note'               => 'before=20000, after=25000、共に予算内 → 通知なし'
    ],
    [
        'name' => '4. delta = 0',
        'setup_actual_after' => 35000,
        'delta'              => 0,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => false,
        'note'               => 'delta=0 は早期 return'
    ],
    [
        'name' => '5. delta < 0 (完了時に final < estimate のケース)',
        'setup_actual_after' => 20000,
        'delta'              => -5000,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => false,
        'note'               => 'delta<0 は減算 → 通知不要'
    ],
    [
        'name' => '6. 境界クロス + メアド未設定',
        'setup_actual_after' => 35000,
        'delta'              => 10000,
        'zone_email'         => null,
        'area_email'         => null,
        'expect_mail'        => false,
        'note'               => 'before=25000→35000 で境界クロスだが、宛先メアド無し → error_log のみ'
    ],
    [
        'name' => '7. ぴったり予算 → 超過 (¥20,000 → +¥10,000 = ¥30,000)',
        'setup_actual_after' => 30000,
        'delta'              => 10000,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => false,
        'note'               => 'before=20000, after=30000 = budget。"超過" の定義は > なので通知なし'
    ],
    [
        'name' => '8. 境界クロス (1円超え)',
        'setup_actual_after' => 30001,
        'delta'              => 1,
        'zone_email'         => 'zone-mgr@test.example.co.jp',
        'area_email'         => 'area-mgr@test.example.co.jp',
        'expect_mail'        => true,
        'note'               => 'before=30000 (=budget, ≦budget なので内), after=30001 → 通知'
    ],
    [
        'name' => '9. zone のみメアド設定 (area NULL)',
        'setup_actual_after' => 35000,
        'delta'              => 10000,
        'zone_email'         => 'zone-only@test.example.co.jp',
        'area_email'         => null,
        'expect_mail'        => true,
        'note'               => '1 件でもメアドがあれば送信'
    ],
];

// ─────────────────────────────────────────
// 実行
// ─────────────────────────────────────────

echo colorize("=== 予算超過通知 網羅テスト ===\n", 'yellow');
echo "対象: shop_code=" . TEST_SHOP . ", fy=" . TEST_FY . ", dept=" . TEST_DEPT . ", Q1\n";
echo "MAIL_LOG_ONLY = true (送信は logs/mail.log に書き出すだけ)\n\n";

// バックアップ
$budgetBackup = snapshotBudget();
$userBackup   = snapshotUserEmails();

if (empty($userBackup)) {
    echo colorize("[ERROR] テスト対象店舗 " . TEST_SHOP . " のユーザーが見つかりません\n", 'red');
    exit(1);
}

// テスト前に logs/mail.log を初期化（既存ログとの混在を避ける）
if (file_exists($logFile)) {
    unlink($logFile);
}

$results = [];

try {
    foreach ($cases as $i => $case) {
        $caseNum = $i + 1;
        echo colorize("\n--- ケース {$caseNum}: {$case['name']} ---\n", 'yellow');
        echo "  期待: " . ($case['expect_mail'] ? 'メール送信' : '送信なし') . "\n";
        echo "  メモ: {$case['note']}\n";

        // DB 状態をセット
        setQuarterBudgetAndActual(TEST_BUDGET_PER_MONTH, $case['setup_actual_after']);
        setUserEmails($case['zone_email'], $case['area_email']);

        // 実行前のログエントリ数
        $beforeCount = countMailLogEntries($logFile);

        // 実行: ダミー order を渡す
        $order = [
            'id'             => 'TEST-CASE-' . $caseNum,
            'shop_code'      => TEST_SHOP,
            'category_code'  => TEST_DEPT,
            'date'           => TEST_DATE,
        ];
        notifyIfQuarterBudgetCrossed($order, $case['delta']);

        // 実行後のログエントリ数
        $afterCount = countMailLogEntries($logFile);
        $actualSent = ($afterCount > $beforeCount);

        // 判定
        $pass = ($actualSent === $case['expect_mail']);
        if ($pass) {
            $results[] = ['case' => $caseNum, 'name' => $case['name'], 'pass' => true, 'actual' => $actualSent];
            echo "  結果: " . colorize("PASS", 'green') . " (実際: " . ($actualSent ? '送信' : '送信なし') . ")\n";
        } else {
            $results[] = ['case' => $caseNum, 'name' => $case['name'], 'pass' => false, 'actual' => $actualSent];
            echo "  結果: " . colorize("FAIL", 'red') . " (期待: " . ($case['expect_mail'] ? '送信' : '送信なし') . " / 実際: " . ($actualSent ? '送信' : '送信なし') . ")\n";
        }
    }
} finally {
    // 復元
    echo "\n--- DB を復元中 ---\n";
    restoreBudget($budgetBackup);
    restoreUserEmails($userBackup);
    echo "  復元完了\n";
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
    echo colorize("\n失敗したケース:\n", 'red');
    foreach ($results as $r) {
        if (!$r['pass']) {
            echo "  - ケース {$r['case']}: {$r['name']}\n";
        }
    }
}

echo "\n参考: 最後のメール内容 (logs/mail.log の末尾 30 行):\n";
echo "─────────────────────────────────────────\n";
echo tailMailLog($logFile, 30);
echo "\n─────────────────────────────────────────\n";

exit($failed > 0 ? 1 : 0);
