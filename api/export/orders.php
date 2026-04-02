<?php declare(strict_types=1);

/**
 * 快活システム - 発注データCSV出力API
 *
 * GET /api/export/orders.php?type=&status=&shop=&zone=&area=&category=&date_from=&date_to=
 * - BOM付きUTF-8 CSV（Excel対応）
 * - クエリパラメータは orders.php と同じ
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

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

// --- 関連データ取得（発注がある場合のみ） ---
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

    // 修理詳細
    $repairSql = "SELECT order_id, equipment_name, issue
                  FROM order_repair_details
                  WHERE order_id IN ({$placeholders})";
    $repairRows = query($repairSql, $idParams);
    foreach ($repairRows as $row) {
        $repairDetails[$row['order_id']] = $row;
    }

    // 部品詳細
    $partsSql = "SELECT order_id, parts_name, target_equipment, reason, quantity
                 FROM order_parts_details
                 WHERE order_id IN ({$placeholders})";
    $partsRows = query($partsSql, $idParams);
    foreach ($partsRows as $row) {
        $partsDetails[$row['order_id']] = $row;
    }

    // 備品明細
    $equipSql = "SELECT order_id, product_name, product_code, price, qty, supplier
                 FROM order_equipment_items
                 WHERE order_id IN ({$placeholders})
                 ORDER BY id";
    $equipRows = query($equipSql, $idParams);
    foreach ($equipRows as $row) {
        $equipItems[$row['order_id']][] = $row;
    }
}

// --- CSV出力 ---
$filename = 'orders_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM

// ヘッダ行
$header = [
    '発注番号',
    '種別',
    'ステータス',
    '店舗コード',
    '店舗名',
    '発注日',
    '見積金額',
    '確定金額',
    '納品予定日',
    '納品実績日',
    '内容',
    '詳細',
    '登録日時',
];
fputcsv($output, $header);

// データ行
foreach ($orders as $order) {
    $id   = $order['id'];
    $oType = $order['type'];

    // 内容・詳細を種別に応じて組み立て
    $content = '';
    $detail  = '';

    if ($oType === 'repair') {
        $rd = $repairDetails[$id] ?? null;
        $content = $rd['equipment_name'] ?? '';
        $detail  = $rd['issue'] ?? '';
    } elseif ($oType === 'equipment') {
        $items = $equipItems[$id] ?? [];
        $itemTexts = [];
        foreach ($items as $ei) {
            $itemTexts[] = $ei['product_name'] . ' × ' . $ei['qty'];
        }
        $content = implode('、', $itemTexts);
    } elseif ($oType === 'parts') {
        $pd = $partsDetails[$id] ?? null;
        $content = $pd['parts_name'] ?? '';
        $detail  = ($pd['target_equipment'] ?? '') !== '' ? '対象: ' . $pd['target_equipment'] : '';
        if (($pd['reason'] ?? '') !== '') {
            $detail .= ($detail !== '' ? ' / ' : '') . '理由: ' . $pd['reason'];
        }
    }

    $row = [
        $order['category_code'],
        $typeLabels[$oType] ?? $oType,
        $statusLabels[(int)$order['status']] ?? $order['status'],
        $order['shop_code'],
        $order['shop_name'],
        $order['date'],
        $order['estimate_amount'] ?? '',
        $order['final_amount'] ?? '',
        $order['delivery_date'] ?? '',
        $order['actual_delivery_date'] ?? '',
        $content,
        $detail,
        $order['created_at'],
    ];

    fputcsv($output, $row);
}

fclose($output);
exit;
