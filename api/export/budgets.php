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

// 複数選択された店舗（チェックボックスでの選択）
$selectedShops = [];
if (isset($_GET['shops']) && is_array($_GET['shops'])) {
    foreach ($_GET['shops'] as $code) {
        if (is_string($code) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code)) {
            $selectedShops[] = $code;
        }
    }
}

// カテゴリバリデーション: 'all' または categories.code のいずれか
$categoryRows = query('SELECT code, name FROM categories WHERE is_active = 1');
$validCategoryCodes = array_column($categoryRows, 'code');
$validDeptValues    = array_merge(['all'], $validCategoryCodes);
if (!in_array($dept, $validDeptValues, true)) {
    jsonError('不正なカテゴリパラメータです');
}

// カテゴリラベル: dept='all' は固定、それ以外は categories.name
$deptLabels = ['all' => '全体'];
foreach ($categoryRows as $cr) {
    $deptLabels[$cr['code']] = $cr['name'];
}

// --- 店舗ユーザーは自店のみ ---
if ($user['role'] !== 'admin') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
    $selectedShops = []; // 店舗ユーザーは複数選択不可
}

// --- 対象店舗を取得 ---
$shopSql    = 'SELECT s.code AS shop_code, s.name AS shop_name, s.area_code,
                      a.zone_code, a.name AS area_name, z.name AS zone_name
               FROM shops s
               JOIN areas a ON s.area_code = a.code
               JOIN zones z ON a.zone_code = z.code
               WHERE s.is_active = 1';
$shopParams = [];

if (!empty($selectedShops)) {
    // 個別選択された店舗のみ（フィルタは無視）
    $placeholders = [];
    foreach ($selectedShops as $i => $sc) {
        $key = ':selsc' . $i;
        $placeholders[] = $key;
        $shopParams[$key] = $sc;
    }
    $shopSql .= ' AND s.code IN (' . implode(',', $placeholders) . ')';
} else {
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
}

$shopSql .= ' ORDER BY s.sort_order, s.code';
$shops = query($shopSql, $shopParams);

// --- 予算データ取得 ---
$shopCodes = array_column($shops, 'shop_code');

$budgetMap = [];
if (!empty($shopCodes)) {
    $placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
    if ($dept === 'all') {
        // 全体: 部門合計 (SUM)
        $budgetSql = "SELECT shop_code, month,
                             SUM(budget_amount) AS budget_amount,
                             SUM(actual_amount) AS actual_amount
                      FROM budgets
                      WHERE fiscal_year = :fiscal_year
                        AND shop_code IN ({$placeholders})
                      GROUP BY shop_code, month
                      ORDER BY shop_code, month";
        $budgetParams = [':fiscal_year' => $fiscalYear];
    } else {
        $budgetSql = "SELECT shop_code, month, budget_amount, actual_amount
                      FROM budgets
                      WHERE fiscal_year = :fiscal_year
                        AND department  = :department
                        AND shop_code IN ({$placeholders})
                      ORDER BY shop_code, month";
        $budgetParams = [
            ':fiscal_year' => $fiscalYear,
            ':department'  => $dept,
        ];
    }
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
$spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('予算実績');

// ヘッダ定義
$headers = [
    '店舗コード',
    '店舗名',
    'ゾーン',
    'エリア',
    '年度',
    'カテゴリ',
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

// カラム幅（データ書き込み後に自動調整）

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

// カラム幅自動調整
for ($c = 1; $c <= $lastCol; $c++) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
    $sheet->getColumnDimension($letter)->setAutoSize(true);
}

// ウィンドウ枠固定（ヘッダ行 + 左6列固定）
$sheet->freezePane('G2');

// アクティブセルをA1に設定
$sheet->setSelectedCell('A1');

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
