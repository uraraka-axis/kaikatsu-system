<?php declare(strict_types=1);

/**
 * 予算管理のカテゴリラベル挙動テスト用データ投入スクリプト
 *
 * 投入内容:
 *   ・カテゴリ「billiard（ビリヤード）」を新規追加
 *   ・新規店舗 3 件:
 *       10106 苫小牧 (北海道 / fitness のみ)
 *       10309 町田   (関東   / golf のみ)
 *       20206 高知   (中国・四国 / fitness + golf + billiard)
 *   ・3 店舗ぶんの 2026 年度月次予算（取扱カテゴリのみ）
 *
 * 期待挙動（予算管理画面）:
 *   ・苫小牧 → タブ: 全体 / フィットネス
 *   ・町田   → タブ: 全体 / インドアゴルフ
 *   ・高知   → タブ: 全体 / フィットネス / インドアゴルフ / ビリヤード
 *
 * 使い方:
 *   php tools/seed_billiard_test_shops.php          - 投入（既存は UPDATE）
 *   php tools/seed_billiard_test_shops.php --reset  - 上記データを削除
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$args  = $argv ?? [];
$reset = in_array('--reset', $args, true);

// パスワード 'password' のハッシュ（既存ユーザーと同じものを再利用）
$PASSWORD_HASH = '$2y$10$c2zmVJav6G.Qn0dj8kU0J.VJbD8gV1XxUFiotFTO95msoCS48tIva';

// ----------------------------------------------------------------
// 定義
// ----------------------------------------------------------------
$NEW_CATEGORY = [
    'code'         => 'billiard',
    'name'         => 'ビリヤード',
    'closing_type' => 'monthly',
    'closing_day'  => 15,
    'sort_order'   => 3,
];

// [shop_code, name, short_code, area_code, sort_order, [取扱カテゴリ]]
$NEW_SHOPS = [
    ['10106', '苫小牧', 'S31', '101', 12, ['fitness']],
    ['10309', '町田',   'S32', '103', 22, ['golf']],
    ['20206', '高知',   'S33', '202', 32, ['fitness', 'golf', 'billiard']],
];

// 月次予算ベース金額（カテゴリ別 / 全期間で同額）
$BUDGET_BASE = [
    'fitness'  => 18000,
    'golf'     => 22000,
    'billiard' => 12000,
];

$FISCAL_YEAR = 2026;
$MONTHS = [1,2,3,4,5,6,7,8,9,10,11,12];

// ----------------------------------------------------------------
// 削除モード
// ----------------------------------------------------------------
if ($reset) {
    echo "[RESET] テストデータを削除します\n";

    $shopCodes = array_map(fn($s) => $s[0], $NEW_SHOPS);
    $placeholders = implode(',', array_fill(0, count($shopCodes), '?'));

    execute("DELETE FROM budgets WHERE shop_code IN ($placeholders)", $shopCodes);
    echo "  [DELETE] budgets\n";

    execute("DELETE FROM shop_categories WHERE shop_code IN ($placeholders)", $shopCodes);
    echo "  [DELETE] shop_categories\n";

    execute("DELETE FROM users WHERE shop_code IN ($placeholders)", $shopCodes);
    echo "  [DELETE] users × " . count($shopCodes) . "\n";

    execute("DELETE FROM shops WHERE code IN ($placeholders)", $shopCodes);
    echo "  [DELETE] shops × " . count($shopCodes) . "\n";

    execute("DELETE FROM shop_categories WHERE category_code = :c", [':c' => $NEW_CATEGORY['code']]);
    execute("DELETE FROM categories WHERE code = :c", [':c' => $NEW_CATEGORY['code']]);
    echo "  [DELETE] category {$NEW_CATEGORY['code']}\n";

    echo "[OK] reset 完了\n";
    exit(0);
}

// ----------------------------------------------------------------
// 投入モード
// ----------------------------------------------------------------
echo "[SEED] テストデータを投入します (fiscal_year={$FISCAL_YEAR})\n";

// 1. billiard カテゴリ
$existing = getOne('SELECT code FROM categories WHERE code = :c', [':c' => $NEW_CATEGORY['code']]);
if ($existing) {
    execute(
        'UPDATE categories SET name = :n, closing_type = :ct, closing_day = :cd, sort_order = :so, is_active = 1
          WHERE code = :c',
        [
            ':c'  => $NEW_CATEGORY['code'],
            ':n'  => $NEW_CATEGORY['name'],
            ':ct' => $NEW_CATEGORY['closing_type'],
            ':cd' => $NEW_CATEGORY['closing_day'],
            ':so' => $NEW_CATEGORY['sort_order'],
        ]
    );
    echo "  [UPDATE] category: {$NEW_CATEGORY['code']} ({$NEW_CATEGORY['name']})\n";
} else {
    execute(
        'INSERT INTO categories (code, name, closing_type, closing_day, is_active, sort_order)
         VALUES (:c, :n, :ct, :cd, 1, :so)',
        [
            ':c'  => $NEW_CATEGORY['code'],
            ':n'  => $NEW_CATEGORY['name'],
            ':ct' => $NEW_CATEGORY['closing_type'],
            ':cd' => $NEW_CATEGORY['closing_day'],
            ':so' => $NEW_CATEGORY['sort_order'],
        ]
    );
    echo "  [INSERT] category: {$NEW_CATEGORY['code']} ({$NEW_CATEGORY['name']})\n";
}

// 2. 店舗
foreach ($NEW_SHOPS as [$code, $name, $shortCode, $areaCode, $sortOrder, $cats]) {
    $existing = getOne('SELECT code FROM shops WHERE code = :c', [':c' => $code]);
    if ($existing) {
        execute(
            'UPDATE shops SET name = :n, short_code = :sc, area_code = :ac, sort_order = :so, is_active = 1
              WHERE code = :c',
            [':c' => $code, ':n' => $name, ':sc' => $shortCode, ':ac' => $areaCode, ':so' => $sortOrder]
        );
        echo "  [UPDATE] shop: {$code} {$name} (area={$areaCode}, cats=" . implode(',', $cats) . ")\n";
    } else {
        execute(
            'INSERT INTO shops (code, name, short_code, area_code, is_active, sort_order)
             VALUES (:c, :n, :sc, :ac, 1, :so)',
            [':c' => $code, ':n' => $name, ':sc' => $shortCode, ':ac' => $areaCode, ':so' => $sortOrder]
        );
        echo "  [INSERT] shop: {$code} {$name} (area={$areaCode}, cats=" . implode(',', $cats) . ")\n";
    }

    // shop_categories: 既存を全削除してから INSERT（差分計算なしの単純化）
    execute('DELETE FROM shop_categories WHERE shop_code = :sc', [':sc' => $code]);
    foreach ($cats as $catCode) {
        execute(
            'INSERT INTO shop_categories (shop_code, category_code) VALUES (:sc, :cc)',
            [':sc' => $code, ':cc' => $catCode]
        );
    }
}

// 3. 店舗ユーザー（login_id = shop_code, password = 'password'）
foreach ($NEW_SHOPS as [$code, $name, , , , ]) {
    $userName = $name . '店';
    $existing = getOne('SELECT id FROM users WHERE login_id = :lid', [':lid' => $code]);
    if ($existing) {
        execute(
            'UPDATE users
                SET password = :pw,
                    name = :name,
                    role = :role,
                    shop_code = :sc,
                    zone_code = NULL,
                    area_code = NULL,
                    is_active = 1
              WHERE login_id = :lid',
            [
                ':pw'   => $PASSWORD_HASH,
                ':name' => $userName,
                ':role' => 'shop',
                ':sc'   => $code,
                ':lid'  => $code,
            ]
        );
        echo "  [UPDATE] user: {$code} {$userName}\n";
    } else {
        execute(
            'INSERT INTO users (login_id, password, name, role, shop_code, is_active)
             VALUES (:lid, :pw, :name, :role, :sc, 1)',
            [
                ':lid'  => $code,
                ':pw'   => $PASSWORD_HASH,
                ':name' => $userName,
                ':role' => 'shop',
                ':sc'   => $code,
            ]
        );
        echo "  [INSERT] user: {$code} {$userName}\n";
    }
}

// 4. 予算（取扱カテゴリのみ）
foreach ($NEW_SHOPS as [$code, $name, , , , $cats]) {
    foreach ($cats as $catCode) {
        $base = $BUDGET_BASE[$catCode] ?? 15000;
        foreach ($MONTHS as $i => $month) {
            // 実績は最初の数か月だけ入れる（消化率の見栄え用）
            $actual = ($month >= 4 && $month <= 6) ? (int)round($base * (0.6 + 0.1 * $i)) : 0;

            $existing = getOne(
                'SELECT id FROM budgets WHERE shop_code = :sc AND fiscal_year = :fy AND month = :m AND department = :d',
                [':sc' => $code, ':fy' => $FISCAL_YEAR, ':m' => $month, ':d' => $catCode]
            );
            if ($existing) {
                execute(
                    'UPDATE budgets SET budget_amount = :b, actual_amount = :a
                      WHERE shop_code = :sc AND fiscal_year = :fy AND month = :m AND department = :d',
                    [
                        ':sc' => $code, ':fy' => $FISCAL_YEAR, ':m' => $month, ':d' => $catCode,
                        ':b' => $base, ':a' => $actual,
                    ]
                );
            } else {
                execute(
                    'INSERT INTO budgets (shop_code, fiscal_year, month, department, budget_amount, actual_amount)
                     VALUES (:sc, :fy, :m, :d, :b, :a)',
                    [
                        ':sc' => $code, ':fy' => $FISCAL_YEAR, ':m' => $month, ':d' => $catCode,
                        ':b' => $base, ':a' => $actual,
                    ]
                );
            }
        }
        echo "  [BUDGET] shop={$code} cat={$catCode} fy={$FISCAL_YEAR} × 12 ヶ月 (base={$base})\n";
    }
}

echo "[OK] 投入完了\n";
echo "\n--- 確認 ---\n";
echo "予算管理画面で 苫小牧(10106) / 町田(10309) / 高知(20206) を見ると、それぞれ\n";
echo "  ・苫小牧 → タブ: 全体 / フィットネス\n";
echo "  ・町田   → タブ: 全体 / インドアゴルフ\n";
echo "  ・高知   → タブ: 全体 / フィットネス / インドアゴルフ / ビリヤード\n";
echo "が表示されます。\n";
