<?php declare(strict_types=1);

/**
 * 快活システム - 発注対応情報編集API
 *
 * POST /api/orders/update-info.php
 * リクエスト:
 *   { "order_id": "REP-S01-20260301-0001", "estimate_amount": 35000, ... }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('POST');

$user  = getCurrentUser();
$input = getJsonInput();

$orderId = trim($input['order_id'] ?? '');
if ($orderId === '') {
    jsonError('発注番号が指定されていません');
}

// --- 発注データ取得 ---
$order = getOne('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
if ($order === null) {
    jsonError('発注が見つかりません', 404);
}

$currentStatus = (int)$order['status'];
$orderType     = $order['type'];

// --- 権限チェック ---
if ($user['role'] === 'admin') {
    // 管理者: ステータス1以上（発注済以降）で編集可
    if ($currentStatus < 1) {
        jsonError('発注済以降の発注のみ編集できます');
    }
} elseif ($user['role'] === 'shop') {
    // 店舗: 自店の修理発注、修理済(3)ステータスのみ、repair_completed_dateとmemoのみ
    if ($order['shop_code'] !== $user['shop_code']) {
        jsonError('自店の発注のみ編集できます', 403);
    }
    if ($orderType !== 'repair') {
        jsonError('修理発注のみ編集できます', 403);
    }
    if ($currentStatus !== 3) {
        jsonError('修理済ステータスの発注のみ編集できます');
    }
} else {
    jsonError('権限がありません', 403);
}

// --- 更新処理 ---
try {
    beginTransaction();

    if ($user['role'] === 'admin') {
        // 管理者: orders テーブルの各フィールドを更新
        $updateCols = [];
        $updateVals = [':order_id' => $orderId];

        if (isset($input['estimate_amount'])) {
            $updateCols[] = 'estimate_amount = :estimate_amount';
            $updateVals[':estimate_amount'] = (int)$input['estimate_amount'];
        }
        if (isset($input['delivery_date'])) {
            $updateCols[] = 'delivery_date = :delivery_date';
            $updateVals[':delivery_date'] = $input['delivery_date'] !== '' ? $input['delivery_date'] : null;
        }
        if (isset($input['final_amount'])) {
            $updateCols[] = 'final_amount = :final_amount';
            $updateVals[':final_amount'] = (int)$input['final_amount'];
        }
        if (isset($input['actual_delivery_date'])) {
            $updateCols[] = 'actual_delivery_date = :actual_delivery_date';
            $updateVals[':actual_delivery_date'] = $input['actual_delivery_date'] !== '' ? $input['actual_delivery_date'] : null;
        }

        if (!empty($updateCols)) {
            $updateSql = 'UPDATE orders SET ' . implode(', ', $updateCols) . ' WHERE id = :order_id';
            execute($updateSql, $updateVals);
        }

        // repair固有フィールド
        if ($orderType === 'repair') {
            $repairUpdate = [];
            $repairVals   = [':oid' => $orderId];

            if (isset($input['repair_schedule_date'])) {
                $repairUpdate[] = 'repair_schedule_date = :rsd';
                $repairVals[':rsd'] = $input['repair_schedule_date'] !== '' ? $input['repair_schedule_date'] : null;
            }
            if (isset($input['repair_completed_date'])) {
                $repairUpdate[] = 'repair_completed_date = :rcd';
                $repairVals[':rcd'] = $input['repair_completed_date'] !== '' ? $input['repair_completed_date'] : null;
            }

            if (!empty($repairUpdate)) {
                execute(
                    'UPDATE order_repair_details SET ' . implode(', ', $repairUpdate) . ' WHERE order_id = :oid',
                    $repairVals
                );
            }
        }

        // memo更新（order_status_historyの最新レコードを更新）
        if (isset($input['memo'])) {
            $latestHistory = getOne(
                'SELECT id FROM order_status_history WHERE order_id = :oid ORDER BY id DESC LIMIT 1',
                [':oid' => $orderId]
            );
            if ($latestHistory !== null) {
                execute(
                    'UPDATE order_status_history SET memo = :memo WHERE id = :id',
                    [':memo' => $input['memo'], ':id' => $latestHistory['id']]
                );
            }
        }
    } else {
        // 店舗ユーザー: repair_completed_date と memo のみ
        if (isset($input['repair_completed_date'])) {
            execute(
                'UPDATE order_repair_details SET repair_completed_date = :rcd WHERE order_id = :oid',
                [
                    ':rcd' => $input['repair_completed_date'] !== '' ? $input['repair_completed_date'] : null,
                    ':oid' => $orderId,
                ]
            );
        }

        if (isset($input['memo'])) {
            $latestHistory = getOne(
                'SELECT id FROM order_status_history WHERE order_id = :oid ORDER BY id DESC LIMIT 1',
                [':oid' => $orderId]
            );
            if ($latestHistory !== null) {
                execute(
                    'UPDATE order_status_history SET memo = :memo WHERE id = :id',
                    [':memo' => $input['memo'], ':id' => $latestHistory['id']]
                );
            }
        }
    }

    commit();
} catch (Throwable $e) {
    rollback();
    error_log('Update info error: ' . $e->getMessage());
    jsonError('更新に失敗しました', 500);
}

jsonResponse([
    'success'  => true,
    'order_id' => $orderId,
]);
