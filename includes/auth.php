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
        // このアプリ専用のセッションCookie名・パスに分離する。
        // 同一ドメイン(uraraka.moe)に同居する他アプリや、共有端末に残存する
        // 既存の PHPSESSID と混線し、別ユーザー(例:admin)のセッションを
        // 読んでしまう不具合を防ぐため。
        session_name('FIT24OB_SESS');
        ini_set('session.cookie_path', rtrim(BASE_URL, '/') . '/');
        // セッションCookieのセキュリティ属性を明示設定。
        // Secure は HTTPS リクエスト時のみ付与（ローカル http 開発を壊さないため条件付き）。
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        session_start();
    }
    // アイドルタイムアウト判定（スライド式）:
    // ログイン中、最後の操作から SESSION_IDLE_TIMEOUT 秒を超えて無操作なら
    // セッションを破棄してログアウト扱いにする。操作のたびに最終操作時刻を更新。
    if (isset($_SESSION['user'])) {
        $now  = time();
        $last = $_SESSION['last_activity'] ?? $now;
        if (($now - $last) > SESSION_IDLE_TIMEOUT) {
            $_SESSION = [];
            session_unset();
            session_destroy();
            return; // 同一リクエスト内はログアウト扱い（$_SESSION 空）
        }
        $_SESSION['last_activity'] = $now;
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
        'SELECT u.id, u.login_id, u.password, u.name, u.role,
                u.shop_code, u.zone_code, u.area_code,
                s.name AS shop_name,
                z.name AS zone_name,
                a.name AS area_name
         FROM users u
         LEFT JOIN shops s ON u.shop_code = s.code
         LEFT JOIN zones z ON u.zone_code = z.code
         LEFT JOIN areas a ON u.area_code = a.code
         WHERE u.login_id = :login_id AND u.is_active = 1
           AND (u.shop_code IS NULL OR s.is_active = 1)',
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
        'zone_code'  => $user['zone_code'],
        'zone_name'  => $user['zone_name'],
        'area_code'  => $user['area_code'],
        'area_name'  => $user['area_name'],
        'categories' => $categories, // 店舗ユーザーの取り扱いカテゴリ。admin/system/zone/area は空配列
    ];
    $_SESSION['last_activity'] = time(); // アイドルタイムアウトの起点

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
 * 管理側ロール判定（admin / system / zone / area）
 *
 * 「複数店舗を横断して閲覧する側」のロールを true とする。
 * 発注一覧・予算管理・自店調達管理での「絞り込み付きの一覧表示」に使う。
 * zone は管轄ゾーン配下、area は管轄エリア配下の店舗のみ参照可能。
 *
 * @return bool 管理側ロールなら true
 */
function isManager(): bool
{
    startSession();
    $role = $_SESSION['user']['role'] ?? null;
    return in_array($role, ['admin', 'system', 'zone', 'area'], true);
}

/**
 * ログインユーザーのロールに応じた「閲覧可能 shop_code への絞り込み」SQL 断片を返す。
 *
 * 戻り値: ['where' => string|null, 'params' => array]
 *   - where が null の場合は追加 WHERE 不要（admin / system）
 *   - shop ロールは自店のみ
 *   - zone ロールは自分の zone_code 配下のエリアに所属する店舗のみ
 *   - area ロールは自分の area_code の店舗のみ
 *   - 不正状態（zone_code/area_code 未設定など）の場合は 1=0 で全件除外
 *
 * @param array $user セッションのユーザー情報
 * @param string $shopAlias shops テーブルのエイリアス（デフォルト 's'）。
 *                          shop_code カラムを持つ別テーブルでフィルタしたい場合は
 *                          $shopCodeColumn を上書きすること。
 * @param string|null $shopCodeColumn shop_code を比較するカラム名（デフォルトは "{alias}.code"）
 * @return array
 */
function getRoleScopeSql(array $user, string $shopAlias = 's', ?string $shopCodeColumn = null): array
{
    $role = $user['role'] ?? '';
    $shopCol = $shopCodeColumn ?? ($shopAlias . '.code');
    $areaCol = $shopAlias . '.area_code';

    switch ($role) {
        case 'admin':
        case 'system':
            return ['where' => null, 'params' => []];

        case 'shop':
            if (empty($user['shop_code'])) {
                return ['where' => '1=0', 'params' => []];
            }
            return [
                'where'  => "{$shopCol} = :_scope_shop_code",
                'params' => [':_scope_shop_code' => $user['shop_code']],
            ];

        case 'zone':
            if (empty($user['zone_code'])) {
                return ['where' => '1=0', 'params' => []];
            }
            return [
                'where'  => "{$areaCol} IN (SELECT code FROM areas WHERE zone_code = :_scope_zone_code)",
                'params' => [':_scope_zone_code' => $user['zone_code']],
            ];

        case 'area':
            if (empty($user['area_code'])) {
                return ['where' => '1=0', 'params' => []];
            }
            return [
                'where'  => "{$areaCol} = :_scope_area_code",
                'params' => [':_scope_area_code' => $user['area_code']],
            ];

        default:
            return ['where' => '1=0', 'params' => []];
    }
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
 * 管理側ロール（admin / system / zone / area）必須チェック
 *
 * 「複数店舗を横断して閲覧する画面・API」に使う。
 * shop ロールはここで弾く。zone/area は管轄スコープ付きで通す。
 */
function requireManager(): void
{
    requireLogin();

    if (!isManager()) {
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
        echo '403 Forbidden: 管理側権限が必要です';
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
