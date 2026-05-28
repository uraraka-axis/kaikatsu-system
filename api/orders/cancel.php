<?php declare(strict_types=1);

/**
 * 快活システム - 発注取消API（論理削除）
 *
 * POST /api/orders/cancel.php
 * リクエスト:
 *   { "order_id": "EQU-S01-20260528-0001", "cancel_reason": "誤発注のため" }
 *
 * 仕様:
 *   - admin/system のみ実行可
 *   - status=0 (依頼中) の発注のみ取消可（発注済以降は仕入先連絡済みのため不可）
 *   - cancel_reason は必須
 *   - 取消すると orders.cancelled_at/cancelled_by/cancel_reason が設定され、
 *     API レイヤで cancelled_at IS NULL でフィルタされ一覧/詳細から非表示になる
 *   - 物理削除はしない（履歴は DB に残す）
 *   - status=0 の取消なので予算実績への影響なし
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();
requireMethod('POST');

$user = getCurrentUser();

// admin/system のみ
if (!in_array($user['role'], ['admin', 'system'], true)) {
    jsonError('権限がありません', 403);
}

$input = getJsonInput();

$orderId = trim($input['order_id'] ?? '');
if ($orderId === '') {
    jsonError('発注番号が指定されていません');
}

$reason = trim($input['cancel_reason'] ?? '');
if ($reason === '') {
    jsonError('取消理由は必須です');
}
if (mb_strlen($reason) > 500) {
    jsonError('取消理由は500文字以内で入力してください');
}

// 発注データ取得（取消済みは対象外）
$order = getOne(
    'SELECT id, status, cancelled_at FROM orders WHERE id = :id',
    [':id' => $orderId]
);
if ($order === null) {
    jsonError('発注が見つかりません', 404);
}
if ($order['cancelled_at'] !== null) {
    jsonError('この発注は既に取消されています', 400);
}
if ((int)$order['status'] !== 0) {
    jsonError('依頼中の発注のみ取消できます', 400);
}

try {
    beginTransaction();

    execute(
        'UPDATE orders
            SET cancelled_at  = NOW(),
                cancelled_by  = :user,
                cancel_reason = :reason
          WHERE id = :id
            AND cancelled_at IS NULL
            AND status = 0',
        [
            ':user'   => $user['name'],
            ':reason' => $reason,
            ':id'     => $orderId,
        ]
    );

    // 履歴用に order_status_history へ取消イベントを残す（status は変更しない）
    execute(
        "INSERT INTO order_status_history (order_id, status, changed_by, memo)
         VALUES (:order_id, :status, :changed_by, :memo)",
        [
            ':order_id'   => $orderId,
            ':status'     => 0, // 取消時のステータスは依頼中(0) のまま記録
            ':changed_by' => $user['name'],
            ':memo'       => '【取消】' . $reason,
        ]
    );

    commit();
} catch (Throwable $e) {
    rollback();
    error_log('Order cancel error: ' . $e->getMessage());
    jsonError('発注の取消に失敗しました', 500);
}

jsonResponse([
    'success'  => true,
    'order_id' => $orderId,
]);
