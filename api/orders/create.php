<?php declare(strict_types=1);

/**
 * 快活システム - 発注登録API
 *
 * POST /api/orders/create.php
 * Content-Type: multipart/form-data
 *
 * 共通パラメータ:
 *   type: repair | equipment | parts | seat-replacement
 *   category: fitness | golf
 *
 * 修理(repair)追加パラメータ:
 *   equipment_name, issue, unavail_dates(JSON), unavail_days(JSON), photos[]
 *
 * シート交換(seat-replacement)追加パラメータ:
 *   equipment_name(マシン名), unavail_dates(JSON), unavail_days(JSON), photos[]
 *   ※ issue は "マシンのシート交換" 固定、category は "fitness" 固定
 *
 * 備品(equipment)追加パラメータ:
 *   items(JSON): [{product_id, qty}, ...]
 *
 * 部品(parts)追加パラメータ:
 *   parts_name, target_equipment, quantity, reason, photos[]
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/budget.php';
require_once __DIR__ . '/../../includes/budget_notify.php';

requireLogin();
requireMethod('POST');

$user = getCurrentUser();

// 店舗ユーザーのみ発注可能（admin/system/zone/area は閲覧のみ）
if ($user['role'] !== 'shop') {
    jsonError('店舗ユーザーのみ発注できます', 403);
}

$shopCode = $user['shop_code'];
$type = $_POST['type'] ?? '';
$category = $_POST['category'] ?? '';

// --- バリデーション ---
if (!in_array($type, ['repair', 'equipment', 'parts', 'seat-replacement'], true)) {
    jsonError('不正な発注種別です');
}

// シート交換はカテゴリを fitness に強制（フロントからの値を信用しない）
if ($type === 'seat-replacement') {
    $category = 'fitness';
}

// カテゴリ存在チェック
$cat = getOne('SELECT code FROM categories WHERE code = :code AND is_active = 1', [':code' => $category]);
if (!$cat) {
    jsonError('不正なカテゴリです');
}

try {
    beginTransaction();

    // 発注番号生成
    $orderId = generateOrderNumber($type, $shopCode);

    // ordersテーブルに共通レコード挿入
    execute(
        'INSERT INTO orders (id, type, category_code, status, shop_code, date, created_by)
         VALUES (:id, :type, :category, :status, :shop, :date, :created_by)',
        [
            ':id'         => $orderId,
            ':type'       => $type,
            ':category'   => $category,
            ':status'     => STATUS_REQUESTED,
            ':shop'       => $shopCode,
            ':date'       => date('Y-m-d'),
            ':created_by' => $user['id'],
        ]
    );

    // ステータス履歴に初期レコード
    execute(
        'INSERT INTO order_status_history (order_id, status, changed_by, memo)
         VALUES (:order_id, :status, :changed_by, :memo)',
        [
            ':order_id'   => $orderId,
            ':status'     => STATUS_REQUESTED,
            ':changed_by' => $user['name'], // 他の更新系と統一（ID でなく名前を記録）
            ':memo'       => '',
        ]
    );

    // --- 種別ごとの詳細テーブル ---
    switch ($type) {
        case 'repair':
            createRepairDetail($orderId);
            break;
        case 'seat-replacement':
            createSeatReplacementDetail($orderId);
            break;
        case 'equipment':
            createEquipmentItems($orderId);
            break;
        case 'parts':
            createPartsDetail($orderId);
            break;
    }

    // --- 写真アップロード（修理・部品・シート交換） ---
    if (in_array($type, ['repair', 'parts', 'seat-replacement'], true) && !empty($_FILES['photos'])) {
        uploadPhotos($orderId);
    }

    commit();

    // レスポンスを先にクライアントへ返してから商品部への発注通知メールを送る。
    // SMTP 応答時間 (本番: 1-3秒) を発注 UX に乗せないため。
    // 失敗時は error_log のみ (リトライしない設計)。
    jsonResponseAndContinue([
        'success'  => true,
        'order_id' => $orderId,
        'message'  => '発注を登録しました',
    ]);
    notifyProductDeptNewOrder($orderId, $type, $shopCode, $category, $user);

    // 備品発注のみ: 仮計上（確定実績＋未納品見込み）が四半期予算を新たに跨いだ場合、
    // ゾーン/エリアマネージャーへ【予算超過見込み】メールを送る。
    // 計上四半期は発注日（＝当日）基準。今回の発注は status=0 で既に DB に存在するため
    // getInflightPipelineTotal の集計に含まれる。
    if ($type === 'equipment') {
        $amtRow = getOne('SELECT estimate_amount FROM orders WHERE id = :id', [':id' => $orderId]);
        $orderAmount = (int)($amtRow['estimate_amount'] ?? 0);
        if ($orderAmount > 0) {
            $today = date('Y-m-d');
            $budgetKey = resolveBudgetKeyByDate($shopCode, $category, $today);
            $orderForMail = [
                'id'            => $orderId,
                'shop_code'     => $shopCode,
                'category_code' => $category,
                'date'          => $today,
            ];
            notifyIfProvisionalQuarterBudgetCrossed($orderForMail, $orderAmount, $budgetKey);
        }
    }
    exit;
} catch (Exception $e) {
    rollback();
    error_log('Order create error: ' . $e->getMessage());
    jsonError('発注の登録に失敗しました: ' . $e->getMessage(), 500);
}

// ========================================
// 修理発注の詳細登録
// ========================================
function createRepairDetail(string $orderId): void
{
    $equipmentName = trim($_POST['equipment_name'] ?? '');
    $issue = trim($_POST['issue'] ?? '');

    if ($equipmentName === '' || $issue === '') {
        throw new InvalidArgumentException('故障機材名と不具合内容は必須です');
    }

    execute(
        'INSERT INTO order_repair_details (order_id, equipment_name, issue)
         VALUES (:order_id, :equipment_name, :issue)',
        [
            ':order_id'       => $orderId,
            ':equipment_name' => $equipmentName,
            ':issue'          => $issue,
        ]
    );

    // 対応不可日時
    $unavailDates = json_decode($_POST['unavail_dates'] ?? '[]', true);
    if (is_array($unavailDates)) {
        foreach ($unavailDates as $ud) {
            if (empty($ud['date'])) continue;
            $isAllDay = !empty($ud['isAllDay']) ? 1 : 0;
            execute(
                'INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end)
                 VALUES (:order_id, :date, :is_all_day, :time_start, :time_end)',
                [
                    ':order_id'   => $orderId,
                    ':date'       => $ud['date'],
                    ':is_all_day' => $isAllDay,
                    ':time_start' => $isAllDay ? null : ($ud['timeStart'] ?? null),
                    ':time_end'   => $isAllDay ? null : ($ud['timeEnd'] ?? null),
                ]
            );
        }
    }

    // 対応不可曜日
    $unavailDays = json_decode($_POST['unavail_days'] ?? '[]', true);
    if (is_array($unavailDays)) {
        foreach ($unavailDays as $day) {
            if (empty($day)) continue;
            execute(
                'INSERT INTO order_repair_unavail_days (order_id, day_of_week)
                 VALUES (:order_id, :day)',
                [':order_id' => $orderId, ':day' => $day]
            );
        }
    }
}

// ========================================
// シート交換発注の詳細登録
// ========================================
// 修理発注と同じステータスフロー/UI を持つが、issue は固定文言。
// 対応不可日時/曜日は order_repair_unavail_* テーブルを流用（order_id 参照のため type 非依存）。
function createSeatReplacementDetail(string $orderId): void
{
    $equipmentName = trim($_POST['equipment_name'] ?? '');

    if ($equipmentName === '') {
        throw new InvalidArgumentException('マシン名・品番は必須です');
    }

    execute(
        'INSERT INTO order_seat_replacement_details (order_id, equipment_name, issue)
         VALUES (:order_id, :equipment_name, :issue)',
        [
            ':order_id'       => $orderId,
            ':equipment_name' => $equipmentName,
            ':issue'          => 'マシンのシート交換',
        ]
    );

    // 対応不可日時
    $unavailDates = json_decode($_POST['unavail_dates'] ?? '[]', true);
    if (is_array($unavailDates)) {
        foreach ($unavailDates as $ud) {
            if (empty($ud['date'])) continue;
            $isAllDay = !empty($ud['isAllDay']) ? 1 : 0;
            execute(
                'INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end)
                 VALUES (:order_id, :date, :is_all_day, :time_start, :time_end)',
                [
                    ':order_id'   => $orderId,
                    ':date'       => $ud['date'],
                    ':is_all_day' => $isAllDay,
                    ':time_start' => $isAllDay ? null : ($ud['timeStart'] ?? null),
                    ':time_end'   => $isAllDay ? null : ($ud['timeEnd'] ?? null),
                ]
            );
        }
    }

    // 対応不可曜日
    $unavailDays = json_decode($_POST['unavail_days'] ?? '[]', true);
    if (is_array($unavailDays)) {
        foreach ($unavailDays as $day) {
            if (empty($day)) continue;
            execute(
                'INSERT INTO order_repair_unavail_days (order_id, day_of_week)
                 VALUES (:order_id, :day)',
                [':order_id' => $orderId, ':day' => $day]
            );
        }
    }
}

// ========================================
// 備品発注の明細登録
// ========================================
function createEquipmentItems(string $orderId): void
{
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || empty($items)) {
        throw new InvalidArgumentException('商品が選択されていません');
    }

    $totalAmount = 0;

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);

        if ($productId <= 0 || $qty <= 0) continue;

        // 商品マスタから情報取得
        $product = getOne(
            'SELECT id, name, code, price,
                    (SELECT s.name FROM suppliers s WHERE s.id = p.supplier_id) AS supplier
             FROM products p WHERE p.id = :id AND p.is_active = 1',
            [':id' => $productId]
        );

        if (!$product) {
            throw new InvalidArgumentException("商品が見つかりません: ID={$productId}");
        }

        $subtotal = (int)$product['price'] * $qty;
        $totalAmount += $subtotal;

        execute(
            'INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier)
             VALUES (:order_id, :product_id, :name, :code, :price, :qty, :supplier)',
            [
                ':order_id'   => $orderId,
                ':product_id' => $productId,
                ':name'       => $product['name'],
                ':code'       => $product['code'],
                ':price'      => $product['price'],
                ':qty'        => $qty,
                ':supplier'   => $product['supplier'],
            ]
        );
    }

    // 見積金額を更新
    execute(
        'UPDATE orders SET estimate_amount = :amount WHERE id = :id',
        [':amount' => $totalAmount, ':id' => $orderId]
    );
}

// ========================================
// 部品発注の詳細登録
// ========================================
function createPartsDetail(string $orderId): void
{
    $partsName = trim($_POST['parts_name'] ?? '');
    $targetEquipment = trim($_POST['target_equipment'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($partsName === '' || $quantity <= 0 || $reason === '') {
        throw new InvalidArgumentException('部品名、数量、発注理由は必須です');
    }

    execute(
        'INSERT INTO order_parts_details (order_id, parts_name, target_equipment, quantity, reason)
         VALUES (:order_id, :parts_name, :target_equipment, :quantity, :reason)',
        [
            ':order_id'         => $orderId,
            ':parts_name'       => $partsName,
            ':target_equipment' => $targetEquipment,
            ':quantity'         => $quantity,
            ':reason'           => $reason,
        ]
    );
}

// ========================================
// 商品部への発注通知メール
// ========================================
/**
 * 店舗からの新規発注時に商品部へ通知メールを送る。
 * 失敗してもログに残すだけで業務処理は止めない。
 */
