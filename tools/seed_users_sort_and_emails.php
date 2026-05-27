<?php declare(strict_types=1);

/**
 * users マスタの sort_order 振り直し + ゾーン/エリアマネージャーメールアドレス投入
 *
 * 振り直し方針:
 *   shop（30 店舗） : 1〜30（sort_order ASC, 既存 1-11 を保持し、12〜30 を昇順割り当て）
 *   zone × 2        : 60, 61
 *   area × 5        : 70〜74
 *   admin           : 80
 *   system          : 99
 *
 * テストメアド投入（shop ユーザーに対して）:
 *   zone_manager_email:
 *     東日本 (zone=100) 配下 → zone-east@example.test
 *     西日本 (zone=200) 配下 → zone-west@example.test
 *   area_manager_email:
 *     area_code に応じて area-{code}@example.test
 *       101: area-101@example.test (北海道)
 *       102: area-102@example.test (東北)
 *       103: area-103@example.test (関東)
 *       201: area-201@example.test (関西)
 *       202: area-202@example.test (中四国)
 *
 * 使い方:
 *   php tools/seed_users_sort_and_emails.php
 *   php tools/seed_users_sort_and_emails.php --dry-run   ← 反映せず一覧表示のみ
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$args   = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);

echo "=== users sort_order 振り直し + テストメアド投入 ===\n";
echo $dryRun ? "(dry-run: DB 更新は行いません)\n\n" : "\n";

// 既存ユーザーを取得（shop は shop_code を持つので sort_order を保持、未設定なら割り当て）
$rows = query(
    "SELECT u.id, u.login_id, u.name, u.role, u.shop_code, u.zone_code, u.area_code,
            u.sort_order, s.sort_order AS shop_sort, s.area_code AS shop_area_code,
            a.zone_code AS shop_zone_code
       FROM users u
       LEFT JOIN shops s ON u.shop_code = s.code
       LEFT JOIN areas a ON s.area_code = a.code
      ORDER BY u.id"
);

// shop の sort_order は所属店舗の sort_order を継承（無ければ id 順で 1〜30 を割り当て）
$shopRows = array_values(array_filter($rows, fn($r) => $r['role'] === 'shop'));
// shop は shops.sort_order に基づいて並べる（未設定 = 大きい数値扱いで後尾）
usort($shopRows, function ($a, $b) {
    $sa = $a['shop_sort'] !== null ? (int)$a['shop_sort'] : PHP_INT_MAX;
    $sb = $b['shop_sort'] !== null ? (int)$b['shop_sort'] : PHP_INT_MAX;
    if ($sa !== $sb) return $sa <=> $sb;
    return strcmp((string)$a['shop_code'], (string)$b['shop_code']);
});
$shopOrders = [];
foreach ($shopRows as $i => $r) {
    $shopOrders[$r['id']] = $i + 1; // 1〜30
}

// zone は code 昇順で 60, 61
$zoneRows = array_values(array_filter($rows, fn($r) => $r['role'] === 'zone'));
usort($zoneRows, fn($a, $b) => strcmp((string)$a['zone_code'], (string)$b['zone_code']));
$zoneOrders = [];
foreach ($zoneRows as $i => $r) {
    $zoneOrders[$r['id']] = 60 + $i;
}

// area は code 昇順で 70〜74
$areaRows = array_values(array_filter($rows, fn($r) => $r['role'] === 'area'));
usort($areaRows, fn($a, $b) => strcmp((string)$a['area_code'], (string)$b['area_code']));
$areaOrders = [];
foreach ($areaRows as $i => $r) {
    $areaOrders[$r['id']] = 70 + $i;
}

// admin は 80, system は 99
$updates = []; // [id, new_sort, zone_email, area_email]

foreach ($rows as $r) {
    $newSort = null;
    if ($r['role'] === 'shop') $newSort = $shopOrders[$r['id']] ?? null;
    elseif ($r['role'] === 'zone')  $newSort = $zoneOrders[$r['id']] ?? null;
    elseif ($r['role'] === 'area')  $newSort = $areaOrders[$r['id']] ?? null;
    elseif ($r['role'] === 'admin') $newSort = 80;
    elseif ($r['role'] === 'system') $newSort = 99;

    // テストメアド（shop のみ）
    $zoneEmail = null;
    $areaEmail = null;
    if ($r['role'] === 'shop') {
        $zc = $r['shop_zone_code']; // shops 経由でゾーンコード
        $ac = $r['shop_area_code'];
        if ($zc === '100')      $zoneEmail = 'zone-east@example.test';
        elseif ($zc === '200')  $zoneEmail = 'zone-west@example.test';
        if ($ac !== null && $ac !== '') {
            $areaEmail = 'area-' . $ac . '@example.test';
        }
    }

    $updates[] = [
        'id'          => $r['id'],
        'login_id'    => $r['login_id'],
        'name'        => $r['name'],
        'role'        => $r['role'],
        'old_sort'    => (int)$r['sort_order'],
        'new_sort'    => (int)$newSort,
        'zone_email'  => $zoneEmail,
        'area_email'  => $areaEmail,
    ];
}

// 差分表示
printf("%-10s %-20s %-8s %-10s %-30s %-30s\n",
       'login_id', 'name', 'role', 'sort', 'zone_email', 'area_email');
echo str_repeat('-', 120) . "\n";
foreach ($updates as $u) {
    printf("%-10s %-20s %-8s %-10s %-30s %-30s\n",
           $u['login_id'],
           mb_substr($u['name'], 0, 18),
           $u['role'],
           ($u['old_sort'] !== $u['new_sort'] ? $u['old_sort'] . '→' . $u['new_sort'] : (string)$u['new_sort']),
           (string)($u['zone_email'] ?? '—'),
           (string)($u['area_email'] ?? '—'));
}

if ($dryRun) {
    echo "\n(dry-run のため DB 更新スキップ)\n";
    exit(0);
}

// 反映
beginTransaction();
try {
    foreach ($updates as $u) {
        execute(
            'UPDATE users
                SET sort_order         = :so,
                    zone_manager_email = :ze,
                    area_manager_email = :ae
              WHERE id = :id',
            [
                ':so' => $u['new_sort'],
                ':ze' => $u['zone_email'],
                ':ae' => $u['area_email'],
                ':id' => $u['id'],
            ]
        );
    }
    commit();
    echo "\n完了 (" . count($updates) . " 件更新)\n";
} catch (Throwable $e) {
    rollback();
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
