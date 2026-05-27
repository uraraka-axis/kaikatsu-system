<?php declare(strict_types=1);

/**
 * ゾーン / エリアマネージャー ユーザーを投入する seed スクリプト
 *
 * 投入内容:
 *   ゾーンマネージャー × 2
 *     Z100 (東日本ゾーン担当 / zone_code=100)
 *     Z200 (西日本ゾーン担当 / zone_code=200)
 *
 *   エリアマネージャー × 5
 *     A101 (北海道エリア担当       / area_code=101)
 *     A102 (東北エリア担当         / area_code=102)
 *     A103 (関東エリア担当         / area_code=103)
 *     A201 (関西エリア担当         / area_code=201)
 *     A202 (中四国エリア担当       / area_code=202)
 *
 * パスワードは全員 'password'（bcrypt ハッシュ済み）。
 *
 * 使い方:
 *   php tools/seed_zone_area_users.php          - 投入（既存ユーザーは UPDATE）
 *   php tools/seed_zone_area_users.php --reset  - 上記ユーザーを削除
 *
 * 関連マイグレーション: database/migration_zone_area_roles.sql
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$args  = $argv ?? [];
$reset = in_array('--reset', $args, true);

// パスワード 'password' のハッシュ（既存ユーザーと同じものを再利用）
$passwordHash = '$2y$10$c2zmVJav6G.Qn0dj8kU0J.VJbD8gV1XxUFiotFTO95msoCS48tIva';

// [login_id, name, role, zone_code, area_code]
$zoneUsers = [
    ['Z100', '東日本ゾーンマネージャー', 'zone', '100', null],
    ['Z200', '西日本ゾーンマネージャー', 'zone', '200', null],
];

$areaUsers = [
    ['A101', '北海道エリアマネージャー', 'area', null, '101'],
    ['A102', '東北エリアマネージャー',   'area', null, '102'],
    ['A103', '関東エリアマネージャー',   'area', null, '103'],
    ['A201', '関西エリアマネージャー',   'area', null, '201'],
    ['A202', '中四国エリアマネージャー', 'area', null, '202'],
];

$allUsers = array_merge($zoneUsers, $areaUsers);

// ============ Reset ============
if ($reset) {
    echo "=== Zone/Area マネージャーユーザーを削除 ===\n";
    beginTransaction();
    try {
        foreach ($allUsers as $u) {
            execute('DELETE FROM users WHERE login_id = :lid', [':lid' => $u[0]]);
            echo "  - {$u[0]} ({$u[1]}) を削除\n";
        }
        commit();
        echo "完了\n";
    } catch (Throwable $e) {
        rollback();
        echo "エラー: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

// ============ Insert / Update ============
echo "=== Zone/Area マネージャーユーザーを投入 ===\n";

beginTransaction();
try {
    foreach ($allUsers as [$loginId, $name, $role, $zoneCode, $areaCode]) {
        $existing = getOne(
            'SELECT id FROM users WHERE login_id = :lid',
            [':lid' => $loginId]
        );

        if ($existing) {
            execute(
                'UPDATE users
                    SET password = :pw,
                        name = :name,
                        role = :role,
                        zone_code = :zc,
                        area_code = :ac,
                        shop_code = NULL,
                        is_active = 1
                  WHERE login_id = :lid',
                [
                    ':pw'   => $passwordHash,
                    ':name' => $name,
                    ':role' => $role,
                    ':zc'   => $zoneCode,
                    ':ac'   => $areaCode,
                    ':lid'  => $loginId,
                ]
            );
            echo "  [UPDATE] {$loginId} - {$name} (role={$role}, zone={$zoneCode}, area={$areaCode})\n";
        } else {
            execute(
                'INSERT INTO users (login_id, password, name, role, zone_code, area_code, is_active)
                 VALUES (:lid, :pw, :name, :role, :zc, :ac, 1)',
                [
                    ':lid'  => $loginId,
                    ':pw'   => $passwordHash,
                    ':name' => $name,
                    ':role' => $role,
                    ':zc'   => $zoneCode,
                    ':ac'   => $areaCode,
                ]
            );
            echo "  [INSERT] {$loginId} - {$name} (role={$role}, zone={$zoneCode}, area={$areaCode})\n";
        }
    }
    commit();
    echo "完了 (7 ユーザー)\n";
    echo "\nログイン例:\n";
    echo "  curl -X POST -H 'Content-Type: application/json' -d '{\"login_id\":\"Z100\",\"password\":\"password\"}' http://localhost/kaikatsu-system/api/login.php\n";
} catch (Throwable $e) {
    rollback();
    echo "エラー: " . $e->getMessage() . "\n";
    exit(1);
}
