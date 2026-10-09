<?php declare(strict_types=1);

/**
 * 快活システム - 修理依頼書 / シート発注依頼書 Excel生成API
 *
 * GET /api/orders/request-sheet.php/{ファイル名}.xlsx?ids={発注番号,カンマ区切り}
 *   - メール下書きの添付ファイル用（admin/system のみ）
 *   - 対象: type = repair（修理依頼書）/ chair-repair（マッサージチェア修理依頼書・宛名なし）
 *           / parts（部品発注依頼書）/ seat-replacement（シート発注依頼書）
 *   - ids は同一店舗・同一種別のみ（修理まとめ=同一店舗を1通にまとめる運用のため）。1発注=1シート
 *   - URL末尾のファイル名セグメントは PATH_INFO（サーバでは無視・ブラウザの保存名用）
 *   - 生成本体は includes/request_sheet.php（一式zip と共通）
 *
 * ファイル名: 修理依頼書_{Ymd}_{店舗名}.xlsx / シート発注依頼書_{Ymd}_{店舗名}.xlsx
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

try {
    $orders = fetchOrdersForAttachment(
        trim((string)($_GET['ids'] ?? ($_GET['id'] ?? ''))),
        ['repair', 'chair-repair', 'parts', 'seat-replacement']
    );
    $spreadsheet = buildRequestSheetSpreadsheet($orders);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage());
} catch (Throwable $e) {
    error_log('request-sheet error: ' . $e->getMessage());
    jsonError('依頼書の生成に失敗しました', 500);
}

$type      = $orders[0]['type'];
$docLabel  = [
    'repair'           => '修理依頼書',
    'chair-repair'     => 'マッサージチェア修理依頼書',
    'parts'            => '部品発注依頼書',
    'seat-replacement' => 'シート発注依頼書',
][$type];
$filename  = sprintf('%s_%s_%s.xlsx', $docLabel, date('Ymd'), $orders[0]['shop_name']);
$asciiFallback = sprintf('request_sheet_%s_%s.xlsx', $orders[0]['shop_code'], date('Ymd'));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header(sprintf(
    "Content-Disposition: attachment; filename=\"%s\"; filename*=UTF-8''%s",
    $asciiFallback,
    rawurlencode($filename)
));
header('Cache-Control: private, no-store');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
