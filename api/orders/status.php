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
if ($order['cancelled_at'] !== null) {
    jsonError('取消された発注は操作できません', 400);
}

$currentStatus = (int)$order['status'];
$orderType     = $order['type'];

// --- アクション別バリデーション＆権限チェック ---
$newStatus   = null;
$updateCols  = [];
$updateVals  = [];
$orderItems  = null; // 備品 0→1 で明細単価を編集する場合のみセット（[{id, price}]）

switch ($action) {
    case 'order':
        // 0→1: admin only, estimate_amount required
        if ($user['role'] !== 'admin') {
            jsonError('権限がありません', 403);
        }
        if ($currentStatus !== 0) {
            jsonError('依頼中の発注のみ発注済に変更できます');
        }
        $newStatus = 1;

        // 備品は明細単価を編集できる。items 指定時は estimate_amount を
        // Σ(price × qty) でトランザクション内に再計算する（合計1欄だけの編集をやめ、
        // 発注済後の明細編集と同じ粒度を発注確定時にも提供する）。
        if ($orderType === 'equipment' && isset($input['items']) && is_array($input['items'])) {
            $orderItems = [];
            foreach ($input['items'] as $item) {
                $itemId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
                $price  = filter_var($item['price'] ?? null, FILTER_VALIDATE_INT);
                if ($itemId === false || $itemId === null || $itemId <= 0) {
                    jsonError('明細IDが不正です');
                }
                if ($price === false || $price === null || $price < 0) {
                    jsonError('単価は0以上の数値を入力してください');
                }
                $orderItems[] = ['id' => $itemId, 'price' => $price];
            }
            // 実際の値はトランザクション内の再計算で上書きする（プレースホルダ）
            $updateCols[] = 'estimate_amount = :estimate_amount';
            $updateVals[':estimate_amount'] = 0;
        } else {
            $estimateAmount = $input['estimate_amount'] ?? null;
            if ($estimateAmount === null || $estimateAmount === '') {
                jsonError('見積金額は必須です');
            }
            $estimateAmount = filter_var($estimateAmount, FILTER_VALIDATE_INT);
            if ($estimateAmount === false || $estimateAmount <= 0) {
                jsonError('見積金額は1以上の数値を入力してください');
            }
            $updateCols[] = 'estimate_amount = :estimate_amount';
            $updateVals[':estimate_amount'] = $estimateAmount;
        }

        // delivery_date (equipment/parts) or repair_schedule_date (repair/seat-replacement)
        if (isRepairLikeType($orderType)) {
            $repairScheduleDate = $input['repair_schedule_date'] ?? null;
            if ($repairScheduleDate !== null && $repairScheduleDate !== '') {
                $updateCols[] = 'delivery_date = delivery_date'; // no-op placeholder; update detail table
                // repair_schedule_date は詳細テーブル (order_repair_details / order_seat_replacement_details) に保存
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
        // 1→2: admin only (全種別 — 備品も商品部の手動運用に変更)
        if ($user['role'] !== 'admin') {
            jsonError('権限がありません', 403);
        }
        if ($currentStatus !== 1) {
            jsonError('発注済の発注のみ変更できます');
        }
        $newStatus = 2;
        break;

    case 'repair-done':
        // 2→3: store only, repair-like type (repair / seat-replacement), own shop
        if ($user['role'] !== 'shop') {
            jsonError('店舗ユーザーのみ実行できます', 403);
        }
        if ($order['shop_code'] !== $user['shop_code']) {
            jsonError('自店の発注のみ変更できます', 403);
        }
        if (!isRepairLikeType($orderType)) {
            jsonError('修理・シート交換発注のみ変更できます');
        }
        if ($currentStatus !== 2) {
            jsonError('修理待ちの発注のみ変更できます');
        }
        $repairCompletedDate = $input['repair_completed_date'] ?? null;
        if ($repairCompletedDate === null || $repairCompletedDate === '') {
            jsonError(($orderType === 'seat-replacement' ? '作業' : '修理') . '完了日は必須です');
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
        // actual_delivery_date は必須（予算計上月の決定キー）
        $actualDeliveryDate = $input['actual_delivery_date'] ?? null;
        if ($actualDeliveryDate === null || $actualDeliveryDate === '') {
            jsonError('納品実績日は必須です');
        }
        $updateCols[] = 'actual_delivery_date = :actual_delivery_date';
        $updateVals[':actual_delivery_date'] = $actualDeliveryDate;
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

        // 最終金額が必須なのは「見積ベース」の種別（修理・シート交換・部品）。
        // 備品はカタログ単価ベースのため任意（未入力なら見積額を最終額に適用）。
        $finalRequired = isRepairLikeType($orderType) || $orderType === 'parts';
        if ($finalRequired) {
            $finalAmount = $input['final_amount'] ?? null;
            if ($finalAmount === null || $finalAmount === '') {
                $typeLabel = match ($orderType) {
                    'seat-replacement' => 'シート交換',
                    'parts'            => '部品',
                    default            => '修理',
                };
                jsonError($typeLabel . '発注の最終金額は必須です');
            }
            $finalAmount = filter_var($finalAmount, FILTER_VALIDATE_INT);
            if ($finalAmount === false || $finalAmount <= 0) {
                jsonError('最終金額は1以上の数値を入力してください');
            }
            $updateCols[] = 'final_amount = :final_amount';
            $updateVals[':final_amount'] = $finalAmount;
        } else {
            // equipment: use final_amount if provided, else estimate_amount
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

    // 0. 備品 0→1 の明細単価編集: 単価を更新し estimate_amount を Σ(price × qty) で再計算
    if ($action === 'order' && $orderItems !== null) {
        foreach ($orderItems as $it) {
            $owns = getOne(
                'SELECT id FROM order_equipment_items WHERE id = :iid AND order_id = :oid',
                [':iid' => $it['id'], ':oid' => $orderId]
            );
            if ($owns === null) {
                throw new InvalidArgumentException('明細ID ' . $it['id'] . ' はこの発注に属していません');
            }
            execute(
                'UPDATE order_equipment_items SET price = :p WHERE id = :iid',
                [':p' => $it['price'], ':iid' => $it['id']]
            );
        }
        $sumRow = getOne(
            'SELECT COALESCE(SUM(price * qty), 0) AS total
               FROM order_equipment_items WHERE order_id = :oid',
            [':oid' => $orderId]
        );
        $recalc = (int)$sumRow['total'];
        if ($recalc <= 0) {
            throw new InvalidArgumentException('見積金額（明細合計）は1以上である必要があります');
        }
        $updateVals[':estimate_amount'] = $recalc;
    }

    // 1. orders テーブル更新
    $updateCols[] = 'status = :new_status';
    $updateVals[':new_status'] = $newStatus;
    $updateVals[':order_id']   = $orderId;

    $updateSql = 'UPDATE orders SET ' . implode(', ', $updateCols) . ' WHERE id = :order_id';
    execute($updateSql, $updateVals);

    // 2. 詳細テーブル更新（修理ライク固有フィールド: 修理 / シート交換）
    if (isRepairLikeType($orderType)) {
        $detailTable = getRepairLikeDetailTable($orderType);
        if ($action === 'order') {
            $repairScheduleDate = $input['repair_schedule_date'] ?? null;
            if ($repairScheduleDate !== null && $repairScheduleDate !== '') {
                execute(
                    "UPDATE {$detailTable} SET repair_schedule_date = :rsd WHERE order_id = :oid",
                    [':rsd' => $repairScheduleDate, ':oid' => $orderId]
                );
            }
        } elseif ($action === 'repair-done') {
            execute(
                "UPDATE {$detailTable} SET repair_completed_date = :rcd WHERE order_id = :oid",
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

    // 4. budgets.actual_amount 反映 (納品月ベース)
    //    - 'order'                    : 加算なし（発注済段階では予算消費しない）
    //    - 'delivery-done'/'repair-done' (status=3) : estimate_amount を加算
    //    - 'complete' (status=4)      : final_amount - estimate_amount の差分を加算
    //    計上月: 備品/部品=actual_delivery_date, 修理=repair_completed_date
    $budgetDelta = 0;
    $budgetKey   = null;

    if ($action === 'delivery-done' || $action === 'repair-done') {
        $afterRow = getOne(
            'SELECT estimate_amount, actual_delivery_date FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
        $budgetDelta = (int)($afterRow['estimate_amount'] ?? 0);
        if ($budgetDelta > 0) {
            $orderForBudget = array_merge($order, [
                'actual_delivery_date' => $afterRow['actual_delivery_date'],
            ]);
            if (applyBudgetActualDeltaByDelivery($orderForBudget, $budgetDelta)) {
                $budgetKey = resolveBudgetKeyByDelivery($orderForBudget);
            } else {
                // 納品日が無いまま status=3 になるケースは通常無いが、保険として記録
                error_log(sprintf(
                    'budget: delivery date missing for order %s on %s — actual_amount not updated',
                    $orderId, $action
                ));
            }
        }
    } elseif ($action === 'complete') {
        $afterFinal = getOne(
            'SELECT final_amount, estimate_amount, actual_delivery_date FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
        $finalAmt    = (int)($afterFinal['final_amount'] ?? 0);
        $estimateAmt = (int)($afterFinal['estimate_amount'] ?? 0);
        $budgetDelta = $finalAmt - $estimateAmt;
        if ($budgetDelta !== 0) {
            $orderForBudget = array_merge($order, [
                'actual_delivery_date' => $afterFinal['actual_delivery_date'],
            ]);
            if (applyBudgetActualDeltaByDelivery($orderForBudget, $budgetDelta)) {
                $budgetKey = resolveBudgetKeyByDelivery($orderForBudget);
            } else {
                error_log(sprintf(
                    'budget: delivery date missing for order %s on complete — final/estimate diff not applied',
                    $orderId
                ));
            }
        }
    }

    commit();
} catch (Throwable $e) {
    rollback();
    error_log('Status change error: ' . $e->getMessage());
    jsonError('ステータス変更に失敗しました', 500);
}

// --- 更新後のデータ返却 ---
$updated = getOne('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);

// 予算超過のマネージャー通知は「店舗の備品発注時（status=0）の仮計上クロス」に一本化したため、
// 納品/完了時の確定メールは送らない（api/orders/create.php 参照）。
jsonResponse([
    'success'  => true,
    'order_id' => $orderId,
    'status'   => (int)$updated['status'],
]);
