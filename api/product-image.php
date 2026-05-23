<?php declare(strict_types=1);

/**
 * 快活システム - 商品画像配信API
 * GET /api/product-image.php?code={product_code}&slot={0|1|2}
 *   slot=0 (デフォルト): image_path (画像1)
 *   slot=1: image_path2 (画像2)
 *   slot=2: image_path3 (画像3)
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
    if ($user['role'] === 'admin') {
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

    $baseDir   = realpath(__DIR__ . '/../uploads/products');
    $candidate = $baseDir ? realpath($baseDir . DIRECTORY_SEPARATOR . $imageFile) : false;

    if ($baseDir === false || $candidate === false) {
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

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($candidate));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($candidate);
    exit;
} catch (Throwable $e) {
    error_log('Product image API error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
