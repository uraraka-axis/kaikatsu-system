<?php declare(strict_types=1);

/**
 * 快活システム - 発注書・報告書PDF生成（共通処理）
 *
 * マッサージチェア備品発注書（モック03）と、ゴルフクラブ破損状況報告書 兼 代替クラブ発送依頼書（モック09）の
 * HTML組み立て＋dompdf化を共通化する。
 *
 * 利用箇所:
 *   - api/orders/order-sheet.php   （チェア備品発注書 単体DL）
 *   - api/orders/club-report.php   （代替クラブ発送依頼書 単体DL・印刷記録は呼び出し側）
 *   - api/orders/pdf-sheet-zip.php （メール下書きの一式zip）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A4縦のHTMLをdompdfでPDFバイト列にする（IPAexゴシック・サブセット埋め込み）。
 */
function renderA4PortraitPdf(string $html): string
{
    $fontCacheDir = __DIR__ . '/../uploads/dompdf-fonts';
    if (!is_dir($fontCacheDir)) {
        mkdir($fontCacheDir, 0775, true);
    }

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isFontSubsettingEnabled', true); // IPAexは約6MBあるため必ずサブセット化
    $options->setFontDir($fontCacheDir);
    $options->setFontCache($fontCacheDir);
    $options->setChroot(realpath(__DIR__ . '/../'));

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

// ========================================
// マッサージチェア備品発注書（type = chair-equipment）
// ========================================

/** 発注行＋店舗情報を取得（type検証は呼び出し側） */
function fetchChairOrderRow(string $orderId): ?array
{
    return getOne(
        'SELECT o.id, o.type, o.shop_code, o.date, o.cancelled_at,
                s.name AS shop_name, s.phone, s.postal_code, s.address
           FROM orders o
           JOIN shops s ON s.code = o.shop_code
          WHERE o.id = :id',
        [':id' => $orderId]
    );
}

/**
 * チェア備品発注書HTML（モック03レイアウト）。
 * 12品目を常に全行表示し、発注分のみ数量・金額。単価は発注明細の保存単価を優先。
 */
function buildChairOrderSheetHtml(array $order): string
{
    $products = query(
        'SELECT id, name, price FROM products
          WHERE is_chair_item = 1 AND is_active = 1
          ORDER BY code'
    );
    $items = query(
        'SELECT product_id, price, qty FROM order_equipment_items WHERE order_id = :oid',
        [':oid' => $order['id']]
    );
    $itemMap = [];
    foreach ($items as $it) {
        $itemMap[(int)$it['product_id']] = $it;
    }

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

    $fontPath = str_replace('\\', '/', realpath(__DIR__ . '/../assets/fonts/ipaexg.ttf'));
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
            . '<td class="num sub">' . h(formatCurrency($r['subtotal'])) . '</td>'
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
/* 店舗情報の値セルは白（自動差し込みの固定情報のため。発注内容側を強調する配色に 2026-10-09） */
.num { text-align: right; }
table.items { margin-top: 18px; }
table.items th { background: #ffffff; font-weight: bold; width: auto; }
table.items td.qty, table.items td.sub { background: #fefce8; } /* 発注数量と金額だけ強調（商品名・単価は白） */
table.items td.qty { text-align: center; width: 55px; }
table.items tr.total td { background: #ffffff; font-weight: bold; font-size: 11pt; }
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

    return str_replace(
        ['%%SHOP_NAME%%', '%%POSTAL%%', '%%ADDRESS%%', '%%PHONE%%', '%%SHOP_CODE%%', '%%TOTAL%%'],
        [h($order['shop_name']), h($postal), h($address), h($phone), h($order['shop_code']), h(formatCurrency($grandTotal))],
        $html
    );
}

/** ファイル名: マッサージチェア備品発注書_{店舗コード}_{店舗名}_{yyyymmdd}.pdf */
function chairOrderSheetFilename(array $order): string
{
    return sprintf(
        'マッサージチェア備品発注書_%s_%s_%s.pdf',
        $order['shop_code'],
        $order['shop_name'],
        (new DateTimeImmutable($order['date']))->format('Ymd')
    );
}

// ========================================
// ゴルフクラブ破損状況報告書 兼 代替クラブ発送依頼書（type = club-replacement）
// ========================================

/** 発注行＋店舗情報＋詳細を取得（type検証は呼び出し側） */
function fetchClubOrderRow(string $orderId): ?array
{
    return getOne(
        'SELECT o.id, o.type, o.shop_code, o.date, o.cancelled_at,
                s.name AS shop_name, s.phone, s.postal_code, s.address,
                d.club, d.shaft, d.damage
           FROM orders o
           JOIN shops s ON s.code = o.shop_code
           JOIN order_club_replacement_details d ON d.order_id = o.id
          WHERE o.id = :id',
        [':id' => $orderId]
    );
}

/** 代替クラブ発送依頼書HTML（モック09レイアウト） */
function buildClubReportHtml(array $order): string
{
    $fontPath = str_replace('\\', '/', realpath(__DIR__ . '/../assets/fonts/ipaexg.ttf'));

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

    return str_replace(
        ['%%SHOP_CODE%%', '%%SHOP_NAME%%', '%%PHONE%%', '%%DATE%%', '%%POSTAL%%', '%%ADDRESS%%', '%%CLUB%%', '%%SHAFT%%', '%%DAMAGE%%'],
        [$cell($order['shop_code']), $cell($order['shop_name']), $cell($phone), $cell($order['date']), $cell($postal), $cell($address),
         $cell($order['club']), $cell($order['shaft']), $cell($order['damage'])],
        $html
    );
}

/** ファイル名: 代替クラブ発送依頼書_{店舗コード}_{店舗名}_{yyyymmdd}.pdf */
function clubReportFilename(array $order): string
{
    return sprintf(
        '代替クラブ発送依頼書_%s_%s_%s.pdf',
        $order['shop_code'],
        $order['shop_name'],
        (new DateTimeImmutable($order['date']))->format('Ymd')
    );
}
