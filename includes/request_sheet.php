<?php declare(strict_types=1);

/**
 * 快活システム - 修理依頼書 / 部品発注依頼書 / シート発注依頼書 Excel生成（共通処理）
 *
 * メール下書きの添付ファイル用。テンプレートは快活様支給ひな形
 * templates/部品発注・修理依頼書_原本_20261001.xlsx（修理依頼書／部品発注依頼書／シート発注依頼書の3シート）。
 * 対象種別のシートだけを残し、1発注=1シートで差し込む（複数件は「修理依頼書(2)」…）。
 * チェア修理(chair-repair)は修理依頼書シートを宛名なしで流用（依頼先未確定のため）。
 * 修理・チェア修理はシリアルナンバー写真セクションを追加して差し込む。
 *
 * 利用箇所: api/orders/request-sheet.php（単体DL）/ api/orders/draft-mail-zip.php（一式zip）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 写真1枚を指定セルの枠（2列×8行 ≒ 184×136px）に収まるサイズで配置する。
 * 行高はひな形既定（Arial 10ptの自動行高≒17px×8行=136px）のため、高さ125px上限で枠内に収める。
 */
function placeRequestSheetPhoto(Worksheet $sheet, string $cell, string $path): void
{
    $drawing = new Drawing();
    $drawing->setPath($path);
    $drawing->setCoordinates($cell);
    $drawing->setOffsetX(4);
    $drawing->setOffsetY(4);
    $drawing->setResizeProportional(true);
    $drawing->setHeight(125);
    if ($drawing->getWidth() > 180) {
        $drawing->setWidth(180); // 横長写真は幅基準に（比率は保持される）
    }
    $drawing->setWorksheet($sheet);
}

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
    } elseif ($type === 'chair-repair') {
        // チェア修理は機材固定のため対象マシン=「マッサージチェア 製造番号」
        foreach (query(
            "SELECT order_id, serial_no, issue FROM order_chair_repair_details WHERE order_id IN ({$in})",
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

    // シリアルナンバー写真（修理・チェア修理のみ・発注ごと1枚。依頼書に必須項目として載せる）
    $serialFiles = [];
    if ($type === 'repair' || $type === 'chair-repair') {
        foreach (query(
            "SELECT order_id, file_path FROM order_photos
              WHERE order_id IN ({$in}) AND photo_kind = 'serial'
              ORDER BY order_id, sort_order, id",
            $params
        ) as $row) {
            if (isset($serialFiles[$row['order_id']])) {
                continue;
            }
            $abs = realpath(__DIR__ . '/../' . $row['file_path']);
            if ($abs !== false && $baseDir !== false
                && strncmp($abs, $baseDir . DIRECTORY_SEPARATOR, strlen($baseDir) + 1) === 0
                && is_file($abs)) {
                $serialFiles[$row['order_id']] = $abs;
            }
        }
    }

    // テンプレート読み込み
    $templatePath = __DIR__ . '/../templates/部品発注・修理依頼書_原本_20261001.xlsx';
    if (!is_file($templatePath)) {
        throw new RuntimeException('依頼書テンプレートが見つかりません: ' . $templatePath);
    }
    $spreadsheet = IOFactory::load($templatePath);

    // 種別ごとのシート名・差し込み座標（支給ひな形のレイアウト。チェア修理は修理依頼書を宛名なしで流用）
    if ($type === 'repair' || $type === 'chair-repair') {
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

    // --- ひな形の体裁調整（支給ひな形はGoogleスプレッドシート出力のため行高固定・宛名1セル詰め） ---
    // 宛名: 「会社名\nご担当者様」が1セルのままだと改行が表示されず右に見切れる → A2/A3 の2行に分割
    $atena = $baseSheet->getCell('A2')->getValue();
    if (is_string($atena) && strpos($atena, "\n") !== false) {
        [$atena1, $atena2] = explode("\n", $atena, 2);
        $baseSheet->setCellValueExplicit('A2', trim($atena1), DataType::TYPE_STRING);
        $baseSheet->setCellValueExplicit('A3', trim($atena2), DataType::TYPE_STRING);
        $baseSheet->duplicateStyle($baseSheet->getStyle('A2'), 'A3');
    }
    if ($type === 'chair-repair') {
        // チェア修理は依頼先未確定のため宛名なしで生成（送付時に手書き・手入力する想定）
        $baseSheet->setCellValueExplicit('A2', '', DataType::TYPE_STRING);
        $baseSheet->setCellValueExplicit('A3', '', DataType::TYPE_STRING);
    }
    // タイトル(20pt)・見出し(14pt)が既定行高15.75ptで上下見切れる → 行高を確保
    $baseSheet->getRowDimension(4)->setRowHeight(30);
    $baseSheet->getRowDimension(6)->setRowHeight(20);
    // 依頼日の表示形式が mm-dd-yy のままなので和式に
    $baseSheet->getStyle('F2')->getNumberFormat()->setFormatCode('yyyy/m/d');
    // 差し込みセルはひな形の例文色（薄グレー）を引き継がず黒字にし、長文が右に見切れないよう折り返す
    $valueCells = $type === 'parts' ? ['A8', 'A12', 'A16', 'A20', 'E20'] : ['A8', 'A12', 'A16'];
    foreach ($valueCells as $vc) {
        $style = $baseSheet->getStyle($vc);
        $style->getFont()->getColor()->setARGB('FF000000');
        if ($vc !== 'E20') { // E20(個数)は数値1文字のため元の配置のまま
            $style->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
        }
    }
    // 3行結合の記入枠は既定行高(15.75pt)×3だと3行目の下端が欠けるため行高を確保
    $wrapRows = array_merge(range(8, 10), range(12, 14), range(16, 18), $type === 'parts' ? range(20, 25) : []);
    foreach ($wrapRows as $wr) {
        $baseSheet->getRowDimension($wr)->setRowHeight(17);
    }

    // 修理・チェア修理: 不具合箇所写真の直下にシリアルナンバー写真セクションを追加（申請時の必須項目のため依頼書にも載せる）
    $serialPhotoCell = null;
    if ($type === 'repair' || $type === 'chair-repair') {
        $baseSheet->insertNewRowBefore(28, 9); // 見出し28＋写真枠29-36。依頼文(旧29)は38へ
        $baseSheet->duplicateStyle($baseSheet->getStyle('A19'), 'A28:F28');
        $baseSheet->mergeCells('A28:F28');
        $baseSheet->setCellValueExplicit('A28', 'シリアルナンバー写真', DataType::TYPE_STRING);
        for ($r = 20; $r <= 27; $r++) { // 不具合箇所写真の3枠(A20:F27)と同じ罫線になるよう全列のスタイルをセル単位で複製
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                $baseSheet->duplicateStyle($baseSheet->getStyle($col . $r), $col . ($r + 9));
            }
        }
        $baseSheet->mergeCells('A29:B36');
        $baseSheet->mergeCells('C29:D36');
        $baseSheet->mergeCells('E29:F36');
        $serialPhotoCell = 'A29';
    }

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
        // 差し込みは必ず文字列型で（setCellValue だと「=」始まりの入力値が数式扱いになる＝数式インジェクション）
        if ($type === 'parts') {
            // 部品: 対象マシン=対象機材 / 発生症状=発注理由 / 部品内容・個数=部品名・数量
            $sheet->setCellValueExplicit('A8', $d['target_equipment'] ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A12', 'FiT24 ' . $o['shop_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A16', $d['reason'] ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A20', $d['parts_name'] ?? '', DataType::TYPE_STRING);
            if (isset($d['quantity'])) {
                $sheet->setCellValue('E20', (int)$d['quantity']);
            } else {
                $sheet->setCellValueExplicit('E20', '', DataType::TYPE_STRING);
            }
        } else {
            $machine = $type === 'chair-repair'
                ? 'マッサージチェア' . ((($d['serial_no'] ?? '') !== '') ? '（製造番号: ' . $d['serial_no'] . '）' : '')
                : ($d['equipment_name'] ?? '');
            $sheet->setCellValueExplicit('A8', $machine, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A12', 'FiT24 ' . $o['shop_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('A16', $d['issue'] ?? '', DataType::TYPE_STRING);
        }
        foreach ($clearCells as $cc) {
            $sheet->setCellValue($cc, '');
        }

        // 写真枠: 例文「画像が入る」を消し、damage 写真を最大3枚配置
        foreach ($photoCells as $pi => $cell) {
            $sheet->setCellValue($cell, '');
            $photo = $photoFiles[$o['id']][$pi] ?? null;
            if ($photo !== null) {
                placeRequestSheetPhoto($sheet, $cell, $photo);
            }
        }
        // シリアルナンバー写真（修理のみ・1枚）
        if ($serialPhotoCell !== null && isset($serialFiles[$o['id']])) {
            placeRequestSheetPhoto($sheet, $serialPhotoCell, $serialFiles[$o['id']]);
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
