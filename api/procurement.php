<?php declare(strict_types=1);

/**
 * 快活システム - 自店調達申請API
 *
 * GET  /api/procurement.php?year=2025&category=&shop=
 *   - 店舗ユーザー: 自店のみ
 *   - 管理者: 全店舗 or 絞り込み
 *
 * POST /api/procurement.php
 *   - 店舗ユーザーのみ
 *   - { "category_code": "fitness", "amount": 15000, "reason": "..." }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$user = getCurrentUser();
$method = $_SERVER['REQUEST_METHOD'];

// ============================
// GET: 申請一覧
// ============================
if ($method === 'GET') {

    $fiscalYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentFiscalYear();
    $category   = $_GET['category'] ?? '';
    $shopCode   = $_GET['shop'] ?? '';

    // 店舗ユーザーは自店のみ
    if ($user['role'] !== 'admin') {
        $shopCode = $user['shop_code'];
    }

    // バリデーション
    if ($category !== '' && !in_array($category, ['fitness', 'golf'], true)) {
        jsonError('不正なカテゴリパラメータです');
    }

    // 年度期間（4月始まり）
    $startDate = $fiscalYear . '-04-01';
    $endDate   = ($fiscalYear + 1) . '-03-31';

    $sql = 'SELECT p.id, p.shop_code, p.category_code, p.amount, p.reason,
                   p.date, p.status, p.created_at,
                   s.name AS shop_name
            FROM procurement_requests p
            JOIN shops s ON p.shop_code = s.code
            WHERE p.date >= :start_date AND p.date <= :end_date';

    $params = [
        ':start_date' => $startDate,
        ':end_date'   => $endDate,
    ];

    if ($shopCode !== '') {
        $sql .= ' AND p.shop_code = :shop_code';
        $params[':shop_code'] = $shopCode;
    }
    if ($category !== '') {
        $sql .= ' AND p.category_code = :category';
        $params[':category'] = $category;
    }

    $sql .= ' ORDER BY p.date DESC, p.id DESC';

    $rows = query($sql, $params);

    // 集計
    $totalCount  = count($rows);
    $totalAmount = 0;
    $fitCount    = 0;
    $fitAmount   = 0;
    $golfCount   = 0;
    $golfAmount  = 0;

    foreach ($rows as $row) {
        $amt = (int)$row['amount'];
        $totalAmount += $amt;
        if ($row['category_code'] === 'fitness') {
            $fitCount++;
            $fitAmount += $amt;
        } else {
            $golfCount++;
            $golfAmount += $amt;
        }
    }

    jsonResponse([
        'success' => true,
        'data'    => $rows,
        'summary' => [
            'total_count'  => $totalCount,
            'total_amount' => $totalAmount,
            'fit_count'    => $fitCount,
            'fit_amount'   => $fitAmount,
            'golf_count'   => $golfCount,
            'golf_amount'  => $golfAmount,
        ],
    ]);

// ============================
// POST: 申請作成
// ============================
} elseif ($method === 'POST') {

    // 店舗ユーザーのみ
    if ($user['role'] === 'admin') {
        jsonError('管理者は自店調達申請を作成できません', 403);
    }

    $input = getJsonInput();

    $categoryCode = trim($input['category_code'] ?? '');
    $amount       = $input['amount'] ?? null;
    $reason       = trim($input['reason'] ?? '');

    // バリデーション
    if ($categoryCode === '') {
        jsonError('カテゴリを選択してください');
    }
    if (!in_array($categoryCode, ['fitness', 'golf'], true)) {
        jsonError('不正なカテゴリです');
    }
    if ($amount === null || $amount === '') {
        jsonError('金額を入力してください');
    }
    $amount = (int)$amount;
    if ($amount <= 0) {
        jsonError('金額は1円以上で入力してください');
    }
    if ($reason === '') {
        jsonError('理由を入力してください');
    }
    if (mb_strlen($reason) > 500) {
        jsonError('理由は500文字以内で入力してください');
    }

    $shopCode = $user['shop_code'];
    $today    = date('Y-m-d');

    try {
        beginTransaction();

        // 採番
        $reqId = generateOrderNumber('procurement', $shopCode, $today);

        // INSERT
        execute(
            'INSERT INTO procurement_requests (id, shop_code, category_code, amount, reason, date, status, created_by)
             VALUES (:id, :shop_code, :category_code, :amount, :reason, :date, :status, :created_by)',
            [
                ':id'            => $reqId,
                ':shop_code'     => $shopCode,
                ':category_code' => $categoryCode,
                ':amount'        => $amount,
                ':reason'        => $reason,
                ':date'          => $today,
                ':status'        => 'approved',
                ':created_by'    => $user['id'],
            ]
        );

        commit();

        // 作成したレコードを返却
        $created = getOne(
            'SELECT p.id, p.shop_code, p.category_code, p.amount, p.reason,
                    p.date, p.status, p.created_at,
                    s.name AS shop_name
             FROM procurement_requests p
             JOIN shops s ON p.shop_code = s.code
             WHERE p.id = :id',
            [':id' => $reqId]
        );

        jsonResponse([
            'success' => true,
            'data'    => $created,
        ]);

    } catch (Throwable $e) {
        rollback();
        error_log('Procurement create error: ' . $e->getMessage());
        jsonError('申請の作成に失敗しました', 500);
    }

} else {
    jsonError('許可されていないメソッドです', 405);
}
