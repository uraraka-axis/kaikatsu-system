<?php declare(strict_types=1);

/**
 * 快活システム - 発注一覧API
 *
 * GET /api/orders.php?type=&status=&shop=&zone=&area=&category=&date_from=&date_to=
 * - 店舗ユーザー: 自店のデータのみ
 * - 管理者: フィルタに応じて全店 or 絞り込み
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

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

// --- ロール別の閲覧スコープ ---
// shop: 自店のみ / admin/system: 全店 / zone: 管轄ゾーン配下 / area: 管轄エリア配下
$scope = getRoleScopeSql($user, 's', 'o.shop_code');

if ($user['role'] === 'shop') {
    // shop はフロントから渡されたフィルタを無視して自店縛り
    $shopCode = $user['shop_code'];
    $zoneCode = '';
    $areaCode = '';
} elseif ($user['role'] === 'zone') {
    // zone はゾーン縛り（フロントの zone/area/shop はゾーン内の絞り込みとして許容）
    $zoneCode = $user['zone_code'];
} elseif ($user['role'] === 'area') {
    // area はエリア縛り
    $areaCode = $user['area_code'];
    $zoneCode = '';
}

// --- バリデーション ---
if ($type !== '' && !in_array($type, ['repair', 'equipment', 'parts', 'seat-replacement'], true)) {
    jsonError('不正な種別パラメータです');
}
if ($status !== '' && !in_array($status, ['0', '1', '2', '3', '4'], true)) {
    jsonError('不正なステータスパラメータです');
}

// --- メインクエリ組み立て ---
$sql = 'SELECT o.id, o.type, o.category_code, o.status, o.shop_code, o.date,
               o.estimate_amount, o.final_amount, o.delivery_date, o.actual_delivery_date,
               o.created_at, o.updated_at,
               s.name AS shop_name
        FROM orders o
        JOIN shops s ON o.shop_code = s.code';

$joins = [];
// 取消発注（cancelled_at IS NOT NULL）は常に一覧から除外
$where = ['o.cancelled_at IS NULL'];
$params = [];

// ゾーン・エリアフィルタ時はareas JOINが必要
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

// JOINs追加
if (!empty($joins)) {
    $sql .= ' ' . implode(' ', $joins);
}

// WHERE追加
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY o.date DESC, o.created_at DESC, o.id DESC';

$orders = query($sql, $params);

if (empty($orders)) {
    jsonResponse([
        'success' => true,
        'data'    => [],
    ]);
}

// --- 発注IDリスト ---
$orderIds = array_column($orders, 'id');

// --- IN句用プレースホルダ ---
$placeholders = implode(',', array_map(fn($i) => ':oid' . $i, array_keys($orderIds)));
$idParams = [];
foreach ($orderIds as $i => $oid) {
    $idParams[':oid' . $i] = $oid;
}

// --- 修理詳細 ---
$repairDetails = [];
$repairSql = "SELECT order_id, equipment_name, issue, repair_schedule_date, repair_completed_date
              FROM order_repair_details
              WHERE order_id IN ({$placeholders})";
$repairRows = query($repairSql, $idParams);
foreach ($repairRows as $row) {
    $repairDetails[$row['order_id']] = $row;
}

// --- シート交換詳細 ---
$seatReplacementDetails = [];
$seatSql = "SELECT order_id, equipment_name, issue, repair_schedule_date, repair_completed_date
            FROM order_seat_replacement_details
            WHERE order_id IN ({$placeholders})";
$seatRows = query($seatSql, $idParams);
foreach ($seatRows as $row) {
    $seatReplacementDetails[$row['order_id']] = $row;
}

// --- 修理不可日 ---
$unavailDates = [];
$unavailDatesSql = "SELECT order_id, date, is_all_day, time_start, time_end
                    FROM order_repair_unavail_dates
                    WHERE order_id IN ({$placeholders})
                    ORDER BY date";
$unavailDateRows = query($unavailDatesSql, $idParams);
foreach ($unavailDateRows as $row) {
    $unavailDates[$row['order_id']][] = $row;
}

// --- 修理不可曜日 ---
$unavailDays = [];
$unavailDaysSql = "SELECT order_id, day_of_week
                   FROM order_repair_unavail_days
                   WHERE order_id IN ({$placeholders})";
$unavailDayRows = query($unavailDaysSql, $idParams);
foreach ($unavailDayRows as $row) {
    $unavailDays[$row['order_id']][] = $row['day_of_week'];
}

// --- 写真 ---
// セキュリティ: file_path を直接返さず、api/photo.php?id={photo_id} 経由のURLに変換。
// URL は相対パスで返す（テストサイト等ベースパスが変わる環境でもそのまま表示可能。
// 備品画像 api/product-image.php と同じ相対指定に統一）。
$photoData = [];
$photoSql = "SELECT id, order_id, original_filename
             FROM order_photos
             WHERE order_id IN ({$placeholders})
             ORDER BY sort_order, id";
$photoRows = query($photoSql, $idParams);
foreach ($photoRows as $row) {
    $photoData[$row['order_id']][] = [
        'url'      => 'api/photo.php?id=' . (int)$row['id'],
        'filename' => $row['original_filename'],
    ];
}

// --- 備品明細 ---
$equipItems = [];
$equipSql = "SELECT id, order_id, product_name, product_code, price, qty, supplier, arrival_date
             FROM order_equipment_items
             WHERE order_id IN ({$placeholders})
             ORDER BY id";
$equipRows = query($equipSql, $idParams);
foreach ($equipRows as $row) {
    $equipItems[$row['order_id']][] = [
        'id'           => (int)$row['id'],
        'product_name' => $row['product_name'],
        'product_code' => $row['product_code'],
        'price'        => (int)$row['price'],
        'qty'          => (int)$row['qty'],
        'supplier'     => $row['supplier'],
        'arrival_date' => $row['arrival_date'],
    ];
}

// --- 部品詳細 ---
$partsDetails = [];
$partsSql = "SELECT order_id, parts_name, target_equipment, reason, quantity
             FROM order_parts_details
             WHERE order_id IN ({$placeholders})";
$partsRows = query($partsSql, $idParams);
foreach ($partsRows as $row) {
    $partsDetails[$row['order_id']] = $row;
}

// --- ステータス履歴 ---
$statusHistories = [];
$historySql = "SELECT order_id, status, changed_at, changed_by, memo
              FROM order_status_history
              WHERE order_id IN ({$placeholders})
              ORDER BY order_id, id";
$historyRows = query($historySql, $idParams);
foreach ($historyRows as $row) {
    $statusHistories[$row['order_id']][] = [
        'status'     => (int)$row['status'],
        'changed_at' => $row['changed_at'],
        'changed_by' => $row['changed_by'],
        'memo'       => $row['memo'],
    ];
}

// --- レスポンス組み立て ---
$data = [];
foreach ($orders as $order) {
    $id = $order['id'];
    $orderType = $order['type'];

    $item = [
        'id'                   => $id,
        'type'                 => $orderType,
        'category_code'        => $order['category_code'],
        'status'               => (int)$order['status'],
        'shop_code'            => $order['shop_code'],
        'shop_name'            => $order['shop_name'],
        'date'                 => $order['date'],
        'estimate_amount'      => $order['estimate_amount'] !== null ? (int)$order['estimate_amount'] : null,
        'final_amount'         => $order['final_amount'] !== null ? (int)$order['final_amount'] : null,
        'delivery_date'        => $order['delivery_date'],
        'actual_delivery_date' => $order['actual_delivery_date'],
        'status_history'       => $statusHistories[$id] ?? [],
        'content_label'        => '',
    ];

    // 種別固有データ
    if ($orderType === 'repair') {
        $rd = $repairDetails[$id] ?? null;
        $item['equipment_name']        = $rd['equipment_name'] ?? '';
        $item['issue']                 = $rd['issue'] ?? '';
        $item['repair_schedule_date']  = $rd['repair_schedule_date'] ?? null;
        $item['repair_completed_date'] = $rd['repair_completed_date'] ?? null;
        $item['unavail_dates']         = $unavailDates[$id] ?? [];
        $item['unavail_days']          = $unavailDays[$id] ?? [];
        $item['photos']                = $photoData[$id] ?? [];
        $item['content_label']         = $rd['equipment_name'] ?? '';
    } elseif ($orderType === 'seat-replacement') {
        $sd = $seatReplacementDetails[$id] ?? null;
        $item['equipment_name']        = $sd['equipment_name'] ?? '';
        $item['issue']                 = $sd['issue'] ?? 'マシンのシート交換';
        $item['repair_schedule_date']  = $sd['repair_schedule_date'] ?? null;
        $item['repair_completed_date'] = $sd['repair_completed_date'] ?? null;
        $item['unavail_dates']         = $unavailDates[$id] ?? [];
        $item['unavail_days']          = $unavailDays[$id] ?? [];
        $item['photos']                = $photoData[$id] ?? [];
        $item['content_label']         = $sd['equipment_name'] ?? '';
    } elseif ($orderType === 'equipment') {
        $items = $equipItems[$id] ?? [];
        $item['equip_items'] = $items;
        // Content label: 1商品なら「商品名 × qty」、複数なら「商品名 他N商品」
        if (count($items) === 1) {
            $first = $items[0];
            $item['content_label'] = $first['product_name'] . ' × ' . $first['qty'];
        } elseif (count($items) > 1) {
            $first = $items[0];
            $otherCount = count($items) - 1;
            $item['content_label'] = $first['product_name'] . ' 他' . $otherCount . '商品';
        }
    } elseif ($orderType === 'parts') {
        $pd = $partsDetails[$id] ?? null;
        $item['parts_name']        = $pd['parts_name'] ?? '';
        $item['target_equipment']  = $pd['target_equipment'] ?? '';
        $item['reason']            = $pd['reason'] ?? '';
        $item['quantity']          = $pd['quantity'] !== null ? (int)$pd['quantity'] : 1;
        $item['photos']            = $photoData[$id] ?? [];
        $item['content_label']     = $pd['parts_name'] ?? '';
    }

    $data[] = $item;
}

jsonResponse([
    'success' => true,
    'data'    => $data,
]);
