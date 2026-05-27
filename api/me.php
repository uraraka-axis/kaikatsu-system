<?php declare(strict_types=1);

/**
 * 快活システム - ログインユーザー情報取得API
 * GET /api/me.php
 *
 * レスポンス（ログイン中）:
 *   { "success": true, "user": { "id": 1, "login_id": "admin", "name": "...", "role": "admin", ... } }
 *
 * レスポンス（未ログイン）:
 *   { "success": false, "error": "ログインが必要です" }
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

try {
    requireMethod('GET');
    requireLogin();

    $user = getCurrentUser();

    // セッションキャッシュに依存せず、DB から最新の表示情報を取得しセッションに反映する。
    // これにより admin が users マスタを Excel で更新したり、shops/zones/areas の name が
    // 変わった場合でも、対象ユーザーが画面リロードするだけで反映される。
    if (!empty($user['id'])) {
        $fresh = getOne(
            'SELECT u.id, u.login_id, u.name, u.role,
                    u.shop_code, u.zone_code, u.area_code,
                    s.name AS shop_name,
                    z.name AS zone_name,
                    a.name AS area_name,
                    u.is_active
               FROM users u
               LEFT JOIN shops s ON u.shop_code = s.code
               LEFT JOIN zones z ON u.zone_code = z.code
               LEFT JOIN areas a ON u.area_code = a.code
              WHERE u.id = :id',
            [':id' => (int)$user['id']]
        );

        if ($fresh === null || (int)$fresh['is_active'] !== 1) {
            // ユーザーが削除・無効化されていたらセッション破棄して 401
            logout();
            jsonError('ログインが必要です', 401);
        }

        // 表示用フィールドをセッションに反映（categories は login 時のものを保持）
        $user['name']      = $fresh['name'];
        $user['role']      = $fresh['role'];
        $user['shop_code'] = $fresh['shop_code'];
        $user['shop_name'] = $fresh['shop_name'];
        $user['zone_code'] = $fresh['zone_code'];
        $user['zone_name'] = $fresh['zone_name'];
        $user['area_code'] = $fresh['area_code'];
        $user['area_name'] = $fresh['area_name'];

        $_SESSION['user'] = $user;
    }

    jsonResponse([
        'success' => true,
        'user'    => $user,
    ]);
} catch (Throwable $e) {
    error_log('Me API error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました', 500);
}
