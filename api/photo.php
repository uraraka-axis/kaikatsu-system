<?php declare(strict_types=1);

/**
 * 快活システム - 発注写真配信API
 * GET /api/photo.php?id={photo_id}
 *
 * セキュリティ要件:
 *   - ログイン必須
 *   - 店舗ユーザーは自店の発注写真のみ閲覧可
 *   - 管理者は全店舗の発注写真を閲覧可
 *   - uploads/ ディレクトリは Apache レベルで全アクセス禁止
 *   - 写真は本APIを介してのみ配信する
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    requireMethod('GET');
    requireLogin();

    $photoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($photoId === null || $photoId === false) {
        http_response_code(400);
        exit;
    }

    // 写真情報＋発注の店舗コードを取得
    $row = getOne(
        'SELECT p.file_path, p.original_filename, p.mime_type, o.shop_code
           FROM order_photos p
           JOIN orders o ON p.order_id = o.id
          WHERE p.id = :id',
        [':id' => $photoId]
    );

    if ($row === null) {
        http_response_code(404);
        exit;
    }

    // 所有者チェック: 管理者でなく、自店以外の発注なら拒否
    $user = getCurrentUser();
    if ($user['role'] !== 'admin' && $user['shop_code'] !== $row['shop_code']) {
        http_response_code(403);
        exit;
    }

    // ファイル絶対パスを安全に構築（パストラバーサル対策）
    $baseDir   = realpath(__DIR__ . '/../uploads');
    $candidate = realpath(__DIR__ . '/../' . $row['file_path']);

    if ($baseDir === false || $candidate === false) {
        http_response_code(404);
        exit;
    }

    // 解決後パスが uploads/ 配下であることを確認
    if (strncmp($candidate, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
        http_response_code(403);
        exit;
    }

    if (!is_file($candidate) || !is_readable($candidate)) {
        http_response_code(404);
        exit;
    }

    // MIMEタイプ決定
    $mimeType = $row['mime_type'] ?? '';
    if ($mimeType === '' || $mimeType === null) {
        $mimeType = mime_content_type($candidate) ?: 'application/octet-stream';
    }

    // 許可するMIMEのみ配信（白リスト方式）
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic',
    ];
    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(403);
        exit;
    }

    // サムネイル要求の場合は GD で縮小して返す（軽量化のため）
    $sizeParam = $_GET['size'] ?? '';
    if ($sizeParam === 'thumb' && function_exists('imagecreatetruecolor')) {
        $thumbBin = generateThumbnail($candidate, $mimeType, 200);
        if ($thumbBin !== null) {
            // サムネイルは PNG/JPEG/WebP 元タイプを尊重しつつ JPEG で返却（軽量化）
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            echo $thumbBin;
            exit;
        }
        // 失敗時は原寸にフォールバック
    }

    // キャッシュ制御（同一ユーザー内ではキャッシュ可）
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($candidate));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($candidate);
    exit;
} catch (Throwable $e) {
    error_log('Photo API error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

/**
 * GDでサムネイル生成（長辺 $maxSize に縮小、JPEG バイナリで返す）
 * 失敗時は null を返す
 */
function generateThumbnail(string $path, string $mimeType, int $maxSize = 200): ?string
{
    $src = null;
    switch ($mimeType) {
        case 'image/jpeg':
            if (function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($path);
            break;
        case 'image/png':
            if (function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($path);
            break;
        case 'image/gif':
            if (function_exists('imagecreatefromgif')) $src = @imagecreatefromgif($path);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($path);
            break;
        default:
            return null;
    }
    if ($src === null || $src === false) return null;

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) { imagedestroy($src); return null; }

    // 既に十分小さい場合は元のJPEGを返す（再エンコードなし）
    if ($w <= $maxSize && $h <= $maxSize && $mimeType === 'image/jpeg') {
        imagedestroy($src);
        return file_get_contents($path) ?: null;
    }

    $scale = min($maxSize / $w, $maxSize / $h, 1.0);
    $newW = max(1, (int)round($w * $scale));
    $newH = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($newW, $newH);
    // 白背景（透過 PNG/WebP を JPEG にする場合の対策）
    $bg = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $bg);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

    ob_start();
    imagejpeg($dst, null, 80);
    $bin = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return is_string($bin) ? $bin : null;
}
