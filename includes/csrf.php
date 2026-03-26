<?php declare(strict_types=1);

/**
 * 快活システム - CSRF対策
 */

require_once __DIR__ . '/auth.php';

/**
 * CSRFトークンを生成してセッションに保存
 *
 * @return string 生成されたトークン
 */
function generateCsrfToken(): string
{
    startSession();

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;

    return $token;
}

/**
 * CSRFトークンを検証
 *
 * @param string $token 検証するトークン
 * @return bool 有効ならtrue
 */
function validateCsrfToken(string $token): bool
{
    startSession();

    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF対策用のhidden inputタグを返す
 *
 * @return string HTMLタグ文字列
 */
function csrfTokenField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}
