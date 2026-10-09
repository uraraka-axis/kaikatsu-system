<?php declare(strict_types=1);

/**
 * 快活システム - 発注書/依頼書PDF一式zipダウンロードAPI
 *
 * GET /api/orders/pdf-sheet-zip.php/{ファイル名}.zip?ids={発注番号,カンマ区切り}
 *   - メール下書き（チェア備品・代替ゴルフ）の「添付ファイルを一式ダウンロード」用（admin/system のみ）
 *   - 対象: type = chair-equipment（マッサージチェア備品発注書）/ club-replacement（代替クラブ発送依頼書）
 *     ids は同一種別のみ（店舗はまたいでよい＝1仕入先にまとめて依頼する運用のため）
 *   - 中身: 1発注=1枚のPDF（単体DLと同じファイル名。重複時は発注番号を付加）
 *   - URL末尾のファイル名セグメントは PATH_INFO（サーバでは無視・ブラウザの保存名用）
 *   - PDF組み立ては includes/pdf_sheets.php（単体DLと共通）
 *
 * ファイル名: 添付一式_{チェア備品|代替ゴルフ}_{Ymd}_{店舗名またはN店舗}.zip
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf_sheets.php';

requireMethod('GET');
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'system'], true)) {
    jsonError('権限がありません', 403);
}
if (!class_exists('ZipArchive')) {
    jsonError('サーバのzip拡張が有効ではありません', 500);
}

// --- ids 検証 ---
$orderIds = [];
foreach (explode(',', trim((string)($_GET['ids'] ?? ''))) as $idStr) {
    $idStr = trim($idStr);
    if ($idStr !== '' && preg_match('/\A[A-Za-z0-9\-]{1,30}\z/', $idStr)) {
        $orderIds[] = $idStr;
    }
}
$orderIds = array_values(array_unique($orderIds));
if (empty($orderIds)) {
    jsonError('発注番号が指定されていません');
}
if (count($orderIds) > 20) {
    jsonError('一度に出力できるのは20件までです');
}

// --- 発注取得・種別検証（同一種別のみ。店舗はまたいでよい） ---
$orders = [];
$type = null;
$shopNames = [];
foreach ($orderIds as $oid) {
    $row = getOne(
        'SELECT o.id, o.type, o.cancelled_at, s.name AS shop_name
           FROM orders o JOIN shops s ON s.code = o.shop_code
          WHERE o.id = :id',
        [':id' => $oid]
    );
    if ($row === null) {
        jsonError('発注が見つかりません: ' . $oid, 404);
    }
    if (!in_array($row['type'], ['chair-equipment', 'club-replacement'], true)) {
        jsonError('この種別の発注には対応していません');
    }
    if ($type === null) {
        $type = $row['type'];
    } elseif ($row['type'] !== $type) {
        jsonError('種別の異なる発注はまとめて出力できません');
    }
    if ($row['cancelled_at'] !== null) {
        jsonError('取消された発注が含まれています');
    }
    $orders[] = $row;
    $shopNames[$row['shop_name']] = true;
}

// --- 1発注=1枚のPDFを生成してzipへ ---
$zipTmp = tempnam(sys_get_temp_dir(), 'kpdfz');
$zip = new ZipArchive();
if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($zipTmp);
    jsonError('zipの作成に失敗しました', 500);
}

try {
    $usedNames = [];
    foreach ($orders as $o) {
        if ($type === 'chair-equipment') {
            $full = fetchChairOrderRow($o['id']);
            $pdf  = renderA4PortraitPdf(buildChairOrderSheetHtml($full));
            $name = chairOrderSheetFilename($full);
        } else {
            $full = fetchClubOrderRow($o['id']);
            $pdf  = renderA4PortraitPdf(buildClubReportHtml($full));
            $name = clubReportFilename($full);
        }
        // zipエントリ名にパス区切りが混入しないよう除去（zip slip対策）＋同名重複は発注番号を付加
        $name = str_replace(['/', '\\'], '', $name);
        if (isset($usedNames[$name])) {
            $name = str_replace('.pdf', '_' . $o['id'] . '.pdf', $name);
        }
        $usedNames[$name] = true;
        $zip->addFromString($name, $pdf);
    }
    $zip->close();
} catch (Throwable $e) {
    @unlink($zipTmp);
    error_log('pdf-sheet-zip error: ' . $e->getMessage());
    jsonError('PDFの生成に失敗しました', 500);
}

// ファイル名: 添付一式_{種別}_{Ymd}_{店舗名}.zip（複数店舗のときは「N店舗」）
$typeLabel = $type === 'chair-equipment' ? 'チェア備品' : '代替ゴルフ';
$shopPart  = count($shopNames) === 1 ? array_key_first($shopNames) : count($shopNames) . '店舗';
$shopPart  = str_replace(['/', '\\'], '', $shopPart);
$filename = sprintf('添付一式_%s_%s_%s.zip', $typeLabel, date('Ymd'), $shopPart);
$asciiFallback = sprintf('%s_sheets_%s.zip', $type === 'chair-equipment' ? 'chair' : 'club', date('Ymd'));

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipTmp));
header(sprintf(
    "Content-Disposition: attachment; filename=\"%s\"; filename*=UTF-8''%s",
    $asciiFallback,
    rawurlencode($filename)
));
header('Cache-Control: private, no-store');

readfile($zipTmp);
@unlink($zipTmp);
exit;
