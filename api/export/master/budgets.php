<?php declare(strict_types=1);

/**
 * 快活システム - 予算マスタ ダウンロードAPI（ピボット形式）
 *
 * GET /api/export/master/budgets.php
 * GET /api/export/master/budgets.php?year=2026
 *
 * → 現状の budgets テーブルをピボット形式 (1行=1店舗×1部門、月12列) で出力
 *    year パラメータ指定時は当該年度のみ
 *    なお、画面に存在しない (shop_code, year, dept) の組合せがあれば 0 で埋める。
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/master_excel.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

requireAdmin();
requireMethod('GET');

try {
    // カテゴリマップ取得（code → name、出力順用）
    $categories = query('SELECT code, name, sort_order FROM categories WHERE is_active = 1 ORDER BY sort_order');
    $codeToName = [];
    $sortByCode = [];
    foreach ($categories as $c) {
        $codeToName[$c['code']] = $c['name'];
        $sortByCode[$c['code']] = (int)$c['sort_order'];
    }

    $year = isset($_GET['year']) ? (int)$_GET['year'] : null;

    // データ取得（全体は SUM 動的計算で行が存在しないため、そのまま全部出力すれば fit/ig 等のみ）
    if ($year !== null) {
        $rows = query(
            "SELECT shop_code, fiscal_year, month, department, budget_amount
               FROM budgets WHERE fiscal_year = ?
              ORDER BY shop_code, department, month",
            [$year]
        );
        $filename = sprintf('budgets_master_%d年度_%s.xlsx', $year, date('Ymd'));
    } else {
        $rows = query(
            "SELECT shop_code, fiscal_year, month, department, budget_amount
               FROM budgets
              ORDER BY fiscal_year, shop_code, department, month"
        );
        $filename = sprintf('budgets_master_全年度_%s.xlsx', date('Ymd'));
    }

    // ピボット集計: pivot[year][shop][dept][month] = amount
    $pivot = [];
    foreach ($rows as $r) {
        $y = (int)$r['fiscal_year'];
        $s = (string)$r['shop_code'];
        $d = (string)$r['department'];
        $m = (int)$r['month'];
        if (!isset($pivot[$y]))                $pivot[$y] = [];
        if (!isset($pivot[$y][$s]))            $pivot[$y][$s] = [];
        if (!isset($pivot[$y][$s][$d]))        $pivot[$y][$s][$d] = [];
        $pivot[$y][$s][$d][$m] = (int)$r['budget_amount'];
    }

    // Excel書き出し
    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('予算マスタ');

    // ヘッダ
    $headers = ['年度', '店舗コード', '部門', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月'];
    foreach ($headers as $i => $h) {
        $cell = chr(ord('A') + $i) . '1';
        $sheet->setCellValue($cell, $h);
    }
    $sheet->getStyle('A1:O1')->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);

    // データ行（categories マスタの sort_order 順、code から name にラベル変換）
    $monthsInOrder = [4,5,6,7,8,9,10,11,12,1,2,3];
    $deptOrderCodes = array_keys($sortByCode); // categories の sort_order 順
    $rowIdx = 2;
    foreach ($pivot as $y => $shopMap) {
        ksort($shopMap);
        foreach ($shopMap as $shop => $deptMap) {
            foreach ($deptOrderCodes as $deptCode) {
                if (!isset($deptMap[$deptCode])) continue;
                $monthValues = $deptMap[$deptCode];
                $sheet->setCellValue("A{$rowIdx}", $y);
                $sheet->setCellValueExplicit("B{$rowIdx}", $shop, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue("C{$rowIdx}", $codeToName[$deptCode] ?? $deptCode);
                $col = 'D';
                foreach ($monthsInOrder as $mn) {
                    $sheet->setCellValue("{$col}{$rowIdx}", $monthValues[$mn] ?? 0);
                    $col++;
                }
                $rowIdx++;
            }
        }
    }

    // 列幅自動調整
    foreach (range('A', 'O') as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }
    $sheet->freezePane('D2');

    // 出力
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
} catch (Throwable $e) {
    error_log('budgets master download error: ' . $e->getMessage());
    http_response_code(500);
    echo 'サーバーエラーが発生しました';
}
