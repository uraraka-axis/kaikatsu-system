<?php declare(strict_types=1);

/**
 * 快活システム - 修理依頼書 / 部品発注依頼書 / シート発注依頼書 Excel生成（共通処理）
 *
 * メール下書きの添付ファイル用。テンプレートは快活様支給ひな形
 * templates/部品発注・修理依頼書_原本_20261001.xlsx（修理依頼書／部品発注依頼書／シート発注依頼書の3シート）。
 * 対象種別のシートだけを残し、1発注=1シートで差し込む（複数件は「修理依頼書(2)」…）。
 *
 * 利用箇所: api/orders/request-sheet.php（単体DL）/ api/orders/draft-mail-zip.php（一式zip）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

/**
 * 発注番号リスト（同一店舗・同一種別: repair / parts / seat-replacement）から依頼書Excelを組み立てる。
 *
 * @param array $orders  orders行の配列（id, type, shop_code, shop_name, date, cancelled_at）。検証済み前提
 * @return Spreadsheet
 * @throws RuntimeException テンプレート不在
 */
function buildRequestSheetSpreadsheet(array $orders): Spreadsheet
{
    $type = $orders[0]['type'];
    $orderIds = array_column($orders, 'id');

    $ph = [];
    $params = [];
    foreach ($orderIds as $i => $oid) {
        $ph[] = ':rsq' . $i;
        $params[':rsq' . $i] = $oid;
    }
    $in = implode(',', $ph);

    // 詳細（対象マシン・症状。部品は部品名・個数・発注理由も）
    $details = [];
    if ($type === 'parts') {
        foreach (query(
            "SELECT order_id, parts_name, target_equipment, quantity, reason FROM order_parts_details WHERE order_id IN ({$in})",
            $params
        ) as $row) {
            $details[$row['order_id']] = $row;
        }
    } else {
        $detailTable = getRepairLikeDetailTable($type); // order_repair_details / order_seat_replacement_details
        foreach (query(
            "SELECT order_id, equipment_name, issue FROM {$detailTable} WHERE order_id IN ({$in})",
            $params
        ) as $row) {
            $details[$row['order_id']] = $row;
        }
    }

    // 故障箇所写真（damage のみ・発注ごと最大3枚）
    $photoFiles = [];
    $baseDir = realpath(__DIR__ . '/../uploads');
    foreach (query(
        "SELECT order_id, file_path FROM order_photos
          WHERE order_id IN ({$in})
            AND COALESCE(photo_kind, 'damage') <> 'serial'
          ORDER BY order_id, sort_order, id",
        $params
    ) as $row) {
        if (count($photoFiles[$row['order_id']] ?? []) >= 3) {
            continue;
        }
        $abs = realpath(__DIR__ . '/../' . $row['file_path']);
        if ($abs !== false && $baseDir !== false
            && strncmp($abs, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) === 0
            && is_file($abs)) {
            $photoFiles[$row['order_id']][] = $abs;
        }
    }

    // テンプレート読み込み
    $templatePath = __DIR__ . '/../templates/部品発注・修理依頼書_原本_20261001.xlsx';
    if (!is_file($templatePath)) {
        throw new RuntimeException('依頼書テンプレートが見つかりません: ' . $templatePath);
    }
    $spreadsheet = IOFactory::load($templatePath);

    // 種別ごとのシート名・差し込み座標（支給ひな形のレイアウト）
    if ($type === 'repair') {
        $sheetName  = '修理依頼書';
        $photoCells = ['A20', 'C20', 'E20'];  // 不具合箇所写真 3枠（各 2列×8行）
        $clearCells = [];
    } elseif ($type === 'parts') {
        $sheetName  = '部品発注依頼書';
        $photoCells = ['A27', 'C27', 'E27'];
        $clearCells = [];                     // A20/E20 は部品名・個数を差し込むためクリア不要
    } else {
        $sheetName  = 'シート発注依頼書';
        $photoCells = ['A24', 'C24', 'E24'];
        $clearCells = ['A20', 'E20'];         // 部品内容・個数の例文はクリア（シート交換はデータに該当項目なし）
    }

    // 対象種別以外のシートを削除
    foreach (array_reverse($spreadsheet->getSheetNames()) as $name) {
        if ($name !== $sheetName) {
            $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($spreadsheet->getSheetByName($name)));
        }
    }
    $baseSheet = $spreadsheet->getSheetByName($sheetName);

    // 1発注=1シートで差し込み
    // 依頼日=ダウンロード当日。PHPToExcel(int) はUTC基準でJSTの0時が前日になるため DateTime で渡す
    $today = XlsDate::dateTimeToExcel(new DateTime('today'));
    foreach ($orders as $i => $o) {
        if ($i === 0) {
            $sheet = $baseSheet;
        } else {
            $sheet = clone $baseSheet;
            $sheet->setTitle($sheetName . '(' . ($i + 1) . ')');
            $spreadsheet->addSheet($sheet);
        }

        $d = $details[$o['id']] ?? [];

        $sheet->setCellValue('F2', $today); // 依頼日 = ダウンロード当日（書式はテンプレのまま）
        if ($type === 'parts') {
            // 部品: 対象マシン=対象機材 / 発生症状=発注理由 / 部品内容・個数=部品名・数量
            $sheet->setCellValue('A8', $d['target_equipment'] ?? '');
            $sheet->setCellValue('A12', 'FiT24 ' . $o['shop_name']);
            $sheet->setCellValue('A16', $d['reason'] ?? '');
            $sheet->setCellValue('A20', $d['parts_name'] ?? '');
            $sheet->setCellValue('E20', isset($d['quantity']) ? (int)$d['quantity'] : '');
        } else {
            $sheet->setCellValue('A8', $d['equipment_name'] ?? '');
            $sheet->setCellValue('A12', 'FiT24 ' . $o['shop_name']);
            $sheet->setCellValue('A16', $d['issue'] ?? '');
        }
        foreach ($clearCells as $cc) {
            $sheet->setCellValue($cc, '');
        }

        // 写真枠: 例文「画像が入る」を消し、damage 写真を最大3枚配置（枠 ≒ 2列×8行 = 約190×165px）
        foreach ($photoCells as $pi => $cell) {
            $sheet->setCellValue($cell, '');
            $photo = $photoFiles[$o['id']][$pi] ?? null;
            if ($photo === null) {
                continue;
            }
            $drawing = new Drawing();
            $drawing->setPath($photo);
            $drawing->setCoordinates($cell);
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);
            $drawing->setResizeProportional(true);
            $drawing->setHeight(155);
            if ($drawing->getWidth() > 180) {
                $drawing->setWidth(180); // 横長写真は幅基準に（比率は保持される）
            }
            $drawing->setWorksheet($sheet);
        }
        $sheet->setSelectedCell('A1');
    }
    $spreadsheet->setActiveSheetIndex(0);

    return $spreadsheet;
}

