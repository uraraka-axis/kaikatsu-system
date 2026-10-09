<?php declare(strict_types=1);

/**
 * 快活システム - 発注メール下書き作成API
 *
 * GET /api/orders/draft-mails.php
 *   ?zone=&area=&shop=&category=&date_from=&date_to=
 *
 * 「依頼中（status=0）」の発注を集計してメール下書きを返却する。
 *   - suppliers:       備品（type=equipment）を仕入先単位に集計
 *   - chair_suppliers: チェア備品（type=chair-equipment）を仕入先単位に集計（発注書PDFのDL用に orders[] 付き）
 *   - club:            代替ゴルフ（type=club-replacement）を全店舗まとめて1通（宛先=仕入先マスタのランシステム）
 *   - repairs:         修理（type=repair）・チェア修理（type=chair-repair）1発注=1通
 *                      （修理の宛先は手入力＝メーカー複数・チェア修理は仕入先マスタ「日本メディック」補完。
 *                      同一店舗の修理はフロント側で1通にまとめ可）。添付写真一覧（photos）つき
 *   - parts:           部品（type=parts）1発注=1通（宛先は手入力）。添付写真一覧つき
 *   - seats:           シート交換（type=seat-replacement）1発注=1通（宛先=仕入先マスタ「ポップサイクル」補完）。添付写真一覧つき
 * 仕入先マスタ（suppliers）と仕入先名を突合し、To アドレスを補完する。
 *
 * レスポンス:
 *   { success: true, data: { suppliers: [...], chair_suppliers: [...], club: {...}|null, repairs: [...] } }
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

// --- チェア備品(status=0) の下書き（備品と同様に仕入先単位・発注書PDFのDL付き） ---
$csql = "SELECT
            o.id              AS order_id,
            o.shop_code,
            o.date            AS order_date,
            s.name            AS shop_name,
            i.id              AS item_id,
            i.product_name,
            i.product_code,
            p.supplier_product_code,
            i.price,
            i.qty,
            i.supplier        AS supplier_name
        FROM orders o
        JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $csql .= ' JOIN areas a ON s.area_code = a.code';
}
$csql .= " JOIN order_equipment_items i ON i.order_id = o.id
        LEFT JOIN products p ON i.product_id = p.id
        WHERE o.status = 0
          AND o.type = 'chair-equipment'
          AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $csql .= ' AND ' . implode(' AND ', $where);
}
$csql .= ' ORDER BY i.supplier, o.shop_code, o.date, i.id';

$chairGrouped = [];
foreach (query($csql, $params) as $r) {
    $supplierName = ($r['supplier_name'] !== null && $r['supplier_name'] !== '')
        ? $r['supplier_name']
        : '(仕入先未設定)';

    if (!isset($chairGrouped[$supplierName])) {
        $master = $supplierMaster[$supplierName] ?? ['email' => '', 'contact' => ''];
        $chairGrouped[$supplierName] = [
            'supplier'      => $supplierName,
            'email'         => $master['email'] ?? '',
            'contact'       => $master['contact'] ?? '',
            'items'         => [],
            'order_ids'     => [],
            'orders'        => [], // 発注書PDFダウンロードリンク用（1発注=1PDF）
            'shops'         => [],
            'total_qty'     => 0,
            'total_amount'  => 0,
        ];
    }

    $price    = (int)$r['price'];
    $qty      = (int)$r['qty'];
    $subtotal = $price * $qty;

    $chairGrouped[$supplierName]['items'][] = [
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

    if (!in_array($r['order_id'], $chairGrouped[$supplierName]['order_ids'], true)) {
        $chairGrouped[$supplierName]['order_ids'][] = $r['order_id'];
        $chairGrouped[$supplierName]['orders'][] = [
            'order_id'   => $r['order_id'],
            'shop_code'  => $r['shop_code'],
            'shop_name'  => $r['shop_name'],
            'order_date' => $r['order_date'],
        ];
    }
    if (!in_array($r['shop_name'], $chairGrouped[$supplierName]['shops'], true)) {
        $chairGrouped[$supplierName]['shops'][] = $r['shop_name'];
    }
    $chairGrouped[$supplierName]['total_qty']    += $qty;
    $chairGrouped[$supplierName]['total_amount'] += $subtotal;
}
$chairSuppliers = array_values($chairGrouped);

// --- 代替ゴルフ(status=0) の下書き（複数店舗をまとめて1通。宛先=仕入先マスタのランシステム） ---
$gsql = "SELECT o.id AS order_id, o.shop_code, o.date AS order_date, s.name AS shop_name,
                cd.club, cd.shaft
         FROM orders o
         JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $gsql .= ' JOIN areas a ON s.area_code = a.code';
}
$gsql .= " JOIN order_club_replacement_details cd ON cd.order_id = o.id
           WHERE o.status = 0 AND o.type = 'club-replacement' AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $gsql .= ' AND ' . implode(' AND ', $where);
}
$gsql .= ' ORDER BY o.shop_code, o.date, o.id';

$clubOrders = [];
foreach (query($gsql, $params) as $r) {
    $clubOrders[] = [
        'order_id'   => $r['order_id'],
        'shop_code'  => $r['shop_code'],
        'shop_name'  => $r['shop_name'],
        'order_date' => $r['order_date'],
        'club'       => $r['club'],
        'shaft'      => $r['shaft'],
    ];
}

// 宛先: 仕入先マスタの「ランシステム」（名称部分一致）
$club = null;
if (!empty($clubOrders)) {
    $runsystem = ['name' => '', 'email' => '', 'contact' => ''];
    foreach ($supplierMaster as $name => $m) {
        if (mb_strpos($name, 'ランシステム') !== false) {
            $runsystem = ['name' => $name, 'email' => $m['email'] ?? '', 'contact' => $m['contact'] ?? ''];
            break;
        }
    }
    $club = [
        'supplier' => $runsystem['name'] !== '' ? $runsystem['name'] : '株式会社ランシステム',
        'email'    => $runsystem['email'],
        'contact'  => $runsystem['contact'],
        'orders'   => $clubOrders,
        'order_ids' => array_column($clubOrders, 'order_id'),
        'shops'    => array_values(array_unique(array_column($clubOrders, 'shop_name'))),
    ];
}

// --- 添付写真の一覧を作るヘルパー ---
// 発注ごとの写真を「発注番号_写真種別_連番.拡張子」のダウンロード名つきで返す。
// 個別DLは api/photo.php?id=...&dl=1（サーバ側でも同じ命名規則でファイル名を付与）。
$photosForOrders = static function (array $orderIds): array {
    if (empty($orderIds)) {
        return [];
    }
    $ph = [];
    $ps = [];
    foreach (array_values($orderIds) as $i => $oid) {
        $ph[] = ':pid' . $i;
        $ps[':pid' . $i] = $oid;
    }
    $rows = query(
        "SELECT id, order_id, photo_kind, original_filename
           FROM order_photos
          WHERE order_id IN (" . implode(',', $ph) . ")
          ORDER BY order_id, (COALESCE(photo_kind, 'damage') = 'serial'), sort_order, id",
        $ps
    );
    $result = [];
    $damageSeq = [];
    foreach ($rows as $p) {
        $oid  = $p['order_id'];
        $kind = $p['photo_kind'] ?: 'damage';
        $ext  = strtolower(pathinfo((string)$p['original_filename'], PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }
        if ($kind === 'serial') {
            $dlName = sprintf('%s_シリアル.%s', $oid, $ext);
        } else {
            $damageSeq[$oid] = ($damageSeq[$oid] ?? 0) + 1;
            $dlName = sprintf('%s_故障箇所_%d.%s', $oid, $damageSeq[$oid], $ext);
        }
        $result[$oid][] = [
            'id'      => (int)$p['id'],
            'kind'    => $kind,
            'dl_name' => $dlName,
        ];
    }
    return $result;
};

// --- 修理(status=0) の下書き（1発注=1通。宛先は手入力。同一店舗はフロント側で1通にまとめ可） ---
$rsql = "SELECT o.id AS order_id, o.shop_code, o.date AS order_date, s.name AS shop_name,
                rd.equipment_name, rd.issue
         FROM orders o
         JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $rsql .= ' JOIN areas a ON s.area_code = a.code';
}
$rsql .= " JOIN order_repair_details rd ON rd.order_id = o.id
           WHERE o.status = 0 AND o.type = 'repair' AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $rsql .= ' AND ' . implode(' AND ', $where);
}
$rsql .= ' ORDER BY o.shop_code, o.date, o.id';

$repairs = [];
foreach (query($rsql, $params) as $r) {
    $repairs[] = [
        'order_id'       => $r['order_id'],
        'order_type'     => 'repair',
        'shop_code'      => $r['shop_code'],
        'shop_name'      => $r['shop_name'],
        'order_date'     => $r['order_date'],
        'equipment_name' => $r['equipment_name'],
        'issue'          => $r['issue'],
    ];
}

// --- チェア修理(status=0) の下書き（修理と同様 1発注=1通。機材は「マッサージチェア」固定。
//     宛先はチェア備品と同じ日本メディックを仕入先マスタから補完・なければ手入力） ---
$chairRepairSupplier = ['name' => '', 'email' => '', 'contact' => ''];
foreach ($supplierMaster as $name => $m) {
    if (mb_strpos($name, '日本メディック') !== false) {
        $chairRepairSupplier = ['name' => $name, 'email' => $m['email'] ?? '', 'contact' => $m['contact'] ?? ''];
        break;
    }
}
$crsql = "SELECT o.id AS order_id, o.shop_code, o.date AS order_date, s.name AS shop_name,
                 cr.serial_no, cr.issue
          FROM orders o
          JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $crsql .= ' JOIN areas a ON s.area_code = a.code';
}
$crsql .= " JOIN order_chair_repair_details cr ON cr.order_id = o.id
            WHERE o.status = 0 AND o.type = 'chair-repair' AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $crsql .= ' AND ' . implode(' AND ', $where);
}
$crsql .= ' ORDER BY o.shop_code, o.date, o.id';

foreach (query($crsql, $params) as $r) {
    $repairs[] = [
        'order_id'       => $r['order_id'],
        'order_type'     => 'chair-repair',
        'shop_code'      => $r['shop_code'],
        'shop_name'      => $r['shop_name'],
        'order_date'     => $r['order_date'],
        'equipment_name' => 'マッサージチェア'
                            . (($r['serial_no'] ?? '') !== '' ? '（製造番号: ' . $r['serial_no'] . '）' : ''),
        'issue'          => $r['issue'],
        'supplier'       => $chairRepairSupplier['name'],
        'email'          => $chairRepairSupplier['email'],
        'contact'        => $chairRepairSupplier['contact'],
    ];
}

// --- シート交換(status=0) の下書き（1発注=1通。宛先は仕入先マスタの「ポップサイクル」で補完・なければ手入力） ---
$ssql = "SELECT o.id AS order_id, o.shop_code, o.date AS order_date, s.name AS shop_name,
                sd.equipment_name
         FROM orders o
         JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $ssql .= ' JOIN areas a ON s.area_code = a.code';
}
$ssql .= " JOIN order_seat_replacement_details sd ON sd.order_id = o.id
           WHERE o.status = 0 AND o.type = 'seat-replacement' AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $ssql .= ' AND ' . implode(' AND ', $where);
}
$ssql .= ' ORDER BY o.shop_code, o.date, o.id';

$seatSupplier = ['name' => '', 'email' => '', 'contact' => ''];
foreach ($supplierMaster as $name => $m) {
    if (mb_strpos($name, 'ポップサイクル') !== false) {
        $seatSupplier = ['name' => $name, 'email' => $m['email'] ?? '', 'contact' => $m['contact'] ?? ''];
        break;
    }
}
$seats = [];
foreach (query($ssql, $params) as $r) {
    $seats[] = [
        'order_id'       => $r['order_id'],
        'shop_code'      => $r['shop_code'],
        'shop_name'      => $r['shop_name'],
        'order_date'     => $r['order_date'],
        'equipment_name' => $r['equipment_name'],
        'supplier'       => $seatSupplier['name'],
        'email'          => $seatSupplier['email'],
        'contact'        => $seatSupplier['contact'],
    ];
}

// --- 部品(status=0) の下書き（1発注=1通。宛先は手入力＝メーカー宛） ---
$psql = "SELECT o.id AS order_id, o.shop_code, o.date AS order_date, s.name AS shop_name,
                pd.parts_name, pd.target_equipment, pd.quantity, pd.reason
         FROM orders o
         JOIN shops s ON o.shop_code = s.code";
if ($zoneCode !== '') {
    $psql .= ' JOIN areas a ON s.area_code = a.code';
}
$psql .= " JOIN order_parts_details pd ON pd.order_id = o.id
           WHERE o.status = 0 AND o.type = 'parts' AND o.cancelled_at IS NULL";
if (!empty($where)) {
    $psql .= ' AND ' . implode(' AND ', $where);
}
$psql .= ' ORDER BY o.shop_code, o.date, o.id';

$parts = [];
foreach (query($psql, $params) as $r) {
    $parts[] = [
        'order_id'         => $r['order_id'],
        'shop_code'        => $r['shop_code'],
        'shop_name'        => $r['shop_name'],
        'order_date'       => $r['order_date'],
        'parts_name'       => $r['parts_name'],
        'target_equipment' => $r['target_equipment'],
        'quantity'         => (int)$r['quantity'],
        'reason'           => $r['reason'],
    ];
}

// --- 修理・チェア修理・部品・シート交換の添付写真（個別DL・zip 用） ---
$attachOrderIds = array_merge(
    array_column($repairs, 'order_id'),
    array_column($parts, 'order_id'),
    array_column($seats, 'order_id')
);
$photoMap = $photosForOrders($attachOrderIds);
foreach ($repairs as &$rp) {
    $rp['photos'] = $photoMap[$rp['order_id']] ?? [];
}
unset($rp);
foreach ($parts as &$pp) {
    $pp['photos'] = $photoMap[$pp['order_id']] ?? [];
}
unset($pp);
foreach ($seats as &$sp) {
    $sp['photos'] = $photoMap[$sp['order_id']] ?? [];
}
unset($sp);

// CC 用: 商品部メアド (system_settings.product_dept_email)
$ccRow = getOne(
    "SELECT `value` FROM system_settings WHERE `key` = 'product_dept_email' AND is_active = 1"
);
$ccEmail = $ccRow['value'] ?? '';

// メール署名（system_settings.mail_signature。〇〇＝担当者名/メールは手入力プレースホルダ）
$sigRow = getOne(
    "SELECT `value` FROM system_settings WHERE `key` = 'mail_signature' AND is_active = 1"
);
$signature = $sigRow['value'] ?? '';

jsonResponse([
    'success' => true,
    'data'    => [
        'suppliers'       => $suppliers,
        'chair_suppliers' => $chairSuppliers,
        'club'            => $club,
        'repairs'         => $repairs,
        'parts'           => $parts,
        'seats'           => $seats,
        'cc_email'     => $ccEmail,
        'signature'    => $signature,
        'fetched_at'   => date('Y-m-d H:i:s'),
        'requester'    => $user['name'] ?? '',
    ],
]);
