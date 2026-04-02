<?php declare(strict_types=1);

/**
 * 快活システム - 予算データExcel出力API
 *
 * GET /api/export/budgets.php?year=2026&dept=all&zone=&area=&shop=
 * - .xlsx形式（PhpSpreadsheet）
 * - クエリパラメータは budgets.php と同じ
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

// --- パラメータ取得 ---
$user       = getCurrentUser();
$fiscalYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentFiscalYear();
$dept       = $_GET['dept'] ?? 'all';
$zoneCode   = $_GET['zone'] ?? '';
$areaCode   = $_GET['area'] ?? '';
$shopCode   = $_GET['shop'] ?? '';

// 部門バリデーション
if (!in_array($dept, ['all', 'fit', 'ig'], true)) {
    jsonError('不正な部門パラメータです');
}

// 部門ラベル
$deptLabels = [
    'all' => '全体',
    'fit' => 'フィットネス',
    'ig'  => 'インドアゴルフ',
];

// --- 店舗ユーザーは自店のみ ---
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
}

// --- 対象店舗を取得 ---
$shopSql    = 'SELECT s.code AS shop_code, s.name AS shop_name, s.area_code,
                      a.zone_code, a.name AS area_name, z.name AS zone_name
               FROM shops s
               JOIN areas a ON s.area_code = a.code
               JOIN zones z ON a.zone_code = z.code
               WHERE s.is_active = 1';
$shopParams = [];

if ($shopCode !== '') {
    $shopSql .= ' AND s.code = :shop_code';
    $shopParams[':shop_code'] = $shopCode;
}
if ($areaCode !== '') {
    $shopSql .= ' AND s.area_code = :area_code';
    $shopParams[':area_code'] = $areaCode;
}
if ($zoneCode !== '') {
    $shopSql .= ' AND a.zone_code = :zone_code';
    $shopParams[':zone_code'] = $zoneCode;
}

$shopSql .= ' ORDER BY s.sort_order, s.code';
$shops = query($shopSql, $shopParams);

// --- 予算データ取得 ---
$shopCodes = array_column($shops, 'shop_code');

$budgetMap = [];
if (!empty($shopCodes)) {
    $placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
    $budgetSql    = "SELECT shop_code, month, budget_amount, actual_amount
                     FROM budgets
                     WHERE fiscal_year = :fiscal_year
                       AND department  = :department
                       AND shop_code IN ({$placeholders})
                     ORDER BY shop_code, month";

    $budgetParams = [
        ':fiscal_year' => $fiscalYear,
        ':department'  => $dept,
    ];
    foreach ($shopCodes as $i => $sc) {
        $budgetParams[':sc' . $i] = $sc;
    }

    $budgetRows = query($budgetSql, $budgetParams);
    foreach ($budgetRows as $row) {
        $budgetMap[$row['shop_code']][(int)$row['month']] = [
            'budget' => (int)$row['budget_amount'],
            'actual' => (int)$row['actual_amount'],
        ];
    }
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- Excel作成 ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('予算実績');

// ヘッダ定義
$headers = [
    '店舗コード',
    '店舗名',
    'ゾーン',
    'エリア',
    '年度',
    '部門',
];
foreach ($fiscalMonths as $m) {
    $headers[] = $m . '月_予算';
    $headers[] = $m . '月_実績';
}
$headers[] = '年間_予算合計';
$headers[] = '年間_実績合計';

// ヘッダ行書き込み
$col = 1;
foreach ($headers as $label) {
    $sheet->setCellValue([$col, 1], $label);
    $col++;
}
$lastCol = $col - 1; // 30列 (6 + 24 + 2)
$lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);

// ヘッダスタイル
$headerRange = 'A1:' . $lastColLetter . '1';
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getRowDimension(1)->setRowHeight(24);

// カラム幅
$sheet->getColumnDimension('A')->setWidth(12); // 店舗コード
$sheet->getColumnDimension('B')->setWidth(14); // 店舗名
$sheet->getColumnDimension('C')->setWidth(10); // ゾーン
$sheet->getColumnDimension('D')->setWidth(12); // エリア
$sheet->getColumnDimension('E')->setWidth(10); // 年度
$sheet->getColumnDimension('F')->setWidth(14); // 部門
// 月次列（G〜AD: 24列）+ 合計2列
for ($c = 7; $c <= $lastCol; $c++) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
    $sheet->getColumnDimension($letter)->setWidth(12);
}

// データ行書き込み
$rowNum = 2;

foreach ($shops as $shop) {
    $code = $shop['shop_code'];
    $col  = 1;

    $sheet->setCellValue([$col++, $rowNum], $shop['shop_code']);
    $sheet->setCellValue([$col++, $rowNum], $shop['shop_name']);
    $sheet->setCellValue([$col++, $rowNum], $shop['zone_name']);
    $sheet->setCellValue([$col++, $rowNum], $shop['area_name']);
    $sheet->setCellValue([$col++, $rowNum], $fiscalYear . '年度');
    $sheet->setCellValue([$col++, $rowNum], $deptLabels[$dept] ?? $dept);

    $totalBudget = 0;
    $totalActual = 0;

    foreach ($fiscalMonths as $m) {
        $entry = $budgetMap[$code][$m] ?? ['budget' => 0, 'actual' => 0];
        $sheet->setCellValue([$col++, $rowNum], $entry['budget']);
        $sheet->setCellValue([$col++, $rowNum], $entry['actual']);
        $totalBudget += $entry['budget'];
        $totalActual += $entry['actual'];
    }

    $sheet->setCellValue([$col++, $rowNum], $totalBudget);
    $sheet->setCellValue([$col++, $rowNum], $totalActual);

    $rowNum++;
}

// データ行スタイル（罫線）
$lastRow = $rowNum - 1;
if ($lastRow >= 2) {
    $dataRange = 'A2:' . $lastColLetter . $lastRow;
    $sheet->getStyle($dataRange)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    // 金額列は右寄せ・カンマ区切り（G列〜最終列）
    for ($c = 7; $c <= $lastCol; $c++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getStyle($letter . '2:' . $letter . $lastRow)
              ->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle($letter . '2:' . $letter . $lastRow)
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// オートフィルター
$sheet->setAutoFilter('A1:' . $lastColLetter . '1');

// ウィンドウ枠固定（ヘッダ行 + 左6列固定）
$sheet->freezePane('G2');

// --- 出力 ---
$filename = sprintf('budget_%d_%s_%s.xlsx', $fiscalYear, $dept, date('Ymd'));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
