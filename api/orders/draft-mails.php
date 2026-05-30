<?php declare(strict_types=1);

/**
 * 快活システム - 発注メール下書き作成API
 *
 * GET /api/orders/draft-mails.php
 *   ?zone=&area=&shop=&category=&date_from=&date_to=
 *
 * 「依頼中（status=0）」の備品発注を仕入先単位に集計してメール下書きを返却する。
 * 仕入先マスタ（suppliers）と仕入先名で LEFT JOIN し、To アドレスを補完する。
 *
 * レスポンス:
 *   { success: true, data: { suppliers: [ {supplier, email, contact, items[], order_ids[], total_qty, total_amount, shops[]}, ... ] } }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('GET');

$user = getCurrentUser();

// 管理者・system のみ
if (!in_array($user['role'], ['admin', 'system'], true)) {
    jsonError('権限がありません', 403);
}

// --- パラメータ取得（発注一覧と同じフィルタを引き継ぐ） ---
$shopCode = $_GET['shop']      ?? '';
$zoneCode = $_GET['zone']      ?? '';
$areaCode = $_GET['area']      ?? '';
$category = $_GET['category']  ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
$idsParam = $_GET['ids']       ?? '';

// 選択行ID（カンマ区切り）。指定された場合は他フィルタより優先して該当発注のみ対象
// orders.id は VARCHAR(30) の発注番号（例: EQU-S04-20260523-0001）
$selectedIds = [];
if ($idsParam !== '') {
    foreach (explode(',', (string)$idsParam) as $idStr) {
        $idStr = trim($idStr);
        if ($idStr !== '' && preg_match('/\A[A-Za-z0-9\-]{1,30}\z/', $idStr)) {
            $selectedIds[] = $idStr;
        }
    }
}

// --- メインクエリ: 依頼中(status=0) かつ 備品(type=equipment) の明細を取得 ---
$sql = "SELECT
            o.id              AS order_id,
            o.shop_code,
            o.date            AS order_date,
            o.category_code,
            s.name            AS shop_name,
            i.id              AS item_id,
            i.product_name,
            i.product_code,
            p.supplier_product_code,
            i.price,
            i.qty,
            i.supplier        AS supplier_name
        FROM orders o
        JOIN shops s ON o.shop_code = s.code
        JOIN order_equipment_items i ON i.order_id = o.id
        LEFT JOIN products p ON i.product_id = p.id
        WHERE o.status = 0
          AND o.type = 'equipment'
          AND o.cancelled_at IS NULL";

$joins  = [];
$where  = [];
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

// 選択された発注IDが指定されていれば IN 句で絞り込み
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
    $sql = preg_replace(
        '/JOIN order_equipment_items/',
        implode(' ', $joins) . ' JOIN order_equipment_items',
        $sql,
        1
    );
}
if (!empty($where)) {
    $sql .= ' AND ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY i.supplier, o.shop_code, o.date, i.id';

$rows = query($sql, $params);

// --- 仕入先マスタを参照してメアド補完 ---
$supplierMaster = [];
foreach (query('SELECT name, email, contact FROM suppliers WHERE is_active = 1') as $s) {
    $supplierMaster[$s['name']] = [
        'email'   => $s['email'],
        'contact' => $s['contact'],
    ];
}

// --- 仕入先名でグループ化 ---
$grouped = [];
foreach ($rows as $r) {
    $supplierName = ($r['supplier_name'] !== null && $r['supplier_name'] !== '')
        ? $r['supplier_name']
        : '(仕入先未設定)';

    if (!isset($grouped[$supplierName])) {
        $master = $supplierMaster[$supplierName] ?? ['email' => '', 'contact' => ''];
        $grouped[$supplierName] = [
            'supplier'      => $supplierName,
            'email'         => $master['email'] ?? '',
            'contact'       => $master['contact'] ?? '',
            'items'         => [],
            'order_ids'     => [],
            'shops'         => [],
            'total_qty'     => 0,
            'total_amount'  => 0,
        ];
    }

    $price    = (int)$r['price'];
    $qty      = (int)$r['qty'];
    $subtotal = $price * $qty;

    $grouped[$supplierName]['items'][] = [
        'order_id'     => $r['order_id'],
        'shop_code'    => $r['shop_code'],
        'shop_name'    => $r['shop_name'],
        'order_date'   => $r['order_date'],
        'product_name' => $r['product_name'],
        'product_code' => $r['product_code'],
        'supplier_product_code' => $r['supplier_product_code'],
        'price'        => $price,
        'qty'          => $qty,
        'subtotal'     => $subtotal,
    ];

    if (!in_array($r['order_id'], $grouped[$supplierName]['order_ids'], true)) {
        $grouped[$supplierName]['order_ids'][] = $r['order_id'];
    }
    if (!in_array($r['shop_name'], $grouped[$supplierName]['shops'], true)) {
        $grouped[$supplierName]['shops'][] = $r['shop_name'];
    }
    $grouped[$supplierName]['total_qty']    += $qty;
    $grouped[$supplierName]['total_amount'] += $subtotal;
}

// 連想配列 → リスト化（フロントが扱いやすいよう）
$suppliers = array_values($grouped);

// CC 用: 商品部メアド (system_settings.product_dept_email)
$ccRow = getOne(
    "SELECT `value` FROM system_settings WHERE `key` = 'product_dept_email' AND is_active = 1"
);
$ccEmail = $ccRow['value'] ?? '';

jsonResponse([
    'success' => true,
    'data'    => [
        'suppliers'    => $suppliers,
        'cc_email'     => $ccEmail,
        'fetched_at'   => date('Y-m-d H:i:s'),
        'requester'    => $user['name'] ?? '',
    ],
]);
