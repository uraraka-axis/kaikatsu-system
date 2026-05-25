<?php declare(strict_types=1);

/**
 * 快活システム - マスタ予約変更 取得API (system 専用)
 *
 * GET /api/system/master-scheduled-changes.php
 *   ?target_table=zones|areas|shops|...
 *   &status=pending|applied|cancelled|error
 *   &operation=insert|update|delete
 *   &scheduled_from=YYYY-MM-DD
 *   &scheduled_to=YYYY-MM-DD
 *   &page=<int>
 *   &limit=<int> (デフォルト50, 最大200)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSystem();
requireMethod('GET');

$targetTable   = trim((string)($_GET['target_table'] ?? ''));
$status        = trim((string)($_GET['status'] ?? ''));
$operation     = trim((string)($_GET['operation'] ?? ''));
$scheduledFrom = trim((string)($_GET['scheduled_from'] ?? ''));
$scheduledTo   = trim((string)($_GET['scheduled_to'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = (int)($_GET['limit'] ?? 50);
if ($limit < 1)   $limit = 50;
if ($limit > 200) $limit = 200;

$validTables = ['zones', 'areas', 'shops', 'suppliers', 'users', 'products', 'budgets', 'shop_categories', 'categories'];
if ($targetTable !== '' && !in_array($targetTable, $validTables, true)) {
    jsonError('不正なテーブル指定です');
}
if ($status !== '' && !in_array($status, ['pending', 'applied', 'cancelled', 'error'], true)) {
    jsonError('不正な状態指定です');
}
if ($operation !== '' && !in_array($operation, ['insert', 'update', 'delete'], true)) {
    jsonError('不正な操作種別です');
}
if ($scheduledFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledFrom)) {
    jsonError('開始日の形式が不正です');
}
if ($scheduledTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledTo)) {
    jsonError('終了日の形式が不正です');
}

$where  = [];
$params = [];

if ($targetTable !== '') {
    $where[] = 's.target_table = :tt';
    $params[':tt'] = $targetTable;
}
if ($status !== '') {
    $where[] = 's.status = :st';
    $params[':st'] = $status;
}
if ($operation !== '') {
    $where[] = 's.operation = :op';
    $params[':op'] = $operation;
}
if ($scheduledFrom !== '') {
    $where[] = 's.scheduled_at >= :sf';
    $params[':sf'] = $scheduledFrom . ' 00:00:00';
}
if ($scheduledTo !== '') {
    $where[] = 's.scheduled_at <= :st2';
    $params[':st2'] = $scheduledTo . ' 23:59:59';
}

$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$countRow = getOne(
    "SELECT COUNT(*) AS cnt FROM master_scheduled_changes s $whereSql",
    $params
);
$total = (int)($countRow['cnt'] ?? 0);

$offset = ($page - 1) * $limit;
$sql = "SELECT s.id, s.target_table, s.operation, s.record_key,
               s.change_data, s.scheduled_at, s.applied_at, s.status, s.error_message,
               s.created_by_id, s.created_at, s.updated_at,
               u.name AS created_by_name, u.login_id AS created_by_login_id
          FROM master_scheduled_changes s
          LEFT JOIN users u ON s.created_by_id = u.id
          $whereSql
          ORDER BY s.scheduled_at DESC, s.id DESC
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
