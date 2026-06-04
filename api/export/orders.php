<?php declare(strict_types=1);

/**
 * 快活システム - 発注データExcel出力API
 *
 * GET /api/export/orders.php?type=&status=&shop=&zone=&area=&category=&date_from=&date_to=
 * - .xlsx形式（PhpSpreadsheet）
 * - 備品発注は明細行ごとに1行出力
 * - クエリパラメータは orders.php と同じ
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

requireLogin();
requireMethod('GET');

$user = getCurrentUser();

// --- パラメータ取得 ---
$type      = $_GET['type'] ?? '';
$status    = $_GET['status'] ?? '';
$shopCode  = $_GET['shop'] ?? '';
$zoneCode  = $_GET['zone'] ?? '';
$areaCode  = $_GET['area'] ?? '';
$category  = $_GET['category'] ?? '';
$dateFrom  = $_GET['date_from'] ?? '';
$dateTo    = $_GET['date_to'] ?? '';
$idsParam  = $_GET['ids'] ?? '';

// 選択行ID（カンマ区切り）。指定された場合は他フィルタより優先して該当発注のみ出力
// orders.id は VARCHAR(30) の発注番号（例: EQU-S04-20260523-0001）
$selectedIds = [];
if ($idsParam !== '') {
    foreach (explode(',', (string)$idsParam) as $idStr) {
        $idStr = trim($idStr);
        // 安全な発注番号フォーマットのみ許可（英数字とハイフン、30文字以内）
        if ($idStr !== '' && preg_match('/\A[A-Za-z0-9\-]{1,30}\z/', $idStr)) {
            $selectedIds[] = $idStr;
        }
    }
}

// --- ロール別の閲覧スコープ ---
// shop: 自店のみ / admin/system: 全店 / zone: 管轄ゾーン強制 / area: 管轄エリア強制
if ($user['role'] === 'shop') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
} elseif ($user['role'] === 'zone') {
    $zoneCode = $user['zone_code'] ?? '';
} elseif ($user['role'] === 'area') {
    $areaCode = $user['area_code'] ?? '';
    $zoneCode = '';
}

// --- バリデーション ---
if ($type !== '' && !in_array($type, ['repair', 'equipment', 'parts', 'seat-replacement'], true)) {
    jsonError('不正な種別パラメータです');
}
if ($status !== '' && !in_array($status, ['0', '1', '2', '3', '4'], true)) {
    jsonError('不正なステータスパラメータです');
}

// --- ラベルマッピング ---
$typeLabels = [
    'repair'           => '修理',
    'equipment'        => '備品',
    'parts'            => '部品',
    'seat-replacement' => 'シート交換',
];

$statusLabels = [
    0 => '依頼中',
    1 => '発注済',
    2 => '配達中/修理待ち',
    3 => '納品済/修理済',
    4 => '完了',
];

// カテゴリ名は categories マスタから取得（固定マップをやめ、カテゴリ増設に対応）
$categoryLabels = [];
foreach (query('SELECT code, name FROM categories') as $c) {
    $categoryLabels[$c['code']] = $c['name'];
}

// --- メインクエリ組み立て ---
$sql = 'SELECT o.id, o.type, o.category_code, o.status, o.shop_code, o.date,
               o.estimate_amount, o.final_amount, o.delivery_date, o.actual_delivery_date,
               o.created_at,
               s.name AS shop_name
        FROM orders o
        JOIN shops s ON o.shop_code = s.code';

$joins = [];
// 取消発注は Excel 出力から除外
$where = ['o.cancelled_at IS NULL'];
$params = [];

if ($zoneCode !== '') {
    $joins[] = 'JOIN areas a ON s.area_code = a.code';
    $where[] = 'a.zone_code = :zone_code';
    $params[':zone_code'] = $zoneCode;
}
if ($areaCode !== '') {
    $where[] = 's.area_code = :area_code';
    $params[':area_code'] = $areaCode;
}
if ($shopCode !== '') {
    $where[] = 'o.shop_code = :shop_code';
    $params[':shop_code'] = $shopCode;
}
if ($type !== '') {
    $where[] = 'o.type = :type';
    $params[':type'] = $type;
}
if ($status !== '') {
    $where[] = 'o.status = :status';
    $params[':status'] = (int)$status;
}
if ($category !== '') {
    $where[] = 'o.category_code = :category';
    $params[':category'] = $category;
}
if ($dateFrom !== '') {
    $where[] = 'o.date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'o.date <= :date_to';
    $params[':date_to'] = $dateTo;
}

// 選択された ID 群が指定されていれば、IN 句で絞り込み（店舗ユーザーは shop_code 制約も同時に効く）
if (!empty($selectedIds)) {
    $placeholders = [];
    foreach ($selectedIds as $i => $oid) {
        $key = ':sid_' . $i;
        $placeholders[] = $key;
        $params[$key] = $oid;
    }
    $where[] = 'o.id IN (' . implode(',', $placeholders) . ')';
}

if (!empty($joins)) {
    $sql .= ' ' . implode(' ', $joins);
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY o.date DESC, o.id DESC';

$orders = query($sql, $params);

// --- 関連データ取得 ---
$repairDetails          = [];
$seatReplacementDetails = [];
$partsDetails           = [];
$equipItems             = [];
$unavailDates           = []; // order_id => [ {date,is_all_day,time_start,time_end}, ... ] 修理・シート交換
$unavailDays            = []; // order_id => [ 'saturday', ... ]                         修理・シート交換
$photoCounts            = []; // order_id => 写真枚数

if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_map(fn($i) => ':oid' . $i, array_keys($orderIds)));
    $idParams = [];
    foreach ($orderIds as $i => $oid) {
        $idParams[':oid' . $i] = $oid;
    }

    $repairSql = "SELECT order_id, equipment_name, issue, repair_schedule_date, repair_completed_date
                  FROM order_repair_details
                  WHERE order_id IN ({$placeholders})";
    foreach (query($repairSql, $idParams) as $row) {
        $repairDetails[$row['order_id']] = $row;
    }

    $seatSql = "SELECT order_id, equipment_name, issue, repair_schedule_date, repair_completed_date
                FROM order_seat_replacement_details
                WHERE order_id IN ({$placeholders})";
    foreach (query($seatSql, $idParams) as $row) {
        $seatReplacementDetails[$row['order_id']] = $row;
    }

    $partsSql = "SELECT order_id, parts_name, target_equipment, reason, quantity
                 FROM order_parts_details
                 WHERE order_id IN ({$placeholders})";
    foreach (query($partsSql, $idParams) as $row) {
        $partsDetails[$row['order_id']] = $row;
    }

    // 仕入先商品コード(supplier_product_code) は order_equipment_items に
    // スナップショットされていないため products テーブルから JOIN で取得する
    $equipSql = "SELECT oei.order_id, oei.product_name, oei.product_code,
                        oei.price, oei.qty, oei.supplier,
                        p.supplier_product_code
                 FROM order_equipment_items oei
                 LEFT JOIN products p ON oei.product_id = p.id
                 WHERE oei.order_id IN ({$placeholders})
                 ORDER BY oei.id";
    foreach (query($equipSql, $idParams) as $row) {
        $equipItems[$row['order_id']][] = $row;
    }

    // 対応不可日（修理・シート交換）: 1発注に複数。終日 or 時間帯。
    $udSql = "SELECT order_id, date, is_all_day, time_start, time_end
              FROM order_repair_unavail_dates
              WHERE order_id IN ({$placeholders})
              ORDER BY order_id, date";
    foreach (query($udSql, $idParams) as $row) {
        $unavailDates[$row['order_id']][] = $row;
    }

    // 対応不可曜日（修理・シート交換）: 1発注に複数。
    $uwSql = "SELECT order_id, day_of_week
              FROM order_repair_unavail_days
              WHERE order_id IN ({$placeholders})";
    foreach (query($uwSql, $idParams) as $row) {
        $unavailDays[$row['order_id']][] = $row['day_of_week'];
    }

    // 写真枚数（全種別）
    $pcSql = "SELECT order_id, COUNT(*) AS cnt
              FROM order_photos
              WHERE order_id IN ({$placeholders})
              GROUP BY order_id";
    foreach (query($pcSql, $idParams) as $row) {
        $photoCounts[$row['order_id']] = (int)$row['cnt'];
    }
}

// --- フォーマットヘルパー ---
// 対応不可日: 終日 or 時間帯（HH:MM-HH:MM）。複数はセル内改行で列挙。
function fmtUnavailDates(?array $rows): string
{
    if (empty($rows)) {
        return '';
    }
    $parts = [];
    foreach ($rows as $r) {
        $d = date('Y/n/j', strtotime((string)$r['date']));
        if ((int)$r['is_all_day'] === 1) {
            $parts[] = $d . ' 終日';
        } else {
            $ts = $r['time_start'] ? substr((string)$r['time_start'], 0, 5) : '';
            $te = $r['time_end']   ? substr((string)$r['time_end'], 0, 5)   : '';
            $parts[] = trim($d . ' ' . $ts . '-' . $te);
        }
    }
    return implode("\n", $parts);
}

// 対応不可曜日: 英語→日本語、月〜日の順に並べ「・」連結。
function fmtUnavailDays(?array $days): string
{
    if (empty($days)) {
        return '';
    }
    static $map   = ['monday'=>'月','tuesday'=>'火','wednesday'=>'水','thursday'=>'木','friday'=>'金','saturday'=>'土','sunday'=>'日'];
    static $order = ['monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6,'sunday'=>7];
    $uniq = array_values(array_unique($days));
    usort($uniq, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    return implode('・', array_map(fn($d) => $map[$d] ?? $d, $uniq));
}

// 1シートを埋めるヘルパ（ヘッダ・データ・スタイル・幅・枠固定）。
//   $moneyIdx/$centerIdx/$wrapIdx は 0 始まりの列インデックス。
function fillSheet($sheet, array $headers, array $rows, array $moneyIdx, array $centerIdx, array $wrapIdx): void
{
    $colIdx  = fn(int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $lastCol = $colIdx(count($headers) - 1);

    // ヘッダ
    foreach ($headers as $i => $label) {
        $sheet->setCellValue($colIdx($i) . '1', $label);
    }
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(24);

    // データ（数値は数値型、その他は文字列型で明示セット）
    $r = 2;
    foreach ($rows as $row) {
        foreach ($row as $i => $val) {
            $type = is_int($val)
                ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
            $sheet->setCellValueExplicit($colIdx($i) . $r, $val, $type);
        }
        $r++;
    }
    $last = $r - 1;

    if ($last >= 2) {
        $sheet->getStyle("A2:{$lastCol}{$last}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'font' => ['size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach ($moneyIdx as $ci) {
            $L = $colIdx($ci);
            $sheet->getStyle("{$L}2:{$L}{$last}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("{$L}2:{$L}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        foreach ($centerIdx as $ci) {
            $L = $colIdx($ci);
            $sheet->getStyle("{$L}2:{$L}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        foreach ($wrapIdx as $ci) {
            $L = $colIdx($ci);
            $sheet->getStyle("{$L}2:{$L}{$last}")->getAlignment()->setWrapText(true);
        }
    }

    // 幅: wrap 列は固定幅、その他は自動幅
    $wrapSet = array_flip($wrapIdx);
    foreach ($headers as $i => $label) {
        $L = $colIdx($i);
        if (isset($wrapSet[$i])) {
            $sheet->getColumnDimension($L)->setWidth(26);
        } else {
            $sheet->getColumnDimension($L)->setAutoSize(true);
        }
    }

    $sheet->freezePane('A2');
    $sheet->setSelectedCell('A1');
}

// --- 種別ごとの行データ生成 ---
$shopOf = fn($o) => $o['shop_code'] . ':' . $o['shop_name'];
$catOf  = fn($o) => $categoryLabels[$o['category_code']] ?? $o['category_code'];
$stOf   = fn($o) => $statusLabels[(int)$o['status']] ?? (string)$o['status'];
$finOf  = fn($o) => $o['final_amount'] !== null ? (int)$o['final_amount'] : '';

// 種別ごとに発注を仕分け（メインクエリの並び順を維持）
$byType = ['equipment' => [], 'repair' => [], 'parts' => [], 'seat-replacement' => []];
foreach ($orders as $o) {
    if (isset($byType[$o['type']])) {
        $byType[$o['type']][] = $o;
    }
}

$sheetSpecs = [];

// 備品（明細1行）
$rows = [];
foreach ($byType['equipment'] as $o) {
    $items = $equipItems[$o['id']] ?? [];
    if (empty($items)) {
        $rows[] = [$o['date'], $o['id'], $shopOf($o), $catOf($o), '', '', '', '', '', '', '',
                   $o['delivery_date'] ?? '', $o['actual_delivery_date'] ?? '', $finOf($o), $stOf($o), $o['created_at']];
    } else {
        foreach ($items as $ei) {
            $rows[] = [
                $o['date'], $o['id'], $shopOf($o), $catOf($o),
                $ei['product_name'], $ei['product_code'] ?? '', $ei['supplier_product_code'] ?? '', $ei['supplier'] ?? '',
                (int)$ei['qty'], (int)$ei['price'], (int)$ei['price'] * (int)$ei['qty'],
                $o['delivery_date'] ?? '', $o['actual_delivery_date'] ?? '', $finOf($o), $stOf($o), $o['created_at'],
            ];
        }
    }
}
$sheetSpecs['equipment'] = [
    'title' => '備品',
    'headers' => ['発注日','発注番号','店舗','カテゴリ','品名','会社商品コード','仕入先商品コード','仕入先','数量','単価','小計','納品予定日','納品実績日','確定金額','ステータス','登録日時'],
    'rows' => $rows, 'money' => [9, 10, 13], 'center' => [8], 'wrap' => [],
];

// 修理
$rows = [];
foreach ($byType['repair'] as $o) {
    $rd = $repairDetails[$o['id']] ?? [];
    $rows[] = [
        $o['date'], $o['id'], $shopOf($o), $catOf($o),
        $rd['equipment_name'] ?? '', $rd['issue'] ?? '',
        fmtUnavailDates($unavailDates[$o['id']] ?? null), fmtUnavailDays($unavailDays[$o['id']] ?? null),
        $rd['repair_schedule_date'] ?? '', $rd['repair_completed_date'] ?? '',
        $finOf($o), $photoCounts[$o['id']] ?? 0, $stOf($o), $o['created_at'],
    ];
}
$sheetSpecs['repair'] = [
    'title' => '修理',
    'headers' => ['発注日','発注番号','店舗','カテゴリ','対象機材','不具合内容','対応不可日時','対応不可曜日','修理予定日','修理完了日','確定金額','写真枚数','ステータス','登録日時'],
    'rows' => $rows, 'money' => [10], 'center' => [11], 'wrap' => [5, 6, 7],
];

// シート交換
$rows = [];
foreach ($byType['seat-replacement'] as $o) {
    $sd = $seatReplacementDetails[$o['id']] ?? [];
    $rows[] = [
        $o['date'], $o['id'], $shopOf($o), $catOf($o),
        $sd['equipment_name'] ?? '', $sd['issue'] ?? '',
        fmtUnavailDates($unavailDates[$o['id']] ?? null), fmtUnavailDays($unavailDays[$o['id']] ?? null),
        $sd['repair_schedule_date'] ?? '', $sd['repair_completed_date'] ?? '',
        $finOf($o), $photoCounts[$o['id']] ?? 0, $stOf($o), $o['created_at'],
    ];
}
$sheetSpecs['seat-replacement'] = [
    'title' => 'シート交換',
    'headers' => ['発注日','発注番号','店舗','カテゴリ','対象機材','内容','対応不可日時','対応不可曜日','作業予定日','作業完了日','確定金額','写真枚数','ステータス','登録日時'],
    'rows' => $rows, 'money' => [10], 'center' => [11], 'wrap' => [5, 6, 7],
];

// 部品
$rows = [];
foreach ($byType['parts'] as $o) {
    $pd = $partsDetails[$o['id']] ?? [];
    $rows[] = [
        $o['date'], $o['id'], $shopOf($o), $catOf($o),
        $pd['parts_name'] ?? '', $pd['target_equipment'] ?? '',
        isset($pd['quantity']) ? (int)$pd['quantity'] : 1, $pd['reason'] ?? '',
        $o['delivery_date'] ?? '', $o['actual_delivery_date'] ?? '',
        $finOf($o), $photoCounts[$o['id']] ?? 0, $stOf($o), $o['created_at'],
    ];
}
$sheetSpecs['parts'] = [
    'title' => '部品',
    'headers' => ['発注日','発注番号','店舗','カテゴリ','部品名・品番','対象機材','数量','発注理由','納品予定日','納品実績日','確定金額','写真枚数','ステータス','登録日時'],
    'rows' => $rows, 'money' => [10], 'center' => [6, 11], 'wrap' => [7],
];

// --- Excel作成（データのある種別のみシート化）---
$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');

$sheetOrder = ['equipment', 'repair', 'parts', 'seat-replacement'];
$created = 0;
foreach ($sheetOrder as $t) {
    $spec = $sheetSpecs[$t];
    if (empty($spec['rows'])) {
        continue;
    }
    $sheet = ($created === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle($spec['title']);
    fillSheet($sheet, $spec['headers'], $spec['rows'], $spec['money'], $spec['center'], $spec['wrap']);
    $created++;
}

// 該当データなし: 空シートにメッセージ
if ($created === 0) {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('発注一覧');
    $sheet->setCellValue('A1', '該当する発注データがありません');
}

$spreadsheet->setActiveSheetIndex(0);

// --- 出力 ---
$filename = 'orders_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
