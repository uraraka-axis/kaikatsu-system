<?php declare(strict_types=1);

/**
 * 快活システム - ログアウトAPI
 * POST /api/logout.php
 *
 * レスポンス:
 *   { "success": true, "message": "ログアウトしました" }
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    requireMethod('POST');

    logout();

    jsonResponse([
        'success' => true,
        'message' => 'ログアウトしました',
    ]);
} catch (Throwable $e) {
    error_log('Logout error: ' . $e->getMessage());
    jsonError('サーバーエラーが発生しました', 500);
}
