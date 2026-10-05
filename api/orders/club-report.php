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
 *
 * 権限: admin/system は全店舗、shop は自店のみ、zone/area は管轄店舗のみ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireMethod('GET');
requireLogin();

$orderId = trim((string)($_GET['id'] ?? ''));
if ($orderId === '' || !preg_match('/^[A-Z]{3}-[A-Za-z0-9]+-\d{8}-\d{4}$/', $orderId)) {
    jsonError('発注番号が不正です');
}

$order = getOne(
    'SELECT o.id, o.type, o.shop_code, o.date, o.cancelled_at,
            s.name AS shop_name, s.phone, s.postal_code, s.address,
            d.club, d.shaft, d.damage
       FROM orders o
       JOIN shops s ON s.code = o.shop_code
       JOIN order_club_replacement_details d ON d.order_id = o.id
      WHERE o.id = :id',
    [':id' => $orderId]
);
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

// --- HTML生成（モック09のレイアウト） ---
$fontPath = str_replace('\\', '/', realpath(__DIR__ . '/../../assets/fonts/ipaexg.ttf'));

$postal  = $order['postal_code'] ?? '';
$address = $order['address'] ?? '';
$phone   = $order['phone'] ?? '';

$cancelNote = $order['cancelled_at'] !== null
    ? '<div style="color:#b91c1c; font-weight:bold; margin:0 0 8px;">※ この依頼は取消されています</div>'
    : '';

$html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<style>
@font-face { font-family: 'ipaexg'; src: url('file://{$fontPath}') format('truetype'); font-weight: normal; font-style: normal; }
@font-face { font-family: 'ipaexg'; src: url('file://{$fontPath}') format('truetype'); font-weight: bold; font-style: normal; }
@page { size: A4 portrait; margin: 18mm 16mm; }
body { font-family: 'ipaexg', sans-serif; color: #111; font-size: 10.5pt; }
h1 { font-size: 15pt; font-weight: bold; line-height: 1.5; margin: 0 0 16px; }
table.fields { border-collapse: separate; border-spacing: 0 0; width: 100%; margin-bottom: 4px; }
.lbl { font-size: 7.5pt; color: #555; padding: 6px 4px 2px 1px; }
.box { border: 1px solid #444; padding: 5px 7px; font-size: 10pt; background: #fff; }
.multi { white-space: pre-wrap; line-height: 1.7; min-height: 60pt; }
.foot { margin-top: 10px; font-size: 8pt; color: #333; line-height: 1.7; }
</style>
</head>
<body>
<h1>ゴルフクラブ破損状況報告書<br>兼<br>代替クラブ発送依頼書</h1>
{$cancelNote}
<table class="fields">
  <tr>
    <td style="width:22%"><div class="lbl">店舗番号</div><div class="box">%%SHOP_CODE%%</div></td>
    <td style="width:3%"></td>
    <td style="width:30%"><div class="lbl">店舗名</div><div class="box">%%SHOP_NAME%%</div></td>
    <td style="width:3%"></td>
    <td style="width:24%"><div class="lbl">電話番号</div><div class="box">%%PHONE%%</div></td>
    <td style="width:3%"></td>
    <td style="width:15%"><div class="lbl">日付</div><div class="box">%%DATE%%</div></td>
  </tr>
</table>
<table class="fields">
  <tr>
    <td style="width:22%"><div class="lbl">郵便番号</div><div class="box">%%POSTAL%%</div></td>
    <td style="width:3%"></td>
    <td style="width:75%"><div class="lbl">住所</div><div class="box">%%ADDRESS%%</div></td>
  </tr>
</table>
<table class="fields">
  <tr>
    <td style="width:30%"><div class="lbl">破損クラブ</div><div class="box">%%CLUB%%</div></td>
    <td style="width:3%"></td>
    <td style="width:22%"><div class="lbl">シャフト</div><div class="box">%%SHAFT%%</div></td>
    <td style="width:45%"></td>
  </tr>
</table>
<table class="fields">
  <tr>
    <td style="width:75%"><div class="lbl">破損状況</div><div class="box multi">%%DAMAGE%%</div></td>
    <td style="width:25%"></td>
  </tr>
</table>
<div class="foot">
※ どこが、どのように破損したかわかるように記載をお願い致します。<br>
例：ヘッドにヒビが入っている、シャフトが曲がっている、グリップが擦れていてすべる、等
</div>
</body>
</html>
HTML;

// 空欄は &nbsp; にしてボックスの高さを保つ（電話なし店舗など）
$cell = static fn(?string $v): string => ($v === null || trim($v) === '') ? '&nbsp;' : h($v);

$html = str_replace(
    ['%%SHOP_CODE%%', '%%SHOP_NAME%%', '%%PHONE%%', '%%DATE%%', '%%POSTAL%%', '%%ADDRESS%%', '%%CLUB%%', '%%SHAFT%%', '%%DAMAGE%%'],
    [$cell($order['shop_code']), $cell($order['shop_name']), $cell($phone), $cell($order['date']), $cell($postal), $cell($address),
     $cell($order['club']), $cell($order['shaft']), $cell($order['damage'])],
    $html
);

// --- dompdf でPDF化 ---
$fontCacheDir = __DIR__ . '/../../uploads/dompdf-fonts';
if (!is_dir($fontCacheDir)) {
    mkdir($fontCacheDir, 0775, true);
}

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isFontSubsettingEnabled', true);
$options->setFontDir($fontCacheDir);
$options->setFontCache($fontCacheDir);
$options->setChroot(realpath(__DIR__ . '/../../'));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

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
$filename = sprintf(
    '代替クラブ発送依頼書_%s_%s_%s.pdf',
    $order['shop_code'],
    $order['shop_name'],
    $orderDate->format('Ymd')
);
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
echo $dompdf->output();
