<?php declare(strict_types=1);

/**
 * 快活システム - 自店調達申請API
 *
 * GET  /api/procurement.php?year=2025&category=&shop=
 *   - 店舗ユーザー: 自店のみ
 *   - 管理者: 全店舗 or 絞り込み
 *
 * GET  /api/procurement.php?action=years
 *   - 実際に申請データが存在する年度の降順リストを返す
 *   - 店舗ユーザー: 自店データから集計 / 管理者: 全店データから集計
 *   - データなしの場合は現在年度を返す
 *
 * POST /api/procurement.php
 *   - 店舗ユーザーのみ
 *   - { "category_code": "fitness", "amount": 15000, "reason": "..." }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/budget.php';

requireLogin();

$user = getCurrentUser();
$method = $_SERVER['REQUEST_METHOD'];

// ============================
// GET: 年度一覧（?action=years）
// ============================
if ($method === 'GET' && (($_GET['action'] ?? '') === 'years')) {

    // ロール別の閲覧スコープ
    $where = '';
    $params = [];

    if ($user['role'] === 'shop') {
        $where = ' WHERE shop_code = :sc';
        $params[':sc'] = $user['shop_code'] ?? '';
    } elseif ($user['role'] === 'zone') {
        // 自分の zone 配下のエリアに所属する店舗のみ
        $where = ' WHERE shop_code IN (
                    SELECT s.code FROM shops s
                    JOIN areas a ON s.area_code = a.code
                    WHERE a.zone_code = :zc
                  )';
        $params[':zc'] = $user['zone_code'] ?? '';
    } elseif ($user['role'] === 'area') {
        $where = ' WHERE shop_code IN (
                    SELECT code FROM shops WHERE area_code = :ac
                  )';
        $params[':ac'] = $user['area_code'] ?? '';
    }
    // admin / system は全件（追加 WHERE なし）

    // 年度 = 4月始まり: MONTH(date) >= 4 なら YEAR(date)、それ未満は YEAR(date)-1
    $sql = "SELECT DISTINCT
              CASE WHEN MONTH(date) >= 4 THEN YEAR(date) ELSE YEAR(date) - 1 END AS fy
            FROM procurement_requests"
         . $where
         . " ORDER BY fy DESC";

    $rows = query($sql, $params);
    $years = array_map(static fn($r) => (int)$r['fy'], $rows);

    // データなしの場合は現在年度のみ返す
    if (empty($years)) {
        $years = [getCurrentFiscalYear()];
    }

    jsonResponse([
        'success' => true,
        'years'   => $years,
    ]);
}

// ============================
// GET: 申請一覧
// ============================
if ($method === 'GET') {

    $fiscalYear = isset($_GET['year']) ? (int)$_GET['year'] : getCurrentFiscalYear();
    $category   = $_GET['category'] ?? '';
    $shopCode   = $_GET['shop'] ?? '';

    // ロール別の閲覧スコープ
    if ($user['role'] === 'shop') {
        $shopCode = $user['shop_code'];
    }

    // バリデーション: カテゴリはマスタ存在チェック（is_active=1）
    if ($category !== '') {
        $catRow = getOne(
            'SELECT code FROM categories WHERE code = :c AND is_active = 1',
            [':c' => $category]
        );
        if (!$catRow) {
            jsonError('不正なカテゴリパラメータです');
        }
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

    // zone / area スコープ（shop ロールは shopCode で既に縛られている）
    if ($user['role'] === 'zone' && !empty($user['zone_code'])) {
        $sql .= ' AND p.shop_code IN (
                    SELECT s2.code FROM shops s2
                    JOIN areas a2 ON s2.area_code = a2.code
                    WHERE a2.zone_code = :scope_zone
                  )';
        $params[':scope_zone'] = $user['zone_code'];
    } elseif ($user['role'] === 'area' && !empty($user['area_code'])) {
        $sql .= ' AND p.shop_code IN (
                    SELECT code FROM shops WHERE area_code = :scope_area
                  )';
        $params[':scope_area'] = $user['area_code'];
    }

    $sql .= ' ORDER BY p.date DESC, p.id DESC';

    $rows = query($sql, $params);

    // 集計（カテゴリ別。固定2分割をやめ categories マスタ駆動にして増設に耐える）
    $catRows = query('SELECT code, name FROM categories WHERE is_active = 1 ORDER BY sort_order, code');
    $byCat = [];
    foreach ($catRows as $c) {
        $byCat[$c['code']] = ['code' => $c['code'], 'name' => $c['name'], 'count' => 0, 'amount' => 0];
    }

    $totalCount  = count($rows);
    $totalAmount = 0;
    foreach ($rows as $row) {
        $amt = (int)$row['amount'];
        $totalAmount += $amt;
        $cc = (string)$row['category_code'];
        if (!isset($byCat[$cc])) {
            // マスタに無い（廃止済み等の）カテゴリも取りこぼさず表示する
            $byCat[$cc] = ['code' => $cc, 'name' => $cc, 'count' => 0, 'amount' => 0];
        }
        $byCat[$cc]['count']++;
        $byCat[$cc]['amount'] += $amt;
    }

    jsonResponse([
        'success' => true,
        'data'    => $rows,
        'summary' => [
            'total_count'  => $totalCount,
            'total_amount' => $totalAmount,
            // カテゴリ別の内訳（[{code, name, count, amount}, ...]）。カテゴリ数に応じて可変。
            'by_category'  => array_values($byCat),
        ],
    ]);

// ============================
// POST: 申請作成
// ============================
} elseif ($method === 'POST') {

    // 店舗ユーザーのみ作成可（admin/system/zone/area は閲覧のみ）
    if ($user['role'] !== 'shop') {
        jsonError('店舗ユーザーのみ自店調達申請を作成できます', 403);
    }

    $input = getJsonInput();

    $categoryCode = trim($input['category_code'] ?? '');
    $amount       = $input['amount'] ?? null;
    $reason       = trim($input['reason'] ?? '');

    // バリデーション
    if ($categoryCode === '') {
        jsonError('カテゴリを選択してください');
    }
    // カテゴリはマスタ存在チェック（is_active=1） + 自店所属チェック
    $catRow = getOne(
        'SELECT c.code
           FROM categories c
           JOIN shop_categories sc ON sc.category_code = c.code
          WHERE c.code = :c AND c.is_active = 1 AND sc.shop_code = :sc',
        [':c' => $categoryCode, ':sc' => $user['shop_code']]
    );
    if (!$catRow) {
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

        // 予算実績反映: 申請月の budgets.actual_amount に金額を加算（即時反映）
        applyBudgetActualDeltaByDate($shopCode, $categoryCode, $today, $amount);

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
