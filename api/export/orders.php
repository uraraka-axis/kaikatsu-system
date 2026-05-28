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

    $seatSql = "SELECT order_id, equipment_name, issue
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
}

// --- Excel作成 ---
$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('発注一覧');

// ヘッダ定義
// 2026-05-28: 列順を業務上見やすい順に並べ替え。
//   発注日 > 種別 > 発注番号 > 店舗 > カテゴリ > 品名 > 会社商品コード >
//   仕入先商品コード > 仕入先 > 数量 > 単価 > 小計 > 納品予定日 >
//   確定金額 > 納品実績日 > ステータス > 詳細 > 登録日時
// 店舗は "10101:札幌" 形式でコードと名前を1列に結合。
// 見積金額列は単価編集可能化により Σ小計 と常に一致するため削除。
$headers = [
    'A' => '発注日',
    'B' => '種別',
    'C' => '発注番号',
    'D' => '店舗',
    'E' => 'カテゴリ',
    'F' => '品名',
    'G' => '会社商品コード',
    'H' => '仕入先商品コード',
    'I' => '仕入先',
    'J' => '数量',
    'K' => '単価',
    'L' => '小計',
    'M' => '納品予定日',
    'N' => '確定金額',
    'O' => '納品実績日',
    'P' => 'ステータス',
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
// 新列順（A〜R）に合わせて行データを組み立てるヘルパ。
// $variable は per-type の差異（F品名 / G会社商品コード / H仕入先商品コード / I仕入先 /
// J数量 / K単価 / L小計 / Q詳細）の連想配列。
function buildRow(
    array $order,
    array $typeLabels,
    array $categoryLabels,
    array $statusLabels,
    array $variable
): array {
    $shopCombined = $order['shop_code'] . ':' . $order['shop_name'];
    return [
        $order['date'],                                                                      // A 発注日
        $typeLabels[$order['type']] ?? $order['type'],                                       // B 種別
        $order['id'],                                                                        // C 発注番号
        $shopCombined,                                                                       // D 店舗
        $categoryLabels[$order['category_code']] ?? $order['category_code'],                 // E カテゴリ
        $variable['product_name']     ?? '',                                                 // F 品名
        $variable['product_code']     ?? '',                                                 // G 会社商品コード
        $variable['supplier_product_code'] ?? '',                                            // H 仕入先商品コード
        $variable['supplier']         ?? '',                                                 // I 仕入先
        $variable['qty']              ?? '',                                                 // J 数量
        $variable['price']            ?? '',                                                 // K 単価
        $variable['subtotal']         ?? '',                                                 // L 小計
        $order['delivery_date']       ?? '',                                                 // M 納品予定日
        $order['final_amount'] !== null ? (int)$order['final_amount'] : '',                  // N 確定金額
        $order['actual_delivery_date'] ?? '',                                                // O 納品実績日
        $statusLabels[(int)$order['status']] ?? $order['status'],                            // P ステータス
        $variable['detail']           ?? '',                                                 // Q 詳細
        $order['created_at'],                                                                // R 登録日時
    ];
}

// データ行書き込み
$rowNum = 2;

foreach ($orders as $order) {
    $id    = $order['id'];
    $oType = $order['type'];

    if ($oType === 'repair') {
        $rd = $repairDetails[$id] ?? null;
        $rowData = buildRow($order, $typeLabels, $categoryLabels, $statusLabels, [
            'product_name' => $rd['equipment_name'] ?? '',
            'qty'          => 1,
            'detail'       => $rd['issue'] ?? '',
        ]);
        writeRow($sheet, $rowNum, $rowData);
        $rowNum++;

    } elseif ($oType === 'seat-replacement') {
        $sd = $seatReplacementDetails[$id] ?? null;
        $rowData = buildRow($order, $typeLabels, $categoryLabels, $statusLabels, [
            'product_name' => $sd['equipment_name'] ?? '',
            'qty'          => 1,
            'detail'       => $sd['issue'] ?? 'マシンのシート交換',
        ]);
        writeRow($sheet, $rowNum, $rowData);
        $rowNum++;

    } elseif ($oType === 'equipment') {
        $items = $equipItems[$id] ?? [];
        if (empty($items)) {
            $rowData = buildRow($order, $typeLabels, $categoryLabels, $statusLabels, []);
            writeRow($sheet, $rowNum, $rowData);
            $rowNum++;
        } else {
            foreach ($items as $ei) {
                $subtotal = (int)$ei['price'] * (int)$ei['qty'];
                $rowData = buildRow($order, $typeLabels, $categoryLabels, $statusLabels, [
                    'product_name'          => $ei['product_name'],
                    'product_code'          => $ei['product_code'] ?? '',
                    'supplier_product_code' => $ei['supplier_product_code'] ?? '',
                    'supplier'              => $ei['supplier'] ?? '',
                    'qty'                   => (int)$ei['qty'],
                    'price'                 => (int)$ei['price'],
                    'subtotal'              => $subtotal,
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
        $rowData = buildRow($order, $typeLabels, $categoryLabels, $statusLabels, [
            'product_name' => $pd['parts_name'] ?? '',
            'qty'          => $pd['quantity'] ?? 1,
            'detail'       => $detail,
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
    // 金額列は右寄せ・カンマ区切り（K=単価/L=小計/N=確定）
    foreach (['K', 'L', 'N'] as $moneyCol) {
        $sheet->getStyle($moneyCol . '2:' . $moneyCol . $lastRow)
              ->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle($moneyCol . '2:' . $moneyCol . $lastRow)
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    // 数量列(J)は中央
    $sheet->getStyle('J2:J' . $lastRow)
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