function notifyProductDeptNewOrder(string $orderId, string $type, string $shopCode, string $category, array $user): void
{
    // 宛先: system_settings.product_dept_email
    $row = getOne(
        "SELECT `value` FROM system_settings WHERE `key` = 'product_dept_email' AND is_active = 1"
    );
    $toEmail = $row['value'] ?? '';
    if ($toEmail === '') {
        error_log('product_dept_email is not set in system_settings');
        return;
    }

    // 店舗名・カテゴリ名を取得（メール文面の見栄え用）
    $shop = getOne('SELECT name FROM shops WHERE code = :c', [':c' => $shopCode]);
    $cat  = getOne('SELECT name FROM categories WHERE code = :c', [':c' => $category]);
    $shopName = $shop['name'] ?? $shopCode;
    $catName  = $cat['name']  ?? $category;

    $typeLabel = match ($type) {
        'repair'           => '修理発注',
        'equipment'        => '備品発注',
        'parts'            => '部品発注',
        'seat-replacement' => 'シート交換',
        default            => $type,
    };

    // 種別固有の概要
    $detail = '';
    switch ($type) {
        case 'repair':
            $r = getOne('SELECT equipment_name, issue FROM order_repair_details WHERE order_id = :id', [':id' => $orderId]);
            if ($r) {
                $detail = "故障機材: {$r['equipment_name']}\n不具合内容: {$r['issue']}";
            }
            break;
        case 'seat-replacement':
            $r = getOne('SELECT equipment_name FROM order_seat_replacement_details WHERE order_id = :id', [':id' => $orderId]);
            if ($r) {
                $detail = "マシン名・品番: {$r['equipment_name']}\n依頼内容: マシンのシート交換";
            }
            break;
        case 'equipment':
            $items = query(
                'SELECT product_name, qty, price FROM order_equipment_items WHERE order_id = :id ORDER BY id',
                [':id' => $orderId]
            );
            $lines = [];
            $total = 0;
            foreach ($items as $it) {
                $sub = (int)$it['price'] * (int)$it['qty'];
                $total += $sub;
                $lines[] = sprintf('  - %s × %d  ¥%s', $it['product_name'], $it['qty'], number_format($sub));
            }
            if (!empty($lines)) {
                $detail = "発注商品:\n" . implode("\n", $lines) . "\n合計（参考）: ¥" . number_format($total);
            }
            break;
        case 'parts':
            $p = getOne('SELECT parts_name, target_equipment, quantity, reason FROM order_parts_details WHERE order_id = :id', [':id' => $orderId]);
            if ($p) {
                $detail = "部品名: {$p['parts_name']}\n対象機材: {$p['target_equipment']}\n数量: {$p['quantity']}\n発注理由: {$p['reason']}";
            }
            break;
    }

    $body  = "{$shopName}から{$typeLabel}が登録されました。\n\n";
    $body .= "■ 発注番号: {$orderId}\n";
    $body .= "■ 店舗:     {$shopName} ({$shopCode})\n";
    $body .= "■ カテゴリ: {$catName}\n";
    $body .= "■ 種別:     {$typeLabel}\n";
    $body .= "■ 登録者:   {$user['name']}\n";
    $body .= "■ 登録日時: " . date('Y-m-d H:i:s') . "\n";
    if ($detail !== '') {
        $body .= "\n--- 詳細 ---\n{$detail}\n";
    }
    $body .= "\n発注内容の確認・処理をお願いします。\n";

    sendMail(
        [$toEmail],
        "【{$typeLabel}】{$shopName} ({$orderId})",
        $body,
        ['html' => false]
    );
}

