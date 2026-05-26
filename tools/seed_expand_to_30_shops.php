<?php declare(strict_types=1);

/**
 * 既存 11 店舗に 19 店舗を追加して計 30 店舗にする
 *
 * 追加内訳:
 *   北海道(101): +2  (10104釧路 / 10105帯広)
 *   東北(102)  : +3  (10203秋田 / 10204山形 / 10205福島)
 *   関東(103)  : +5  (10304渋谷 / 10305上野 / 10306千葉 / 10307さいたま / 10308川崎)
 *   関西(201)  : +5  (20103京都 / 20104神戸 / 20105奈良 / 20106和歌山 / 20107滋賀)
 *   中四国(202): +4  (20202岡山 / 20203高松 / 20204松山 / 20205徳島)
 *
 * 各店舗に以下を作成:
 *   - shops 行
 *   - shop_categories: fitness + golf 両方
 *   - users: login_id = shop_code, password = 'password', role = 'shop'
 *   - budgets: 2025/2026/2027 × 12ヶ月 × 2部門 = 72行/店舗
 *
 * 使い方:
 *   php tools/seed_expand_to_30_shops.php
 *   php tools/seed_expand_to_30_shops.php --reset   ← 追加した19店舗を削除
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$args  = $argv ?? [];
$reset = in_array('--reset', $args, true);

// [shop_code, short_code, area_code, name, sort_order, scale]
// scale: 'large'(大都市) / 'mid'(地方都市) / 'small'(郊外)
$newShops = [
    ['10104', 'S12', '101', '釧路',     12, 'small'],
    ['10105', 'S13', '101', '帯広',     13, 'small'],
    ['10203', 'S14', '102', '秋田',     14, 'mid'],
    ['10204', 'S15', '102', '山形',     15, 'small'],
    ['10205', 'S16', '102', '福島',     16, 'mid'],
    ['10304', 'S17', '103', '渋谷',     17, 'large'],
    ['10305', 'S18', '103', '上野',     18, 'large'],
    ['10306', 'S19', '103', '千葉',     19, 'mid'],
    ['10307', 'S20', '103', 'さいたま', 20, 'large'],
    ['10308', 'S21', '103', '川崎',     21, 'large'],
    ['20103', 'S22', '201', '京都',     22, 'large'],
    ['20104', 'S23', '201', '神戸',     23, 'large'],
    ['20105', 'S24', '201', '奈良',     24, 'mid'],
    ['20106', 'S25', '201', '和歌山',   25, 'small'],
    ['20107', 'S26', '201', '滋賀',     26, 'small'],
    ['20202', 'S27', '202', '岡山',     27, 'mid'],
    ['20203', 'S28', '202', '高松',     28, 'small'],
    ['20204', 'S29', '202', '松山',     29, 'small'],
    ['20205', 'S30', '202', '徳島',     30, 'small'],
];

// 月予算（scale 別）
$scaleBudget = [
    'large' => ['fitness' => 16000, 'golf' => 11000],
    'mid'   => ['fitness' => 13000, 'golf' =>  9000],
    'small' => ['fitness' => 10000, 'golf' =>  8000],
];

$years = [2025, 2026, 2027];

// ============ Reset ============
if ($reset) {
    echo "=== 追加19店舗を削除 ===\n";
    $codes = array_map(fn($s) => $s[0], $newShops);
    $in = implode(',', array_map(fn($c) => "'{$c}'", $codes));
    // 子テーブルから先に削除
    execute("DELETE FROM budgets         WHERE shop_code IN ({$in})");
    execute("DELETE FROM shop_categories WHERE shop_code IN ({$in})");
    execute("DELETE FROM users           WHERE shop_code IN ({$in})");
    execute("DELETE FROM shops           WHERE code      IN ({$in})");
    echo "削除完了\n\n";
}

// ============ 投入 ============
echo "=== 追加19店舗を投入 ===\n";

// パスワード 'password' のハッシュ（既存ユーザーと同じものを再利用）
$passwordHash = '$2y$10$c2zmVJav6G.Qn0dj8kU0J.VJbD8gV1XxUFiotFTO95msoCS48tIva';

beginTransaction();
try {
    $addedShops = 0;
    $addedUsers = 0;
    $addedBudgetRows = 0;

    foreach ($newShops as $s) {
        [$code, $short, $area, $name, $sort, $scale] = $s;

        // 1) shops
        execute(
            'INSERT INTO shops (code, short_code, area_code, name, sort_order, is_active)
             VALUES (:code, :short, :area, :name, :sort, 1)',
            [':code' => $code, ':short' => $short, ':area' => $area, ':name' => $name, ':sort' => $sort]
        );
        $addedShops++;

        // 2) shop_categories (fitness + golf)
        execute('INSERT INTO shop_categories (shop_code, category_code) VALUES (:s, :c)',
            [':s' => $code, ':c' => 'fitness']);
        execute('INSERT INTO shop_categories (shop_code, category_code) VALUES (:s, :c)',
            [':s' => $code, ':c' => 'golf']);

        // 3) users (shop ユーザー)
        execute(
            'INSERT INTO users (login_id, password, name, role, shop_code, is_active)
             VALUES (:lid, :pw, :name, :role, :sc, 1)',
            [
                ':lid'  => $code,
                ':pw'   => $passwordHash,
                ':name' => $name . '店',
                ':role' => 'shop',
                ':sc'   => $code,
            ]
        );
        $addedUsers++;

        // 4) budgets (各年度 × 12 ヶ月 × 2 部門)
        $fitMonth  = $scaleBudget[$scale]['fitness'];
        $golfMonth = $scaleBudget[$scale]['golf'];
        foreach ($years as $fy) {
            for ($m = 1; $m <= 12; $m++) {
                execute(
                    'INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
                     VALUES (:s, :y, :m, :d, :amt, 0)',
                    [':s' => $code, ':y' => $fy, ':m' => $m, ':d' => 'fitness', ':amt' => $fitMonth]
                );
                execute(
                    'INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
                     VALUES (:s, :y, :m, :d, :amt, 0)',
                    [':s' => $code, ':y' => $fy, ':m' => $m, ':d' => 'golf', ':amt' => $golfMonth]
                );
                $addedBudgetRows += 2;
            }
        }
        echo "  ✓ {$code} {$name} ({$scale}) — fit={$fitMonth} golf={$golfMonth}\n";
    }

    commit();
    echo "\n=== 完了 ===\n";
    echo "店舗追加: {$addedShops} 件\n";
    echo "ユーザー追加: {$addedUsers} 件\n";
    echo "予算行追加: {$addedBudgetRows} 件\n\n";
    echo "ヒント: 削除は  php tools/seed_expand_to_30_shops.php --reset\n";

} catch (Throwable $e) {
    rollback();
    echo "\n❌ エラー: " . $e->getMessage() . "\n";
    exit(1);
}
