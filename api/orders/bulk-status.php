<?php declare(strict_types=1);

/**
 * 快活システム - 一括ステータス変更API
 *
 * POST /api/orders/bulk-status.php
 * リクエスト:
 *   { "order_ids": [...], "action": "order|to-delivering|complete", "memo": "..." }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('POST');

$user = getCurrentUser();

// --- 管理者のみ ---
if ($user['role'] !== 'admin') {
    jsonError('権限がありません', 403);
}

$input = getJsonInput();

$orderIds = $input['order_ids'] ?? [];
$action   = trim($input['action'] ?? '');
$memo     = $input['memo'] ?? '';

if (empty($orderIds) || !is_array($orderIds)) {
    jsonError('発注番号が指定されていません');
}

$validActions = ['order', 'to-delivering', 'complete'];
if (!in_array($action, $validActions, true)) {
    jsonError('不正なアクションです');
}

// --- アクションに応じた必要ステータス ---
$requiredStatus = match ($action) {
    'order'          => 0,
    'to-delivering'  => 1,
    'complete'       => 3,
};
$newStatus = match ($action) {
    'order'          => 1,
    'to-delivering'  => 2,
    'complete'       => 4,
};

// --- 対象発注を取得 ---
$placeholders = implode(',', array_map(fn($i) => ':oid' . $i, array_keys($orderIds)));
$idParams = [];
foreach ($orderIds as $i => $oid) {
    $idParams[':oid' . $i] = $oid;
}

$orders = query(
    "SELECT * FROM orders WHERE id IN ({$placeholders})",
    $idParams
);

$processed = [];
$skipped   = [];

try {
    beginTransaction();

    foreach ($orders as $order) {
        $orderId     = $order['id'];
        $orderType   = $order['type'];
        $orderStatus = (int)$order['status'];

        // ステータスが一致しない場合はスキップ
        if ($orderStatus !== $requiredStatus) {
            $skipped[] = $orderId;
            continue;
        }

        // to-delivering はrepair/partsのみ
        if ($action === 'to-delivering' && !in_array($orderType, ['repair', 'parts'], true)) {
            $skipped[] = $orderId;
            continue;
        }

        $updateCols = ['status = :new_status'];
        $updateVals = [':new_status' => $newStatus, ':order_id' => $orderId];

        if ($action === 'order') {
            // 備品の場合、明細から見積金額を自動計算
            if ($orderType === 'equipment') {
                $itemsTotal = getOne(
                    'SELECT SUM(price * qty) AS total FROM order_equipment_items WHERE order_id = :oid',
                    [':oid' => $orderId]
                );
                $estimateAmount = (int)($itemsTotal['total'] ?? 0);
                $updateCols[] = 'estimate_amount = :estimate_amount';
                $updateVals[':estimate_amount'] = $estimateAmount;
            }
        } elseif ($action === 'complete') {
            // 最終金額未設定の場合、見積金額を適用
            if ($order['final_amount'] === null) {
                if ($orderType === 'repair') {
                    // 修理は個別でfinal_amount必須なのでスキップ
                    $skipped[] = $orderId;
                    continue;
                }
                $updateCols[] = 'final_amount = estimate_amount';
            }
        }

        // orders更新
        $updateSql = 'UPDATE orders SET ' . implode(', ', $updateCols) . ' WHERE id = :order_id';
        execute($updateSql, $updateVals);

        // ステータス履歴追加
        execute(
            'INSERT INTO order_status_history (order_id, status, changed_by, memo)
             VALUES (:order_id, :status, :changed_by, :memo)',
            [
                ':order_id'   => $orderId,
                ':status'     => $newStatus,
                ':changed_by' => $user['name'],
                ':memo'       => $memo,
            ]
        );

        $processed[] = $orderId;
    }

    commit();
} catch (Throwable $e) {
    rollback();
    error_log('Bulk status change error: ' . $e->getMessage());
    jsonError('一括ステータス変更に失敗しました', 500);
}

jsonResponse([
    'success'   => true,
    'processed' => $processed,
    'skipped'   => $skipped,
]);
