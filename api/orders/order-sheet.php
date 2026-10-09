<?php declare(strict_types=1);

/**
 * 快活システム - マッサージチェア備品発注書PDF生成API
 *
 * GET /api/orders/order-sheet.php?id={発注番号}&dl=1
 *   - 対象: type = 'chair-equipment' の発注のみ
 *   - レイアウトはモック 03_massage-order-pdf.html（A4縦・1発注1枚）
 *   - チェア備品12品目を常に全行表示し、発注分のみ数量・金額を入れる
 *   - 単価は発注明細の保存単価（admin編集後の値）を優先し、未発注品目は商品マスタ単価
 *   - ファイル名: マッサージチェア備品発注書_{店舗コード}_{店舗名}_{yyyymmdd}.pdf
 *   - dl=1 でダウンロード（既定はブラウザ内表示）
 *   - PDF組み立ては includes/pdf_sheets.php（一式zip と共通）
 *
 * 権限: admin/system は全店舗、shop は自店のみ、zone/area は管轄店舗のみ
 * 日本語フォント: assets/fonts/ipaexg.ttf（IPAexゴシック・サブセット埋め込み）
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf_sheets.php';

requireMethod('GET');
requireLogin();

$orderId = trim((string)($_GET['id'] ?? ''));
if ($orderId === '' || !preg_match('/^[A-Z]{3}-[A-Za-z0-9]+-\d{8}-\d{4}$/', $orderId)) {
    jsonError('発注番号が不正です');
}

$order = fetchChairOrderRow($orderId);
if ($order === null) {
    jsonError('発注が見つかりません', 404);
}
if ($order['type'] !== 'chair-equipment') {
    jsonError('チェア備品発注のみ発注書を出力できます');
}

// --- 閲覧スコープ（photo.php と同一ルール） ---
$user = getCurrentUser();
$allowed = false;
if (in_array($user['role'], ['admin', 'system'], true)) {
    $allowed = true;
} elseif ($user['role'] === 'shop') {
    $allowed = ($user['shop_code'] === $order['shop_code']);
} elseif ($user['role'] === 'zone' && !empty($user['zone_code'])) {
    $check = getOne(
        'SELECT 1 FROM shops s
          JOIN areas a ON s.area_code = a.code
         WHERE s.code = :sc AND a.zone_code = :zc',
        [':sc' => $order['shop_code'], ':zc' => $user['zone_code']]
    );
    $allowed = ($check !== null);
} elseif ($user['role'] === 'area' && !empty($user['area_code'])) {
    $check = getOne(
        'SELECT 1 FROM shops WHERE code = :sc AND area_code = :ac',
        [':sc' => $order['shop_code'], ':ac' => $user['area_code']]
    );
    $allowed = ($check !== null);
}
if (!$allowed) {
    jsonError('権限がありません', 403);
}

$pdf = renderA4PortraitPdf(buildChairOrderSheetHtml($order));

$orderDate = new DateTimeImmutable($order['date']);
$filename = chairOrderSheetFilename($order);
$asciiFallback = sprintf('chair_order_sheet_%s_%s.pdf', $order['shop_code'], $orderDate->format('Ymd'));
$disposition = (($_GET['dl'] ?? '') === '1') ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header(sprintf(
    "Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s",
    $disposition,
    $asciiFallback,
    rawurlencode($filename)
));
header('Cache-Control: private, no-store');
echo $pdf;
