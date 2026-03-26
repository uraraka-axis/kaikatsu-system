<?php declare(strict_types=1);

/**
 * 快活システム - ログインAPI
 * POST /api/login.php
 *
 * リクエスト:
 *   { "login_id": "admin", "password": "password" }
 *
 * レスポンス（成功）:
 *   { "success": true, "user": { "id": 1, "login_id": "admin", "name": "...", "role": "admin", ... } }
 *
 * レスポンス（失敗）:
 *   { "success": false, "error": "ログインIDまたはパスワードが正しくありません" }
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    requireMethod('POST');

    $input = getJsonInput();

    $loginId  = trim($input['login_id'] ?? '');
    $password = $input['password'] ?? '';

    // バリデーション
    if ($loginId === '' || $password === '') {
        jsonError('ログインIDとパスワードを入力してください');
    }

    // 認証
    $user = login($loginId, $password);

    if ($user === null) {
        jsonError('ログインIDまたはパスワードが正しくありません', 401);
    }

    jsonResponse([
        'success' => true,
        'user'    => $user,
    ]);
} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました', 500);
}
