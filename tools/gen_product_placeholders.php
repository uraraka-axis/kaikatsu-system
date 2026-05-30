<?php declare(strict_types=1);
/**
 * 全有効商品に仮画像（プレースホルダ）を3枚ずつ生成し、image_path/2/3 を登録する。
 *   ファイル名: {code}.png / {code}_1.png / {code}_2.png  （uploads/products/ 配下）
 *   GD で生成（コード・スロット・カテゴリを描画したダミー画像）。
 *
 * 使い方: php tools/gen_product_placeholders.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$dir = __DIR__ . '/../uploads/products';
if (!is_dir($dir)) { mkdir($dir, 0755, true); }

$products = query("SELECT id, code, name, category_code FROM products WHERE is_active = 1 ORDER BY code");
echo "対象商品: " . count($products) . "\n";

// カテゴリ別のベース色 [R,G,B]
$baseColor = [
    'fitness' => [37, 99, 235],   // 青
    'golf'    => [22, 163, 74],   // 緑
];
// スロットごとの明度差
$slotShade = [0 => 1.0, 1 => 0.82, 2 => 0.66];

function makeImage(string $path, string $code, int $slot, string $cat, array $base): void {
    $W = 480; $H = 360;
    $im = imagecreatetruecolor($W, $H);
    $shade = [1.0, 0.82, 0.66][$slot] ?? 1.0;
    $bg = imagecolorallocate($im, (int)($base[0]*$shade), (int)($base[1]*$shade), (int)($base[2]*$shade));
    $white = imagecolorallocate($im, 255, 255, 255);
    $panel = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, $W, $H, $bg);
    // 中央パネル
    imagefilledrectangle($im, 30, 30, $W-30, $H-30, imagecolorallocatealpha($im, 255,255,255,90));
    // テキスト（GD組み込みフォント=ASCII）
    $f = 5;
    imagestring($im, $f, 50, 60,  'SAMPLE IMAGE', $white);
    imagestring($im, $f, 50, 130, 'CODE: ' . $code, $white);
    imagestring($im, $f, 50, 170, 'SLOT: ' . ($slot + 1) . ' / 3', $white);
    imagestring($im, $f, 50, 210, 'CATEGORY: ' . strtoupper($cat), $white);
    imagestring($im, 3, 50, 300, '(placeholder for development)', $white);
    imagepng($im, $path);
    imagedestroy($im);
}

$n = 0;
foreach ($products as $p) {
    $code = $p['code'];
    $cat  = $p['category_code'];
    $base = $baseColor[$cat] ?? [100, 116, 139];
    $files = [$code . '.png', $code . '_1.png', $code . '_2.png'];
    foreach ($files as $slot => $fname) {
        makeImage($dir . '/' . $fname, $code, $slot, $cat, $base);
    }
    execute(
        "UPDATE products SET image_path = :a, image_path2 = :b, image_path3 = :c WHERE id = :id",
        [':a' => $files[0], ':b' => $files[1], ':c' => $files[2], ':id' => (int)$p['id']]
    );
    $n++;
}
echo "生成・登録完了: {$n} 商品 × 3枚 = " . ($n*3) . " ファイル\n";
