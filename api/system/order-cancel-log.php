<?php declare(strict_types=1);

/**
 * 快活システム - 発注取消履歴 取得API (system 専用)
 *
 * 発注の論理削除（取消）イベントを横断的に一覧する。
 * orders.cancelled_at IS NOT NULL の行を取消日時の新しい順に返す。
 *
 * GET /api/system/order-cancel-log.php
 *   ?type=repair|equipment|parts|seat-replacement
 *   &shop_code=<店舗コード>
 *   &date_from=YYYY-MM-DD   (取消日 下限)
 *   &date_to=YYYY-MM-DD     (取消日 上限)
 *   &page=<int>  (1始まり)
 *   &limit=<int> (デフォルト50, 最大200)
 *
 * レスポンス:
 *   { success, data: [...], pagination: { total, page, limit, total_pages } }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSystem();
requireMethod('GET');

$type      = trim((string)($_GET['type'] ?? ''));
$shopCode  = trim((string)($_GET['shop_code'] ?? ''));
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = (int)($_GET['limit'] ?? 50);
if ($limit < 1)   $limit = 50;
if ($limit > 200) $limit = 200;

// バリデーション
$validTypes = ['repair', 'equipment', 'parts', 'seat-replacement'];
if ($type !== '' && !in_array($type, $validTypes, true)) {
    jsonError('不正な種別指定です');
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    jsonError('開始日の形式が不正です');
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    jsonError('終了日の形式が不正です');
}

$where  = ['o.cancelled_at IS NOT NULL'];
$params = [];

if ($type !== '') {
    $where[] = 'o.type = :type';
    $params[':type'] = $type;
}
if ($shopCode !== '') {
    $where[] = 'o.shop_code = :sc';
    $params[':sc'] = $shopCode;
}
if ($dateFrom !== '') {
    $where[] = 'o.cancelled_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'o.cancelled_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// 総件数
$countRow = getOne(
    "SELECT COUNT(*) AS cnt FROM orders o $whereSql",
    $params
);
$total = (int)($countRow['cnt'] ?? 0);

// データ取得 (LIMIT/OFFSET はバインドできないため整数で直書き)
$offset = ($page - 1) * $limit;
$sql = "SELECT o.id, o.type, o.category_code, o.shop_code, s.name AS shop_name,
               o.estimate_amount, o.final_amount, o.status,
               o.cancel_reason, o.cancelled_by, o.cancelled_at,
               o.created_by, cu.name AS created_by_name, cu.login_id AS created_by_login_id,
               o.created_at
          FROM orders o
          LEFT JOIN shops s ON s.code = o.shop_code
          LEFT JOIN users cu ON cu.id = o.created_by
          $whereSql
          ORDER BY o.cancelled_at DESC, o.id DESC
          LIMIT $limit OFFSET $offset";

$rows = query($sql, $params);

$totalPages = $total === 0 ? 1 : (int)ceil($total / $limit);

jsonResponse([
    'success'    => true,
    'data'       => $rows,
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => $totalPages,
    ],
]);