// ========================================
// 写真アップロード処理
// ========================================
function uploadPhotos(string $orderId): void
{
    $files = $_FILES['photos'];
    if (!is_array($files['name'])) return;

    $uploadDir = UPLOAD_PATH . '/orders/' . $orderId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $count = min(count($files['name']), MAX_PHOTO_COUNT);

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;

        $mime = $files['type'][$i];
        if (!in_array($mime, ALLOWED_MIME_TYPES, true)) continue;

        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $ext = strtolower($ext);
        if (!in_array($ext, ALLOWED_EXTENSIONS, true)) continue;

        $filename = sprintf('%s_%d.%s', $orderId, $i + 1, $ext);
        $filePath = $uploadDir . '/' . $filename;
        $relativePath = 'uploads/orders/' . $orderId . '/' . $filename;

        if (move_uploaded_file($files['tmp_name'][$i], $filePath)) {
            execute(
                'INSERT INTO order_photos (order_id, file_path, original_filename, mime_type, file_size, sort_order)
                 VALUES (:order_id, :file_path, :original, :mime, :size, :sort)',
                [
                    ':order_id' => $orderId,
                    ':file_path' => $relativePath,
                    ':original'  => $files['name'][$i],
                    ':mime'      => $mime,
                    ':size'      => $files['size'][$i],
                    ':sort'      => $i,
                ]
            );
        }
    }
}
