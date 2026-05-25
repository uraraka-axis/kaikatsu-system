<?php declare(strict_types=1);

/**
 * 快活システム - マスタ変更履歴 取得API (system 専用)
 *
 * GET /api/system/master-change-log.php
 *   ?target_table=zones|areas|shops|...
 *   &operation=insert|update|delete
 *   &changed_by_id=<userId>
 *   &date_from=YYYY-MM-DD
 *   &date_to=YYYY-MM-DD
 *   &page=<int>  (1始まり)
 *   &limit=<int> (デフォルト50, 最大200)
 *
 * レスポンス:
 *   { success, data: [...], pagination: { total, page, limit, total_pages } }
 *
 * password 等のマスク済みフィールドは change_data 内で既に '********' に置換済み。
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSystem();
requireMethod('GET');

$targetTable = trim((string)($_GET['target_table'] ?? ''));
$operation   = trim((string)($_GET['operation'] ?? ''));
$changedById = $_GET['changed_by_id'] ?? '';
$dateFrom    = trim((string)($_GET['date_from'] ?? ''));
$dateTo      = trim((string)($_GET['date_to'] ?? ''));
$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = (int)($_GET['limit'] ?? 50);
if ($limit < 1)   $limit = 50;
if ($limit > 200) $limit = 200;

// バリデーション
$validTables = ['zones', 'areas', 'shops', 'suppliers', 'users', 'products', 'budgets', 'shop_categories', 'categories'];
if ($targetTable !== '' && !in_array($targetTable, $validTables, true)) {
    jsonError('不正なテーブル指定です');
}
if ($operation !== '' && !in_array($operation, ['insert', 'update', 'delete'], true)) {
    jsonError('不正な操作種別です');
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    jsonError('開始日の形式が不正です');
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    jsonError('終了日の形式が不正です');
}

$where  = [];
$params = [];

if ($targetTable !== '') {
    $where[] = 'l.target_table = :tt';
    $params[':tt'] = $targetTable;
}
if ($operation !== '') {
    $where[] = 'l.operation = :op';
    $params[':op'] = $operation;
}
if ($changedById !== '' && ctype_digit((string)$changedById)) {
    $where[] = 'l.changed_by_id = :cbid';
    $params[':cbid'] = (int)$changedById;
}
if ($dateFrom !== '') {
    $where[] = 'l.changed_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'l.changed_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}

$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// 総件数
$countRow = getOne(
    "SELECT COUNT(*) AS cnt FROM master_change_log l $whereSql",
    $params
);
$total = (int)($countRow['cnt'] ?? 0);

// データ取得 (LIMIT/OFFSET はバインドできないため整数で直書き)
$offset = ($page - 1) * $limit;
$sql = "SELECT l.id, l.target_table, l.operation, l.record_key,
               l.change_data, l.changed_by_id, l.changed_at,
               l.upload_filename, l.upload_batch_id,
               u.name AS changed_by_name, u.login_id AS changed_by_login_id
          FROM master_change_log l
          LEFT JOIN users u ON l.changed_by_id = u.id
          $whereSql
          ORDER BY l.changed_at DESC, l.id DESC
          LIMIT $limit OFFSET $offset";

$rows = query($sql, $params);

// change_data は JSON 文字列のまま返す（フロントでパース）
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
