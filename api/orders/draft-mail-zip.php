<?php declare(strict_types=1);

/**
 * 快活システム - メール添付一式zipダウンロードAPI
 *
 * GET /api/orders/draft-mail-zip.php/{ファイル名}.zip?ids={発注番号,カンマ区切り}
 *   - メール下書きの「添付ファイルを一式ダウンロード」用（admin/system のみ）
 *   - 対象: repair / parts / seat-replacement / chair-repair（ids は同一店舗・同一種別）
 *   - 中身:
 *       修理         : 修理依頼書_{Ymd}_{店舗名}.xlsx ＋ 写真全件
 *       部品         : 部品発注依頼書_{Ymd}_{店舗名}.xlsx ＋ 写真全件
 *       シート交換   : シート発注依頼書_{Ymd}_{店舗名}.xlsx ＋ 写真全件
 *       チェア修理   : 写真全件のみ（依頼書ひな形なし・メーカー書式未定）
 *     写真のファイル名は「発注番号_写真種別_連番.拡張子」で自動付与（photo.php?dl=1 と同一規則）
 *   - URL末尾のファイル名セグメントは PATH_INFO（サーバでは無視・ブラウザの保存名用）
 *
 * ファイル名: 添付一式_{Ymd}_{店舗名}.zip
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/request_sheet.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

requireMethod('GET');
requireLogin();

$user = getCurrentUser();
if (!in_array($user['role'], ['admin', 'system'], true)) {
    jsonError('権限がありません', 403);
}
if (!class_exists('ZipArchive')) {
    jsonError('サーバのzip拡張が有効ではありません', 500);
}

try {
    $orders = fetchOrdersForAttachment(
        trim((string)($_GET['ids'] ?? ($_GET['id'] ?? ''))),
        ['repair', 'parts', 'seat-replacement', 'chair-repair']
    );
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage());
}

$type     = $orders[0]['type'];
$shopName = $orders[0]['shop_name'];
$orderIds = array_column($orders, 'id');

// --- 写真一覧（全種別・damage/serial 両方。DL名は photo.php?dl=1 と同一規則） ---
$ph = [];
$params = [];
foreach ($orderIds as $i => $oid) {
    $ph[] = ':zid' . $i;
    $params[':zid' . $i] = $oid;
}
$photoRows = query(
    "SELECT order_id, photo_kind, file_path, original_filename
       FROM order_photos
      WHERE order_id IN (" . implode(',', $ph) . ")
      ORDER BY order_id, (COALESCE(photo_kind, 'damage') = 'serial'), sort_order, id",
    $params
);

$baseDir = realpath(__DIR__ . '/../../uploads');
$photoEntries = []; // [ [abs_path, zip_name], ... ]
$damageSeq = [];
foreach ($photoRows as $p) {
    $abs = realpath(__DIR__ . '/../../' . $p['file_path']);
    if ($abs === false || $baseDir === false
        || strncmp($abs, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) !== 0
        || !is_file($abs)) {
        continue;
    }
    $oid  = $p['order_id'];
    $kind = $p['photo_kind'] ?: 'damage';
    $ext  = strtolower(pathinfo((string)$p['original_filename'], PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'jpg';
    }
    if ($kind === 'serial') {
        $zipName = sprintf('%s_シリアル.%s', $oid, $ext);
    } else {
        $damageSeq[$oid] = ($damageSeq[$oid] ?? 0) + 1;
        $zipName = sprintf('%s_故障箇所_%d.%s', $oid, $damageSeq[$oid], $ext);
    }
    $photoEntries[] = [$abs, $zipName];
}

// --- 依頼書Excel（repair / seat-replacement のみ）を一時ファイルに生成 ---
$sheetTmp  = null;
$sheetName = null;
if (in_array($type, ['repair', 'parts', 'seat-replacement'], true)) {
    try {
        $spreadsheet = buildRequestSheetSpreadsheet($orders);
        $sheetTmp = tempnam(sys_get_temp_dir(), 'kreq');
        $writer = new Xlsx($spreadsheet);
        $writer->save($sheetTmp);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        $docLabel  = $type === 'repair' ? '修理依頼書' : ($type === 'parts' ? '部品発注依頼書' : 'シート発注依頼書');
        $sheetName = sprintf('%s_%s_%s.xlsx', $docLabel, date('Ymd'), $shopName);
    } catch (Throwable $e) {
        if ($sheetTmp !== null && is_file($sheetTmp)) {
            @unlink($sheetTmp);
        }
        error_log('draft-mail-zip request sheet error: ' . $e->getMessage());
        jsonError('依頼書の生成に失敗しました', 500);
    }
}

if (empty($photoEntries) && $sheetTmp === null) {
    jsonError('添付対象のファイルがありません', 404);
}

// --- zip作成 ---
$zipTmp = tempnam(sys_get_temp_dir(), 'kzip');
$zip = new ZipArchive();
if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
    if ($sheetTmp !== null) @unlink($sheetTmp);
    @unlink($zipTmp);
    jsonError('zipの作成に失敗しました', 500);
}
if ($sheetTmp !== null && $sheetName !== null) {
    $zip->addFile($sheetTmp, $sheetName);
}
foreach ($photoEntries as [$abs, $zipName]) {
    $zip->addFile($abs, $zipName);
}
$zip->close();

$filename = sprintf('添付一式_%s_%s.zip', date('Ymd'), $shopName);
$asciiFallback = sprintf('attachments_%s_%s.zip', $orders[0]['shop_code'], date('Ymd'));

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
if ($sheetTmp !== null) {
    @unlink($sheetTmp);
}
exit;
