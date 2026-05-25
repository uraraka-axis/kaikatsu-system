<?php declare(strict_types=1);

/**
 * 快活システム - ログイン履歴 取得API (system 専用)
 *
 * GET /api/system/login-history.php
 *   ?login_id=<部分一致>
 *   &success=1|0
 *   &date_from=YYYY-MM-DD
 *   &date_to=YYYY-MM-DD
 *   &page=<int>
 *   &limit=<int> (デフォルト50, 最大200)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSystem();
requireMethod('GET');

$loginId  = trim((string)($_GET['login_id'] ?? ''));
$success  = $_GET['success'] ?? '';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = (int)($_GET['limit'] ?? 50);
if ($limit < 1)   $limit = 50;
if ($limit > 200) $limit = 200;

if ($success !== '' && !in_array((string)$success, ['0', '1'], true)) {
    jsonError('不正な成功/失敗フラグです');
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    jsonError('開始日の形式が不正です');
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    jsonError('終了日の形式が不正です');
}
if (mb_strlen($loginId) > 50) {
    jsonError('ログインIDが長すぎます');
}

$where  = [];
$params = [];

if ($loginId !== '') {
    $where[] = 'h.login_id LIKE :lid';
    $params[':lid'] = '%' . $loginId . '%';
}
if ($success !== '') {
    $where[] = 'h.success = :ok';
    $params[':ok'] = (int)$success;
}
if ($dateFrom !== '') {
    $where[] = 'h.attempted_at >= :df';
    $params[':df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'h.attempted_at <= :dt';
    $params[':dt'] = $dateTo . ' 23:59:59';
}

$whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$countRow = getOne(
    "SELECT COUNT(*) AS cnt FROM login_history h $whereSql",
    $params
);
$total = (int)($countRow['cnt'] ?? 0);

$offset = ($page - 1) * $limit;
$sql = "SELECT h.id, h.user_id, h.login_id, h.ip_address, h.user_agent,
               h.success, h.failure_reason, h.attempted_at,
               u.name AS user_name
          FROM login_history h
          LEFT JOIN users u ON h.user_id = u.id
          $whereSql
          ORDER BY h.attempted_at DESC, h.id DESC
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
