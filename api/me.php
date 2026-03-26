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

try {
    requireMethod('GET');
    requireLogin();

    $user = getCurrentUser();

    jsonResponse([
        'success' => true,
        'user'    => $user,
    ]);
} catch (Throwable $e) {
    error_log('Me API error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました', 500);
}
