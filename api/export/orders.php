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

// --- 店舗ユーザーは自店のみ ---
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
}

// --- バリデーション ---
if ($type !== '' && !in_array($type, ['repair', 'equipment', 'parts'], true)) {
    jsonError('不正な種別パラメータです');
}
if ($status !== '' && !in_array($status, ['0', '1', '2', '3', '4'], true)) {
    jsonError('不正なステータスパラメータです');
}

// --- ラベルマッピング ---
$typeLabels = [
    'repair'    => '修理',
    'equipment' => '備品',
    'parts'     => '部品',
];

$statusLabels = [
    0 => '依頼中',
    1 => '発注済',
    2 => '配達中/修理待ち',
    3 => '納品済/修理済',
    4 => '完了',
];

$categoryLabels = [
    'fitness' => 'フィットネス',
    'golf'    => 'ゴルフ',
];

// --- メインクエリ組み立て ---
$sql = 'SELECT o.id, o.type, o.category_code, o.status, o.shop_code, o.date,
               o.estimate_amount, o.final_amount, o.delivery_date, o.actual_delivery_date,
               o.created_at,
               s.name AS shop_name
        FROM orders o
        JOIN shops s ON o.shop_code = s.code';

$joins = [];
$where = [];
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

if (!empty($joins)) {
    $sql .= ' ' . implode(' ', $joins);
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY o.date DESC, o.id DESC';

$orders = query($sql, $params);

// --- 関連データ取得 ---
$repairDetails = [];
$partsDetails  = [];
$equipItems    = [];

if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_map(fn($i) => ':oid' . $i, array_keys($orderIds)));
    $idParams = [];
    foreach ($orderIds as $i => $oid) {
        $idParams[':oid' . $i] = $oid;
    }

    $repairSql = "SELECT order_id, equipment_name, issue
                  FROM order_repair_details
                  WHERE order_id IN ({$placeholders})";
    foreach (query($repairSql, $idParams) as $row) {
        $repairDetails[$row['order_id']] = $row;
    }

    $partsSql = "SELECT order_id, parts_name, target_equipment, reason, quantity
                 FROM order_parts_details
                 WHERE order_id IN ({$placeholders})";
    foreach (query($partsSql, $idParams) as $row) {
        $partsDetails[$row['order_id']] = $row;
    }

    $equipSql = "SELECT order_id, product_name, product_code, price, qty, supplier
                 FROM order_equipment_items
                 WHERE order_id IN ({$placeholders})
                 ORDER BY id";
    foreach (query($equipSql, $idParams) as $row) {
        $equipItems[$row['order_id']][] = $row;
    }
}

// --- Excel作成 ---
$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('発注一覧');

// ヘッダ定義
$headers = [
    'A' => '発注番号',
    'B' => '種別',
    'C' => 'カテゴリ',
    'D' => 'ステータス',
    'E' => '店舗コード',
    'F' => '店舗名',
    'G' => '発注日',
    'H' => '見積金額',
    'I' => '確定金額',
    'J' => '納品予定日',
    'K' => '納品実績日',
    'L' => '品名',
    'M' => '数量',
    'N' => '単価',
    'O' => '小計',
    'P' => '仕入先',
    'Q' => '詳細',
    'R' => '登録日時',
];

// ヘッダ行書き込み
foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

// ヘッダスタイル
$headerRange = 'A1:R1';
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// カラム幅（データ書き込み後に自動調整）

// --- 共通カラム生成 ---
function orderBaseCols(array $order, array $typeLabels, array $categoryLabels, array $statusLabels): array
{
    return [
        $order['id'],
        $typeLabels[$order['type']] ?? $order['type'],
        $categoryLabels[$order['category_code']] ?? $order['category_code'],
        $statusLabels[(int)$order['status']] ?? $order['status'],
        $order['shop_code'],
        $order['shop_name'],
        $order['date'],
        $order['estimate_amount'] !== null ? (int)$order['estimate_amount'] : '',
        $order['final_amount'] !== null ? (int)$order['final_amount'] : '',
        $order['delivery_date'] ?? '',
        $order['actual_delivery_date'] ?? '',
    ];
}

// データ行書き込み
$rowNum = 2;

foreach ($orders as $order) {
    $id    = $order['id'];
    $oType = $order['type'];
    $base  = orderBaseCols($order, $typeLabels, $categoryLabels, $statusLabels);

    if ($oType === 'repair') {
        $rd = $repairDetails[$id] ?? null;
        $rowData = array_merge($base, [
            $rd['equipment_name'] ?? '',
            1,
            '',
            '',
            '',
            $rd['issue'] ?? '',
            $order['created_at'],
        ]);
        writeRow($sheet, $rowNum, $rowData);
        $rowNum++;

    } elseif ($oType === 'equipment') {
        $items = $equipItems[$id] ?? [];
        if (empty($items)) {
            $rowData = array_merge($base, ['', '', '', '', '', '', $order['created_at']]);
            writeRow($sheet, $rowNum, $rowData);
            $rowNum++;
        } else {
            foreach ($items as $ei) {
                $subtotal = (int)$ei['price'] * (int)$ei['qty'];
                $rowData = array_merge($base, [
                    $ei['product_name'],
                    (int)$ei['qty'],
                    (int)$ei['price'],
                    $subtotal,
                    $ei['supplier'] ?? '',
                    '',
                    $order['created_at'],
                ]);
                writeRow($sheet, $rowNum, $rowData);
                $rowNum++;
            }
        }

    } elseif ($oType === 'parts') {
        $pd = $partsDetails[$id] ?? null;
        $detail = '';
        if (($pd['target_equipment'] ?? '') !== '') {
            $detail = '対象: ' . $pd['target_equipment'];
        }
        if (($pd['reason'] ?? '') !== '') {
            $detail .= ($detail !== '' ? ' / ' : '') . '理由: ' . $pd['reason'];
        }
        $rowData = array_merge($base, [
            $pd['parts_name'] ?? '',
            $pd['quantity'] ?? 1,
            '',
            '',
            '',
            $detail,
            $order['created_at'],
        ]);
        writeRow($sheet, $rowNum, $rowData);
        $rowNum++;
    }
}

// データ行スタイル（罫線）
$lastRow = $rowNum - 1;
if ($lastRow >= 2) {
    $dataRange = 'A2:R' . $lastRow;
    $sheet->getStyle($dataRange)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    // 金額列は右寄せ・カンマ区切り
    foreach (['H', 'I', 'N', 'O'] as $moneyCol) {
        $sheet->getStyle($moneyCol . '2:' . $moneyCol . $lastRow)
              ->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle($moneyCol . '2:' . $moneyCol . $lastRow)
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    // 数量列は中央
    $sheet->getStyle('M2:M' . $lastRow)
          ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// カラム幅自動調整
foreach (range('A', 'R') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ウィンドウ枠固定（ヘッダ行）
$sheet->freezePane('A2');

// アクティブセルをA1に設定
$sheet->setSelectedCell('A1');

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

// --- ヘルパー ---
function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowNum, array $data): void
{
    $cols = range('A', 'R');
    foreach ($data as $i => $val) {
        if (isset($cols[$i])) {
            $sheet->setCellValue($cols[$i] . $rowNum, $val);
        }
    }
}