/**
 * ids パラメータ文字列を検証し、対象 orders 行（同一店舗・同一種別・未取消）を返す。
 * 検証エラーは InvalidArgumentException（メッセージ=ユーザー向け文言）。
 *
 * @param string $idsParam      カンマ区切りの発注番号
 * @param array  $allowedTypes  許可する発注種別
 */
function fetchOrdersForAttachment(string $idsParam, array $allowedTypes): array
{
    $orderIds = [];
    foreach (explode(',', $idsParam) as $idStr) {
        $idStr = trim($idStr);
        if ($idStr !== '' && preg_match('/\A[A-Za-z0-9\-]{1,30}\z/', $idStr)) {
            $orderIds[] = $idStr;
        }
    }
    $orderIds = array_values(array_unique($orderIds));
    if (empty($orderIds)) {
        throw new InvalidArgumentException('発注番号が指定されていません');
    }
    if (count($orderIds) > 20) {
        throw new InvalidArgumentException('一度に出力できるのは20件までです');
    }

    // PDO は同一の名前付きプレースホルダを2回使えないため、IN句用とFIELD句用で別名にする
    $phIn = [];
    $phField = [];
    $params = [];
    foreach ($orderIds as $i => $oid) {
        $phIn[] = ':foa' . $i;
        $phField[] = ':fob' . $i;
        $params[':foa' . $i] = $oid;
        $params[':fob' . $i] = $oid;
    }
    $orders = query(
        'SELECT o.id, o.type, o.shop_code, o.date, o.cancelled_at, s.name AS shop_name
           FROM orders o
           JOIN shops s ON s.code = o.shop_code
          WHERE o.id IN (' . implode(',', $phIn) . ')
          ORDER BY FIELD(o.id, ' . implode(',', $phField) . ')',
        $params
    );
    if (count($orders) !== count($orderIds)) {
        throw new InvalidArgumentException('発注が見つかりません');
    }

    $type = $orders[0]['type'];
    if (!in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('この種別の発注には対応していません');
    }
    $shopCode = $orders[0]['shop_code'];
    foreach ($orders as $o) {
        if ($o['type'] !== $type) {
            throw new InvalidArgumentException('種別の異なる発注はまとめて出力できません');
        }
        if ($o['shop_code'] !== $shopCode) {
            throw new InvalidArgumentException('店舗の異なる発注はまとめて出力できません');
        }
        if ($o['cancelled_at'] !== null) {
            throw new InvalidArgumentException('取消された発注が含まれています');
        }
    }
    return $orders;
}
