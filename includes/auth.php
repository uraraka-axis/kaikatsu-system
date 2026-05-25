<?php declare(strict_types=1);

/**
 * 快活システム - 認証・セッション管理
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * セッションを開始する（未開始の場合のみ）
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * ログイン履歴に1行記録（失敗・成功どちらも）
 *
 * @param string $loginId 入力されたログインID
 * @param int|null $userId 成功時のみセット
 * @param bool $success 成功フラグ
 * @param string|null $failureReason 失敗理由 (user_not_found / invalid_password / inactive など)
 */
function recordLoginAttempt(string $loginId, ?int $userId, bool $success, ?string $failureReason = null): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua !== null && mb_strlen($ua) > 500) {
            $ua = mb_substr($ua, 0, 500);
        }
        execute(
            'INSERT INTO login_history (user_id, login_id, ip_address, user_agent, success, failure_reason)
             VALUES (:uid, :lid, :ip, :ua, :ok, :fr)',
            [
                ':uid' => $userId,
                ':lid' => $loginId,
                ':ip'  => $ip,
                ':ua'  => $ua,
                ':ok'  => $success ? 1 : 0,
                ':fr'  => $failureReason,
            ]
        );
    } catch (Throwable $e) {
        // ログイン履歴の記録失敗は認証フロー自体を止めない
        error_log('login_history insert failed: ' . $e->getMessage());
    }
}

/**
 * ログイン処理
 *
 * @param string $loginId ログインID
 * @param string $password パスワード
 * @return array|null 成功時はユーザー情報、失敗時はnull
 */
function login(string $loginId, string $password): ?array
{
    $user = getOne(
        'SELECT u.id, u.login_id, u.password, u.name, u.role, u.shop_code, s.name AS shop_name
         FROM users u
         LEFT JOIN shops s ON u.shop_code = s.code
         WHERE u.login_id = :login_id AND u.is_active = 1',
        [':login_id' => $loginId]
    );

    if ($user === null) {
        recordLoginAttempt($loginId, null, false, 'user_not_found_or_inactive');
        return null;
    }

    if (!password_verify($password, $user['password'])) {
        recordLoginAttempt($loginId, (int)$user['id'], false, 'invalid_password');
        return null;
    }

    // 店舗ユーザーの場合、所属店舗の取り扱いカテゴリを取得
    $categories = [];
    if ($user['shop_code'] !== null) {
        $rows = query(
            'SELECT c.code, c.name
               FROM shop_categories sc
               JOIN categories c ON sc.category_code = c.code
              WHERE sc.shop_code = :shop_code AND c.is_active = 1
              ORDER BY c.sort_order, c.code',
            [':shop_code' => $user['shop_code']]
        );
        foreach ($rows as $r) {
            $categories[] = ['code' => $r['code'], 'name' => $r['name']];
        }
    }

    // セッションにユーザー情報を保存
    startSession();
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'         => $user['id'],
        'login_id'   => $user['login_id'],
        'name'       => $user['name'],
        'role'       => $user['role'],
        'shop_code'  => $user['shop_code'],
        'shop_name'  => $user['shop_name'],
        'categories' => $categories, // 店舗ユーザーの取り扱いカテゴリ。admin/system は空配列
    ];

    recordLoginAttempt($loginId, (int)$user['id'], true);

    return $_SESSION['user'];
}

/**
 * ログアウト処理
 */
function logout(): void
{
    startSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * ログイン中のユーザー情報を取得
 *
 * @return array|null ユーザー情報またはnull
 */
function getCurrentUser(): ?array
{
    startSession();
    return $_SESSION['user'] ?? null;
}

/**
 * ログイン判定
 *
 * @return bool ログイン中ならtrue
 */
function isLoggedIn(): bool
{
    startSession();
    return isset($_SESSION['user']);
}

/**
 * 管理者判定（admin または system）
 *
 * system は admin の上位互換のため、admin 用の権限を持つ。
 * admin 専用に限定したい場合は明示的に role === 'admin' をチェックすること。
 *
 * @return bool 管理者ならtrue
 */
function isAdmin(): bool
{
    startSession();
    $role = $_SESSION['user']['role'] ?? null;
    return $role === 'admin' || $role === 'system';
}

/**
 * システム管理者判定（system のみ）
 *
 * @return bool system なら true
 */
function isSystem(): bool
{
    startSession();
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'system';
}

/**
 * ログイン必須チェック（未ログインならログイン画面にリダイレクト）
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        // APIリクエストの場合はJSONで返す
        if (isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'ログインが必要です',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 画面リクエストの場合はリダイレクト
        header('Location: ' . BASE_URL . '/login.html');
        exit;
    }
}

/**
 * 管理者権限必須チェック（admin または system を通す）
 */
function requireAdmin(): void
{
    requireLogin();

    if (!isAdmin()) {
        if (isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => '権限がありません',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        echo '403 Forbidden: 管理者権限が必要です';
        exit;
    }
}

/**
 * システム管理者権限必須チェック（system のみ）
 *
 * 監査ログ閲覧などシステム管理者専用機能に使う。
 */
function requireSystem(): void
{
    requireLogin();

    if (!isSystem()) {
        if (isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'システム管理者権限が必要です',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        echo '403 Forbidden: システム管理者権限が必要です';
        exit;
    }
}

/**
 * APIリクエスト判定
 *
 * @return bool APIリクエストならtrue
 */
function isApiRequest(): bool
{
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    return str_contains($acceptHeader, 'application/json')
        || str_contains($contentType, 'application/json')
        || str_contains($requestUri, '/api/');
}
