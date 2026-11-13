<?php declare(strict_types=1);

/**
 * 快活システム - 商品画像配信API
 * GET /api/product-image.php?code={product_code}&slot={0|1|2}
 *   slot=0 (デフォルト): image_path (画像1)
 *   slot=1: image_path2 (画像2)
 *   slot=2: image_path3 (画像3)
 *   size=thumb: 一覧カード用の縮小JPEG（560×316 に収める）
 *               uploads/products/_thumbs/ にキャッシュ。元画像の更新日時・サイズが変われば作り直す
 *
 * セキュリティ要件:
 *   - ログイン必須
 *   - 店舗ユーザーは自店所属カテゴリの商品画像のみ閲覧可
 *   - 管理者は全商品の画像を閲覧可
 *   - uploads/ ディレクトリは Apache レベルで全アクセス禁止
 *   - 画像は本APIを介してのみ配信する
 *
 * 注意: 画像本体は管理者が FTP 等で uploads/products/ に直接配置する想定
 *      products.image_path / image_path2 / image_path3 にファイル名のみ記録
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

try {
    requireMethod('GET');
    requireLogin();

    $code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
    if ($code === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $code)) {
        http_response_code(400);
        exit;
    }

    // slot 0..2 を image_path / image_path2 / image_path3 にマップ
    $slot = isset($_GET['slot']) ? (int)$_GET['slot'] : 0;
    if ($slot < 0 || $slot > 2) {
        http_response_code(400);
        exit;
    }
    $slotColumns = ['image_path', 'image_path2', 'image_path3'];
    $imageCol = $slotColumns[$slot];

    $user = getCurrentUser();

    // 商品 + 該当スロットの画像ファイル名取得、店舗ユーザーは shop_categories で絞り込み
    if (in_array($user['role'], ['admin', 'system'], true)) {
        $row = getOne(
            "SELECT {$imageCol} AS img FROM products WHERE code = :code AND is_active = 1",
            [':code' => $code]
        );
    } else {
        $shopCode = $user['shop_code'] ?? null;
        if ($shopCode === null) {
            http_response_code(403);
            exit;
        }
        $row = getOne(
            "SELECT p.{$imageCol} AS img
               FROM products p
              WHERE p.code = :code AND p.is_active = 1
                AND p.category_code IN (
                    SELECT category_code FROM shop_categories WHERE shop_code = :sc
                )",
            [':code' => $code, ':sc' => $shopCode]
        );
    }

    if ($row === null || empty($row['img'])) {
        http_response_code(404);
        exit;
    }

    // パストラバーサル対策: ベースネームのみ使用（ディレクトリ部分は捨てる）
    $imageFile = basename((string)$row['img']);
    if ($imageFile === '' || $imageFile === '.' || $imageFile === '..') {
        http_response_code(400);
        exit;
    }

    $baseDir = realpath(__DIR__ . '/../uploads/products');
    if ($baseDir === false) {
        http_response_code(404);
        exit;
    }

    // 拡張子フォールバック:
    //   DB の image_path と実ファイルの拡張子が一致しない場合
    //   （例: DB=「DB-005.jpg」だが実体は DB-005.png）でも見つける。
    //   許容拡張子: jpg/jpeg/png/gif/webp（白リスト）
    $candidate = resolveProductImagePath($baseDir, $imageFile);

    if ($candidate === null) {
        http_response_code(404);
        exit;
    }

    // 解決後パスが uploads/products/ 配下であることを確認
    if (strncmp($candidate, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0) {
        http_response_code(403);
        exit;
    }

    if (!is_file($candidate) || !is_readable($candidate)) {
        http_response_code(404);
        exit;
    }

    $mimeType = mime_content_type($candidate) ?: 'application/octet-stream';
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(403);
        exit;
    }

    // 一覧用サムネイル（作れない場合は元画像をそのまま返す）
    $outFile = $candidate;
    if (($_GET['size'] ?? '') === 'thumb') {
        $thumb = getProductThumbPath($baseDir, $candidate, $mimeType);
        if ($thumb !== null) {
            $outFile = $thumb;
            $mimeType = 'image/jpeg';
        }
    }

    // ETag / Last-Modified で再表示時は 304 を返す（画像を差し替えると値が変わる）
    $mtime = (int)filemtime($outFile);
    $etag = '"' . sha1($outFile . '|' . $mtime . '|' . filesize($outFile)) . '"';
    header('Cache-Control: private, max-age=3600');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($outFile));

    readfile($outFile);
    exit;
} catch (Throwable $e) {
    error_log('Product image API error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

/**
 * 商品画像ファイルパスを解決する。
 *
 * まず指定ファイル名で検索し、見つからなければ拡張子を差し替えて
 * jpg / jpeg / png / gif / webp を順に試す。
 * 既に拡張子が一致する候補があれば即返す。
 *
 * @return string|null realpath されたファイルパス or null（見つからない場合）
 */
function resolveProductImagePath(string $baseDir, string $imageFile): ?string
{
    // 1) 指定された通りのファイル名を試す
    $direct = realpath($baseDir . DIRECTORY_SEPARATOR . $imageFile);
    if ($direct !== false && is_file($direct)) {
        return $direct;
    }

    // 2) 拡張子を差し替えてフォールバック
    $baseName = pathinfo($imageFile, PATHINFO_FILENAME); // 拡張子無し部分
    if ($baseName === '' || $baseName === '.' || $baseName === '..') {
        return null;
    }
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    foreach ($extensions as $ext) {
        $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . $baseName . '.' . $ext);
        if ($candidate !== false && is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * 一覧カード用サムネイルのパスを返す（無ければ作成）。
 * GD が無い・GIF（アニメーションの可能性）・元画像が十分小さい・書き込み不可 などの場合は null（元画像を使う）。
 */
function getProductThumbPath(string $baseDir, string $source, string $mimeType): ?string
{
    $maxW = 560;  // カード最大幅 約280px × Retina 2倍
    $maxH = 316;  // 16:9
    if (!function_exists('imagecreatetruecolor') || $mimeType === 'image/gif') {
        return null;
    }
    $info = @getimagesize($source);
    if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
        return null;
    }
    [$w, $h] = $info;
    $scale = min($maxW / $w, $maxH / $h, 1.0);
    if ($scale >= 1.0 && filesize($source) <= 150 * 1024) {
        return null;
    }

    $thumbDir = $baseDir . DIRECTORY_SEPARATOR . '_thumbs';
    $thumbFile = $thumbDir . DIRECTORY_SEPARATOR
        . sha1(basename($source) . '|' . filemtime($source) . '|' . filesize($source) . '|' . $maxW . 'x' . $maxH) . '.jpg';
    // 縮小しても元より大きくなる場合（元が小さいPNGなど）は元画像を使う
    if (is_file($thumbFile)) {
        return filesize($thumbFile) < filesize($source) ? $thumbFile : null;
    }
    if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0755) && !is_dir($thumbDir)) {
        return null;
    }

    $src = match ($mimeType) {
        'image/jpeg' => @imagecreatefromjpeg($source),
        'image/png'  => @imagecreatefrompng($source),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default      => false,
    };
    if ($src === false) {
        return null;
    }
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));  // 透過部分は白（カード背景と同じ）
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $tmp = $thumbFile . '.' . getmypid() . '.tmp';
    $ok = @imagejpeg($dst, $tmp, 82) && @rename($tmp, $thumbFile);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }
    clearstatcache(true, $thumbFile);
    return filesize($thumbFile) < filesize($source) ? $thumbFile : null;
}
