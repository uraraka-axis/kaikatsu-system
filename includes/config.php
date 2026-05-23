<?php declare(strict_types=1);

/**
 * 快活システム - 共通設定ファイル
 */

// ============================================================
// タイムゾーン設定
// ============================================================
date_default_timezone_set('Asia/Tokyo');

// ============================================================
// エラー表示設定（開発時はON）
// ============================================================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ============================================================
// DB接続情報
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'kaikatsu');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// サイト設定
// ============================================================
define('SITE_NAME', '快活フロンティア 発注管理システム');
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '/kaikatsu-system');

// ============================================================
// アップロード設定
// ============================================================
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('MAX_PHOTO_COUNT', 3);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/gif']);

// ============================================================
// セッション設定
// ============================================================
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', '3600'); // 1時間

// ============================================================
// 発注番号プレフィクス
// ============================================================
define('ORDER_PREFIX_REPAIR', 'REP');
define('ORDER_PREFIX_EQUIPMENT', 'EQU');
define('ORDER_PREFIX_PARTS', 'PTS');
define('ORDER_PREFIX_PROCUREMENT', 'REQ');

// ============================================================
// ステータス定義
// ============================================================
define('STATUS_REQUESTED', 0);   // 依頼中
define('STATUS_ORDERED', 1);     // 発注済
define('STATUS_IN_PROGRESS', 2); // 修理待ち / 配達中
define('STATUS_DONE', 3);        // 修理済 / 納品済
define('STATUS_COMPLETED', 4);   // 完了

// ============================================================
// メール送信設定 (PHPMailer + SMTP)
// 環境ごとに値を差し替える。ローカル開発は Mailpit を想定。
//   Mailpit:  SMTP_HOST=localhost, SMTP_PORT=1025, SMTP_AUTH=false
//   Gmail:    SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_AUTH=true, SMTP_SECURE=tls
//   SendGrid: SMTP_HOST=smtp.sendgrid.net, SMTP_PORT=587, SMTP_AUTH=true, SMTP_USER=apikey
// ============================================================
define('SMTP_HOST',     'localhost');
define('SMTP_PORT',     1025);
define('SMTP_AUTH',     false);          // true で認証あり
define('SMTP_USER',     '');             // SMTP_AUTH=true のとき必須
define('SMTP_PASS',     '');             // SMTP_AUTH=true のとき必須
define('SMTP_SECURE',   '');             // '', 'tls', 'ssl' のいずれか
define('MAIL_FROM',     'noreply@kaikatsu.local');
define('MAIL_FROM_NAME','快活システム');
// ローカル開発時に Mailpit 等を起動していない場合、true にすると
// 送信を試みず logs/mail.log に追記するだけにできる (障害切り分け用)。
define('MAIL_LOG_ONLY', false);
