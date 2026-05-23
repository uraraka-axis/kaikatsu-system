<?php declare(strict_types=1);

/**
 * 快活システム - ステータス変更API
 *
 * POST /api/orders/status.php
 * リクエスト:
 *   { "order_id": "REP-S01-20260301-0001", "action": "order", ... }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/budget.php';
require_once __DIR__ . '/../../includes/budget_notify.php';

requireLogin();
requireMethod('POST');

$user  = getCurrentUser();
$input = getJsonInput();

// --- バリデーション ---
$orderId = trim($input['order_id'] ?? '');
$action  = trim($input['action'] ?? '');

if ($orderId === '') {
    jsonError('発注番号が指定されていません');
}

$validActions = ['order', 'to-delivering', 'repair-done', 'delivery-done', 'complete'];
if (!in_array($action, $validActions, true)) {
    jsonError('不正なアクションです');
}

// --- 発注データ取得 ---
$order = getOne('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
if ($order === null) {
    jsonError('発注が見つかりません', 404);
}

$currentStatus = (int)$order['status'];
$orderType     = $order['type'];

// --- アクション別バリデーション＆権限チェック ---
$newStatus   = null;
$updateCols  = [];
$updateVals  = [];

switch ($action) {
    case 'order':
        // 0→1: admin only, estimate_amount required
        if ($user['role'] !== 'admin') {
            jsonError('権限がありません', 403);
        }
        if ($currentStatus !== 0) {
            jsonError('依頼中の発注のみ発注済に変更できます');
        }
        $estimateAmount = $input['estimate_amount'] ?? null;
        if ($estimateAmount === null || $estimateAmount === '') {
            jsonError('見積金額は必須です');
        }
        $estimateAmount = filter_var($estimateAmount, FILTER_VALIDATE_INT);
        if ($estimateAmount === false || $estimateAmount <= 0) {
            jsonError('見積金額は1以上の数値を入力してください');
        }
        $newStatus = 1;
        $updateCols[] = 'estimate_amount = :estimate_amount';
        $updateVals[':estimate_amount'] = $estimateAmount;

        // delivery_date (equipment/parts) or repair_schedule_date (repair)
        if ($orderType === 'repair') {
            $repairScheduleDate = $input['repair_schedule_date'] ?? null;
            if ($repairScheduleDate !== null && $repairScheduleDate !== '') {
                $updateCols[] = 'delivery_date = delivery_date'; // no-op placeholder; update repair_details
                // repair_schedule_date はorder_repair_detailsに保存
            }
        } else {
            $deliveryDate = $input['delivery_date'] ?? null;
            if ($deliveryDate !== null && $deliveryDate !== '') {
                $updateCols[] = 'delivery_date = :delivery_date';
                $updateVals[':delivery_date'] = $deliveryDate;
            }
        }
        break;

    case 'to-delivering':
        // 1→2: admin only, repair/parts only
        if ($user['role'] !== 'admin') {
            jsonError('権限がありません', 403);
        }
        if ($currentStatus !== 1) {
            jsonError('発注済の発注のみ変更できます');
        }
        if (!in_array($orderType, ['repair', 'parts'], true)) {
            jsonError('修理・部品発注のみ変更できます');
        }
        $newStatus = 2;
        break;

    case 'repair-done':
        // 2→3: store only, repair type, own shop
        if ($user['role'] !== 'shop') {
            jsonError('店舗ユーザーのみ実行できます', 403);
        }
        if ($order['shop_code'] !== $user['shop_code']) {
            jsonError('自店の発注のみ変更できます', 403);
        }
        if ($orderType !== 'repair') {
            jsonError('修理発注のみ変更できます');
        }
        if ($currentStatus !== 2) {
            jsonError('修理待ちの発注のみ変更できます');
        }
        $repairCompletedDate = $input['repair_completed_date'] ?? null;
        if ($repairCompletedDate === null || $repairCompletedDate === '') {
            jsonError('修理完了日は必須です');
        }
        $newStatus = 3;
        break;

    case 'delivery-done':
        // 2→3: store only, equipment/parts, own shop
        if ($user['role'] !== 'shop') {
            jsonError('店舗ユーザーのみ実行できます', 403);
        }
        if ($order['shop_code'] !== $user['shop_code']) {
            jsonError('自店の発注のみ変更できます', 403);
        }
        if (!in_array($orderType, ['equipment', 'parts'], true)) {
            jsonError('備品・部品発注のみ変更できます');
        }
        if ($currentStatus !== 2) {
            jsonError('配達中の発注のみ変更できます');
        }
        $newStatus = 3;
        // actual_delivery_date
        $actualDeliveryDate = $input['actual_delivery_date'] ?? null;
        if ($actualDeliveryDate !== null && $actualDeliveryDate !== '') {
            $updateCols[] = 'actual_delivery_date = :actual_delivery_date';
            $updateVals[':actual_delivery_date'] = $actualDeliveryDate;
        }
        break;

    case 'complete':
        // 3→4: admin only
        if ($user['role'] !== 'admin') {
            jsonError('権限がありません', 403);
        }
        if ($currentStatus !== 3) {
            jsonError('納品済/修理済の発注のみ完了にできます');
        }
        $newStatus = 4;

        if ($orderType === 'repair') {
            $finalAmount = $input['final_amount'] ?? null;
            if ($finalAmount === null || $finalAmount === '') {
                jsonError('修理発注の最終金額は必須です');
            }
            $finalAmount = filter_var($finalAmount, FILTER_VALIDATE_INT);
            if ($finalAmount === false || $finalAmount <= 0) {
                jsonError('最終金額は1以上の数値を入力してください');
            }
            $updateCols[] = 'final_amount = :final_amount';
            $updateVals[':final_amount'] = $finalAmount;
        } else {
            // equipment/parts: use final_amount if provided, else estimate_amount
            $finalAmount = $input['final_amount'] ?? null;
            if ($finalAmount !== null && $finalAmount !== '') {
                $finalAmount = filter_var($finalAmount, FILTER_VALIDATE_INT);
                if ($finalAmount === false || $finalAmount <= 0) {
                    jsonError('最終金額は1以上の数値を入力してください');
                }
                $updateCols[] = 'final_amount = :final_amount';
                $updateVals[':final_amount'] = $finalAmount;
            } else {
                $updateCols[] = 'final_amount = estimate_amount';
            }
        }

        // actual_delivery_date
        $actualDeliveryDate = $input['actual_delivery_date'] ?? null;
        if ($actualDeliveryDate !== null && $actualDeliveryDate !== '') {
            $updateCols[] = 'actual_delivery_date = :actual_delivery_date';
            $updateVals[':actual_delivery_date'] = $actualDeliveryDate;
        }
        break;
}

// --- トランザクション実行 ---
$memo = $input['memo'] ?? '';

try {
    beginTransaction();

    // 1. orders テーブル更新
    $updateCols[] = 'status = :new_status';
    $updateVals[':new_status'] = $newStatus;
    $updateVals[':order_id']   = $orderId;

    $updateSql = 'UPDATE orders SET ' . implode(', ', $updateCols) . ' WHERE id = :order_id';
    execute($updateSql, $updateVals);

    // 2. repair_details 更新（修理固有フィールド）
    if ($orderType === 'repair') {
        if ($action === 'order') {
            $repairScheduleDate = $input['repair_schedule_date'] ?? null;
            if ($repairScheduleDate !== null && $repairScheduleDate !== '') {
                execute(
                    'UPDATE order_repair_details SET repair_schedule_date = :rsd WHERE order_id = :oid',
                    [':rsd' => $repairScheduleDate, ':oid' => $orderId]
                );
            }
        } elseif ($action === 'repair-done') {
            execute(
                'UPDATE order_repair_details SET repair_completed_date = :rcd WHERE order_id = :oid',
                [':rcd' => $input['repair_completed_date'], ':oid' => $orderId]
            );
        }
    }

    // 3. ステータス履歴追加
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

    // 4. budgets.actual_amount 反映
    //    - 'order'    : estimate_amount を加算 (発注済になった瞬間に予算消費)
    //    - 'complete' : final_amount - estimate_amount の差分を加算 (確定時の補正)
    $budgetDelta = 0;
    if ($action === 'order') {
        $budgetDelta = (int)($updateVals[':estimate_amount'] ?? 0);
        if ($budgetDelta > 0) {
            applyBudgetActualDelta($order, $budgetDelta);
        }
    } elseif ($action === 'complete') {
        // 完了時は orders を再取得して final_amount の確定値を得る
        $afterFinal = getOne('SELECT final_amount, estimate_amount FROM orders WHERE id = :id', [':id' => $orderId]);
        $finalAmt    = (int)($afterFinal['final_amount'] ?? 0);
        $estimateAmt = (int)($afterFinal['estimate_amount'] ?? 0);
        $budgetDelta = $finalAmt - $estimateAmt;
        if ($budgetDelta !== 0) {
            applyBudgetActualDelta($order, $budgetDelta);
        }
    }

    commit();

    // 5. 四半期予算超過通知（commit 後、加算で初めて超えた場合のみ）
    if ($budgetDelta > 0) {
        notifyIfQuarterBudgetCrossed($order, $budgetDelta);
    }
} catch (Throwable $e) {
    rollback();
    error_log('Status change error: ' . $e->getMessage());
    jsonError('ステータス変更に失敗しました', 500);
}

// --- 更新後のデータ返却 ---
$updated = getOne('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);

jsonResponse([
    'success'  => true,
    'order_id' => $orderId,
    'status'   => (int)$updated['status'],
]);
