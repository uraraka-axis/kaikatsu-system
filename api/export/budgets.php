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
$user      = getCurrentUser();
// year: 空欄 or 'all' → 全年度（複数年をまとめて出力）／ それ以外は当該年度のみ
$yearParam = $_GET['year'] ?? '';
$allYears  = ($yearParam === '' || $yearParam === 'all');
$fiscalYear = $allYears ? null : (int)$yearParam;
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

// --- ロール別の閲覧スコープ ---
// shop : 自店のみ / admin/system : 全店（フィルタ通り）
// zone : 自分の zone_code に強制（管轄外の zone/area/shop は弾く）
// area : 自分の area_code に強制
if ($user['role'] === 'shop') {
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
    $selectedShops = []; // 店舗ユーザーは複数選択不可
} elseif ($user['role'] === 'zone') {
    $zoneCode = $user['zone_code'] ?? '';
    // 管轄外の店舗を selectedShops から除外
    if (!empty($selectedShops) && !empty($zoneCode)) {
        $placeholders = [];
        $checkParams  = [':zc' => $zoneCode];
        foreach ($selectedShops as $i => $sc) {
            $key = ':ss' . $i;
            $placeholders[] = $key;
            $checkParams[$key] = $sc;
        }
        $valid = query(
            'SELECT s.code FROM shops s JOIN areas a ON s.area_code = a.code
              WHERE a.zone_code = :zc AND s.code IN (' . implode(',', $placeholders) . ')',
            $checkParams
        );
        $selectedShops = array_column($valid, 'code');
    }
} elseif ($user['role'] === 'area') {
    $areaCode = $user['area_code'] ?? '';
    $zoneCode = '';
    if (!empty($selectedShops) && !empty($areaCode)) {
        $placeholders = [];
        $checkParams  = [':ac' => $areaCode];
        foreach ($selectedShops as $i => $sc) {
            $key = ':ss' . $i;
            $placeholders[] = $key;
            $checkParams[$key] = $sc;
        }
        $valid = query(
            'SELECT code FROM shops WHERE area_code = :ac AND code IN (' . implode(',', $placeholders) . ')',
            $checkParams
        );
        $selectedShops = array_column($valid, 'code');
    }
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
// 出力する dept のリストを決定:
//   - dept='all'         → 全体（SUM）＋全カテゴリの内訳行を出力（カテゴリ拡張時も自動対応）
//   - dept=<categories.code> → そのカテゴリのみ
$shopCodes = array_column($shops, 'shop_code');

$deptsToOutput = ($dept === 'all')
    ? array_merge(['all'], array_column($categoryRows, 'code'))
    : [$dept];

// 出力対象年度の決定
if ($allYears) {
    $yearRows = query('SELECT DISTINCT fiscal_year FROM budgets ORDER BY fiscal_year');
    $yearsToOutput = array_map(fn($r) => (int)$r['fiscal_year'], $yearRows);
} else {
    $yearsToOutput = [$fiscalYear];
}

// budgetMap[dept][shop_code][fiscal_year][month] = ['budget'=>x, 'actual'=>y]
$budgetMap = [];
if (!empty($shopCodes) && !empty($yearsToOutput)) {
    $placeholders = implode(',', array_map(fn($i) => ':sc' . $i, array_keys($shopCodes)));
    $yearFilter   = $allYears ? '' : ' AND fiscal_year = :fiscal_year';

    // 全体（SUM）: dept=all のときに使う
    if (in_array('all', $deptsToOutput, true)) {
        $sumSql = "SELECT shop_code, fiscal_year, month,
                          SUM(budget_amount) AS budget_amount,
                          SUM(actual_amount) AS actual_amount
                   FROM budgets
                   WHERE shop_code IN ({$placeholders}){$yearFilter}
                   GROUP BY shop_code, fiscal_year, month
                   ORDER BY shop_code, fiscal_year, month";
        $sumParams = [];
        if (!$allYears) $sumParams[':fiscal_year'] = $fiscalYear;
        foreach ($shopCodes as $i => $sc) $sumParams[':sc' . $i] = $sc;
        foreach (query($sumSql, $sumParams) as $r) {
            $budgetMap['all'][$r['shop_code']][(int)$r['fiscal_year']][(int)$r['month']] = [
                'budget' => (int)$r['budget_amount'],
                'actual' => (int)$r['actual_amount'],
            ];
        }
    }

    // 個別カテゴリ取得
    $categoryDepts = array_filter($deptsToOutput, fn($d) => $d !== 'all');
    if (!empty($categoryDepts)) {
        $deptPlaceholders = implode(',', array_map(fn($i) => ':dp' . $i, array_keys(array_values($categoryDepts))));
        $catSql = "SELECT shop_code, fiscal_year, month, department, budget_amount, actual_amount
                   FROM budgets
                   WHERE department IN ({$deptPlaceholders})
                     AND shop_code IN ({$placeholders}){$yearFilter}
                   ORDER BY shop_code, fiscal_year, department, month";
        $catParams = [];
        if (!$allYears) $catParams[':fiscal_year'] = $fiscalYear;
        foreach (array_values($categoryDepts) as $i => $d) $catParams[':dp' . $i] = $d;
        foreach ($shopCodes as $i => $sc) $catParams[':sc' . $i] = $sc;
        foreach (query($catSql, $catParams) as $r) {
            $budgetMap[$r['department']][$r['shop_code']][(int)$r['fiscal_year']][(int)$r['month']] = [
                'budget' => (int)$r['budget_amount'],
                'actual' => (int)$r['actual_amount'],
            ];
        }
    }
}

// --- 年度月配列（4月始まり） ---
$fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

// --- Excel作成 ---
$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Meiryo UI');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('予算実績');

// ヘッダ定義: 店舗情報6列 + 項目1列 + 月12列 + 合計1列 = 20列
// 各 (店舗 × カテゴリ) について 予算/実績/残高/消化(%) の4行を出力（画面の月別明細と同じレイアウト）
$headers = [
    '店舗コード', '店舗名', 'ゾーン', 'エリア', '年度', 'カテゴリ', '項目',
];
foreach ($fiscalMonths as $m) {
    $headers[] = $m . '月';
}
$headers[] = '合計';

// ヘッダ行書き込み
$col = 1;
foreach ($headers as $label) {
    $sheet->setCellValue([$col, 1], $label);
    $col++;
}
$lastCol = $col - 1; // 20列
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

// データ行書き込み: 店舗 × カテゴリ × {予算/実績/残高/消化(%)} の4行
$rowNum = 2;
$itemLabels = ['予算（円）', '実績（円）', '残高（円）', '消化（%）'];

foreach ($shops as $shop) {
    $code = $shop['shop_code'];

    foreach ($yearsToOutput as $year) {
        foreach ($deptsToOutput as $deptKey) {
            $monthMap = $budgetMap[$deptKey][$code][$year] ?? [];

            // 月別 budget / actual 配列を作成
            $budgets = [];
            $actuals = [];
            $balances = [];
            $rates = []; // 消化率(%)
            $totalBudget = 0;
            $totalActual = 0;
            foreach ($fiscalMonths as $m) {
                $entry = $monthMap[$m] ?? ['budget' => 0, 'actual' => 0];
                $b = (int)$entry['budget'];
                $a = (int)$entry['actual'];
                $budgets[]  = $b;
                $actuals[]  = $a;
                $balances[] = $b - $a;
                $rates[]    = $b > 0 ? round($a / $b * 100, 1) : 0.0;
                $totalBudget += $b;
                $totalActual += $a;
            }
            $totalBalance = $totalBudget - $totalActual;
            $totalRate    = $totalBudget > 0 ? round($totalActual / $totalBudget * 100, 1) : 0.0;

            $itemValues = [
                ['予算（円）',  $budgets,  $totalBudget],
                ['実績（円）',  $actuals,  $totalActual],
                ['残高（円）',  $balances, $totalBalance],
                ['消化（%）',   $rates,    $totalRate],
            ];

            foreach ($itemValues as $iv) {
                $col = 1;
                $sheet->setCellValueExplicit([$col++, $rowNum], $shop['shop_code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue([$col++, $rowNum], $shop['shop_name']);
                $sheet->setCellValue([$col++, $rowNum], $shop['zone_name']);
                $sheet->setCellValue([$col++, $rowNum], $shop['area_name']);
                $sheet->setCellValue([$col++, $rowNum], $year . '年度');
                $sheet->setCellValue([$col++, $rowNum], $deptLabels[$deptKey] ?? $deptKey);
                $sheet->setCellValue([$col++, $rowNum], $iv[0]);
                foreach ($iv[1] as $val) {
                    $sheet->setCellValue([$col++, $rowNum], $val);
                }
                $sheet->setCellValue([$col++, $rowNum], $iv[2]);
                $rowNum++;
            }
        }
    }
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
    // 数値列の書式（H列=4月以降〜最終列）。消化(%)行と金額行で書式分けする必要があるが、
    // ここでは数値列を右寄せ + デフォルトの数値表示にし、行ごとに細かい数値書式を適用
    for ($c = 8; $c <= $lastCol; $c++) {
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $sheet->getStyle($letter . '2:' . $letter . $lastRow)
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
    // 行ごとに書式適用: 予算/実績/残高は #,##0、消化率は 0.0
    // 4行を1サイクルとして処理
    for ($r = 2; $r <= $lastRow; $r++) {
        // (r - 2) % 4 で行種別を判定 (0=予算, 1=実績, 2=残高, 3=消化率)
        $itemIdx = ($r - 2) % 4;
        $fmt = ($itemIdx === 3) ? '0.0' : '#,##0';
        $sheet->getStyle('H' . $r . ':' . $lastColLetter . $r)
              ->getNumberFormat()->setFormatCode($fmt);
    }
}

// カラム幅自動調整
for ($c = 1; $c <= $lastCol; $c++) {
    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
    $sheet->getColumnDimension($letter)->setAutoSize(true);
}

// ウィンドウ枠固定（ヘッダ行 + 左7列=店舗情報+項目 固定）
$sheet->freezePane('H2');

// アクティブセルをA1に設定
$sheet->setSelectedCell('A1');

// --- 出力 ---
$yearLabel = $allYears ? '全年度' : (string)$fiscalYear;
$filename = sprintf('budget_%s_%s_%s.xlsx', $yearLabel, $dept, date('Ymd'));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
