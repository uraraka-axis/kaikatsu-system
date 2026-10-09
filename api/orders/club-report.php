<?php declare(strict_types=1);

/**
 * 快活システム - ゴルフクラブ破損状況報告書 兼 代替クラブ発送依頼書 PDF生成API
 *
 * GET /api/orders/club-report.php?id={発注番号}&dl=1
 *   - 対象: type = 'club-replacement' の発注のみ
 *   - レイアウトはモック 09_club-replacement-print.html（A4縦・1発注1枚）
 *   - 店舗情報（電話・郵便番号・住所）は shops マスタを最新参照
 *   - 初回出力時に order_club_replacement_details.report_printed_at を記録
 *     （完了報告ダイアログの「報告書未印刷」警告の判定に使用）
 *   - ファイル名: 代替クラブ発送依頼書_{店舗コード}_{店舗名}_{yyyymmdd}.pdf
 *   - dl=1 でダウンロード（既定はブラウザ内表示→印刷）
 *   - PDF組み立ては includes/pdf_sheets.php（一式zip と共通）
 *
 * 権限: admin/system は全店舗、shop は自店のみ、zone/area は管轄店舗のみ
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

$order = fetchClubOrderRow($orderId);
if ($order === null) {
    jsonError('発注が見つかりません', 404);
}
if ($order['type'] !== 'club-replacement') {
    jsonError('代替ゴルフクラブ発送依頼のみ報告書を出力できます');
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

$pdf = renderA4PortraitPdf(buildClubReportHtml($order));

// 初回出力を記録（完了報告の「報告書未印刷」警告の判定に使用）。
// 報告書は店舗が破損クラブに同封して返送するものなので、
// 「店舗自身が印刷したときだけ」記録する（本部が印刷しても警告は消えない）。
if ($user['role'] === 'shop' && $user['shop_code'] === $order['shop_code']) {
    execute(
        'UPDATE order_club_replacement_details
            SET report_printed_at = COALESCE(report_printed_at, NOW())
          WHERE order_id = :oid',
        [':oid' => $orderId]
    );
}

$orderDate = new DateTimeImmutable($order['date']);
$filename = clubReportFilename($order);
$asciiFallback = sprintf('club_report_%s_%s.pdf', $order['shop_code'], $orderDate->format('Ymd'));
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
