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
 *
 * 権限: admin/system は全店舗、shop は自店のみ、zone/area は管轄店舗のみ
 * 日本語フォント: assets/fonts/ipaexg.ttf（IPAexゴシック・サブセット埋め込み）
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
            s.name AS shop_name, s.phone, s.postal_code, s.address
       FROM orders o
       JOIN shops s ON s.code = o.shop_code
      WHERE o.id = :id',
    [':id' => $orderId]
);
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

// --- チェア備品12品目（マスタ順）＋この発注の明細 ---
$products = query(
    'SELECT id, name, price FROM products
      WHERE is_chair_item = 1 AND is_active = 1
      ORDER BY code'
);
$items = query(
    'SELECT product_id, price, qty FROM order_equipment_items WHERE order_id = :oid',
    [':oid' => $orderId]
);
$itemMap = [];
foreach ($items as $it) {
    $itemMap[(int)$it['product_id']] = $it;
}

// --- 行データ組み立て（発注明細の単価を優先） ---
$rows = [];
$grandTotal = 0;
foreach ($products as $i => $p) {
    $item  = $itemMap[(int)$p['id']] ?? null;
    $qty   = $item !== null ? (int)$item['qty'] : 0;
    $price = $item !== null ? (int)$item['price'] : (int)$p['price'];
    $subtotal = $price * $qty;
    $grandTotal += $subtotal;
    $rows[] = [
        'no'       => mb_chr(0x2460 + $i, 'UTF-8'), // ①〜⑫
        'name'     => $p['name'],
        'price'    => $price,
        'qty'      => $qty,
        'subtotal' => $subtotal,
    ];
}

// --- HTML生成（モック03のレイアウト） ---
$fontPath = str_replace('\\', '/', realpath(__DIR__ . '/../../assets/fonts/ipaexg.ttf'));
$orderDate = new DateTimeImmutable($order['date']);
$dateLabel = sprintf('%d年%d月%d日', (int)$orderDate->format('Y'), (int)$orderDate->format('n'), (int)$orderDate->format('j'));

$postal  = $order['postal_code'] !== null && $order['postal_code'] !== '' ? '〒' . $order['postal_code'] : '';
$address = $order['address'] ?? '';
$phone   = $order['phone'] ?? '';

$bodyRows = '';
foreach ($rows as $r) {
    $bodyRows .= '<tr>'
        . '<td>' . h($r['no'] . $r['name']) . '</td>'
        . '<td class="num">' . h(formatCurrency($r['price'])) . '</td>'
        . '<td class="qty">' . ($r['qty'] > 0 ? h((string)$r['qty']) : '') . '</td>'
        . '<td class="num">' . h(formatCurrency($r['subtotal'])) . '</td>'
        . '</tr>';
}

$cancelNote = $order['cancelled_at'] !== null
    ? '<div style="color:#b91c1c; font-weight:bold; margin:0 0 8px;">※ この発注は取消されています</div>'
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
h1 { font-size: 16pt; font-weight: bold; margin: 0 0 4px; }
.sheet-sub { font-size: 8.5pt; color: #444; text-align: right; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #333; padding: 6px 8px; font-size: 10pt; }
th { background: #f1f5f9; text-align: left; width: 80px; }
td.val { background: #fefce8; }
.num { text-align: right; }
table.items { margin-top: 18px; }
table.items th { background: #ffffff; font-weight: bold; width: auto; }
table.items td.qty { background: #fefce8; text-align: center; width: 55px; }
tr.total td { font-weight: bold; font-size: 11pt; }
</style>
</head>
<body>
<h1>マッサージチェア備品発注書</h1>
<div class="sheet-sub">発注日：{$dateLabel}</div>
{$cancelNote}
<table>
  <tr><th>店舗名</th><td style="width:100px;">FiT24</td><td class="val">%%SHOP_NAME%%</td></tr>
  <tr><th>住所</th><td class="val" style="width:100px;">%%POSTAL%%</td><td class="val">%%ADDRESS%%</td></tr>
  <tr><th>電話番号</th><td class="val" colspan="2">%%PHONE%%</td></tr>
  <tr><th>店舗コード</th><td class="val" colspan="2">%%SHOP_CODE%%</td></tr>
</table>
<table class="items">
  <thead>
    <tr><th style="width:46%">商品名</th><th class="num">単価（税抜）</th><th style="width:55px; text-align:center;">数量</th><th class="num">合計</th></tr>
  </thead>
  <tbody>
    {$bodyRows}
    <tr class="total"><td colspan="3" style="text-align:right;">計</td><td class="num">%%TOTAL%%</td></tr>
  </tbody>
</table>
</body>
</html>
HTML;

$html = str_replace(
    ['%%SHOP_NAME%%', '%%POSTAL%%', '%%ADDRESS%%', '%%PHONE%%', '%%SHOP_CODE%%', '%%TOTAL%%'],
    [h($order['shop_name']), h($postal), h($address), h($phone), h($order['shop_code']), h(formatCurrency($grandTotal))],
    $html
);

// --- dompdf でPDF化 ---
$fontCacheDir = __DIR__ . '/../../uploads/dompdf-fonts';
if (!is_dir($fontCacheDir)) {
    mkdir($fontCacheDir, 0775, true);
}

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isFontSubsettingEnabled', true); // IPAexは約6MBあるため必ずサブセット化
$options->setFontDir($fontCacheDir);
$options->setFontCache($fontCacheDir);
$options->setChroot(realpath(__DIR__ . '/../../'));

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = sprintf(
    'マッサージチェア備品発注書_%s_%s_%s.pdf',
    $order['shop_code'],
    $order['shop_name'],
    $orderDate->format('Ymd')
);
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
echo $dompdf->output();
