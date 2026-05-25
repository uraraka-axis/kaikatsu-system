<?php declare(strict_types=1);

/**
 * 発注メール下書きテストデータ生成スクリプト
 *
 * 「依頼中（status=0）」の備品発注を多数生成して、
 * 仕入先別の集計・メーラー起動・本文コピー・発注済化の動作確認に使う。
 *
 * 使い方:
 *   cd C:\xampp\htdocs\kaikatsu-system
 *   php tools/seed_draft_mail_test_data.php
 *
 *   # 既存のシードデータを消してから再生成
 *   php tools/seed_draft_mail_test_data.php --reset
 *
 * 識別:
 *   - status_history.memo = '[SEED_DRAFT_MAIL]' を付けてあるので、後で簡単に削除可能
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$seedMemo = '[SEED_DRAFT_MAIL]';

$args = $argv ?? [];
$reset = in_array('--reset', $args, true);

// ---- 既存シードデータの削除 ----
if ($reset) {
    echo "=== --reset 指定: 既存シードデータを削除します ===\n";
    $seedOrderIds = query(
        "SELECT DISTINCT o.id
         FROM orders o
         JOIN order_status_history h ON h.order_id = o.id
         WHERE h.memo = :memo",
        [':memo' => $seedMemo]
    );
    $count = count($seedOrderIds);
    if ($count === 0) {
        echo "削除対象なし\n";
    } else {
        foreach ($seedOrderIds as $row) {
            $oid = $row['id'];
            // 履歴削除 → 明細削除 → 本体削除
            execute('DELETE FROM order_status_history WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_equipment_items WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM orders WHERE id = :oid', [':oid' => $oid]);
        }
        echo "{$count} 件削除しました\n";
    }
    echo "\n";
}

// ---- マスタ読み込み ----
$shops = [];
foreach (query('SELECT code, name, short_code FROM shops WHERE is_active = 1 ORDER BY sort_order, code') as $s) {
    $shops[$s['code']] = $s;
}

$products = [];
foreach (query('SELECT id, code, name, category_code, supplier_id, price FROM products WHERE is_active = 1') as $p) {
    $products[$p['id']] = $p;
}

$suppliers = [];
foreach (query('SELECT id, name FROM suppliers WHERE is_active = 1') as $s) {
    $suppliers[$s['id']] = $s['name'];
}

// 仕入先 → 商品IDリスト
$bySupplier = [];
foreach ($products as $p) {
    $bySupplier[(int)$p['supplier_id']][] = $p;
}

$adminUserId = 1; // admin/商品部

// ---- 発注テンプレート定義 ----
// 各エントリ: [shop_code, day_offset(-N=N日前), category, [items: [product_id, qty], ...]]
$today = new DateTime('today');

$seeds = [
    // ─── 仕入先1: フィットネスジャパン（多店舗・多明細） ───
    ['10101', -1,  'fitness', [[1, 10], [15, 12]]],                 // 札幌: トレーニングマット ×10, ヨガマット ×12
    ['10301', -1,  'fitness', [[1, 8], [2, 6], [4, 20]]],           // 新宿東口: マット, ダンベル, ヨガブロック
    ['10302', -2,  'fitness', [[15, 20], [4, 15]]],                 // 池袋西口: ヨガマット, ヨガブロック
    ['10303', -2,  'fitness', [[11, 2]]],                           // 横浜: エアロバイク AB-300 ×2
    ['10201', -3,  'fitness', [[12, 4], [14, 3]]],                  // 仙台: フロアマット, ダンベルセット
    ['20101', -3,  'fitness', [[1, 15]]],                           // 梅田: トレーニングマット ×15
    ['20102', -4,  'fitness', [[2, 10], [4, 30]]],                  // 難波: ダンベル, ヨガブロック
    ['10102', -4,  'fitness', [[14, 5]]],                           // 函館: ダンベルセット 10kg ×5
    ['20201', -5,  'fitness', [[1, 6], [15, 8]]],                   // 広島: マット類
    ['10103', -5,  'fitness', [[11, 1], [12, 2]]],                  // 旭川: エアロバイク, フロアマット
    ['10202', -6,  'fitness', [[15, 10]]],                          // 盛岡: ヨガマット ×10

    // ─── 仕入先2: スポーツ用品販売（バランスボールのみ） ───
    ['10101', -1,  'fitness', [[3, 8]]],                            // 札幌: バランスボール ×8
    ['10301', -2,  'fitness', [[3, 6]]],                            // 新宿東口
    ['10302', -3,  'fitness', [[3, 4]]],                            // 池袋西口
    ['20101', -4,  'fitness', [[3, 10]]],                           // 梅田

    // ─── 仕入先3: ゴルフサプライ（ゴルフ＋フィットネス両方） ───
    ['10301', -1,  'golf',    [[5, 12], [6, 50], [7, 8]]],          // 新宿東口（ゴルフ）
    ['10302', -1,  'golf',    [[5, 10], [7, 6], [10, 5]]],          // 池袋西口（ゴルフ）
    ['10303', -2,  'golf',    [[5, 8], [6, 30]]],                   // 横浜
    ['20101', -2,  'golf',    [[5, 15], [10, 3]]],                  // 梅田
    ['20102', -3,  'golf',    [[6, 40], [7, 10]]],                  // 難波
    ['10101', -3,  'fitness', [[13, 5]]],                           // 札幌: 心拍計アームバンド（fitness）
    ['10301', -4,  'fitness', [[13, 8]]],                           // 新宿東口: 心拍計アームバンド
    ['20201', -4,  'golf',    [[5, 5], [6, 20]]],                   // 広島
    ['10302', -5,  'fitness', [[13, 4]]],                           // 池袋西口: 心拍計
    ['10303', -5,  'golf',    [[10, 4], [7, 5]]],                   // 横浜

    // ─── 仕入先4: リネンサービス（タオルのみ） ───
    ['10101', -1,  'fitness', [[8, 6]]],                            // 札幌
    ['10102', -2,  'fitness', [[8, 4]]],                            // 函館
    ['10301', -2,  'fitness', [[8, 8]]],                            // 新宿東口
    ['10302', -3,  'fitness', [[8, 6]]],                            // 池袋西口
    ['10303', -3,  'fitness', [[8, 6]]],                            // 横浜
    ['10201', -4,  'fitness', [[8, 4]]],                            // 仙台
    ['20101', -4,  'fitness', [[8, 10]]],                           // 梅田
    ['20102', -5,  'fitness', [[8, 6]]],                            // 難波
    ['20201', -5,  'fitness', [[8, 4]]],                            // 広島

    // ─── 仕入先5: 衛生用品販売（消毒スプレーのみ） ───
    ['10101', -0,  'fitness', [[9, 24]]],                           // 札幌: 消毒スプレー ×24
    ['10301', -0,  'fitness', [[9, 30]]],                           // 新宿東口
    ['10302', -1,  'fitness', [[9, 24]]],                           // 池袋西口
    ['10303', -1,  'fitness', [[9, 24]]],                           // 横浜
    ['10201', -2,  'fitness', [[9, 18]]],                           // 仙台
    ['10202', -2,  'fitness', [[9, 12]]],                           // 盛岡
    ['20101', -3,  'fitness', [[9, 30]]],                           // 梅田
    ['20102', -3,  'fitness', [[9, 24]]],                           // 難波
    ['20201', -4,  'fitness', [[9, 18]]],                           // 広島
    ['10102', -4,  'fitness', [[9, 12]]],                           // 函館
    ['10103', -5,  'fitness', [[9, 12]]],                           // 旭川
];

// ---- データ生成 ----
$createdCount = 0;
$bySupplierCount = [];

beginTransaction();

try {
    foreach ($seeds as $entry) {
        [$shopCode, $dayOffset, $category, $items] = $entry;

        if (!isset($shops[$shopCode])) {
            echo "  ⚠ skip: shop {$shopCode} not found\n";
            continue;
        }

        $date = (clone $today)->modify("{$dayOffset} day")->format('Y-m-d');

        // 発注番号生成
        $orderId = generateOrderNumber('equipment', $shopCode, $date);

        // 発注本体
        execute(
            "INSERT INTO orders
                (id, type, category_code, status, shop_code, date, created_by, created_at)
             VALUES
                (:id, 'equipment', :category, 0, :shop, :date, :uid, :created_at)",
            [
                ':id'         => $orderId,
                ':category'   => $category,
                ':shop'       => $shopCode,
                ':date'       => $date,
                ':uid'        => $adminUserId,
                ':created_at' => $date . ' 09:30:00',
            ]
        );

        // ステータス履歴
        execute(
            "INSERT INTO order_status_history
                (order_id, status, changed_by, memo, changed_at)
             VALUES
                (:order_id, 0, :changed_by, :memo, :changed_at)",
            [
                ':order_id'   => $orderId,
                ':changed_by' => '商品部',
                ':memo'       => $seedMemo,
                ':changed_at' => $date . ' 09:30:00',
            ]
        );

        // 明細
        foreach ($items as $item) {
            [$productId, $qty] = $item;
            $p = $products[$productId] ?? null;
            if ($p === null) {
                echo "  ⚠ skip: product id={$productId} not found\n";
                continue;
            }

            $supplierName = $suppliers[(int)$p['supplier_id']] ?? '';

            execute(
                "INSERT INTO order_equipment_items
                    (order_id, product_id, product_name, product_code, price, qty, supplier)
                 VALUES
                    (:order_id, :pid, :pname, :pcode, :price, :qty, :supplier)",
                [
                    ':order_id' => $orderId,
                    ':pid'      => $productId,
                    ':pname'    => $p['name'],
                    ':pcode'    => $p['code'],
                    ':price'    => (int)$p['price'],
                    ':qty'      => $qty,
                    ':supplier' => $supplierName,
                ]
            );

            $bySupplierCount[$supplierName] = ($bySupplierCount[$supplierName] ?? 0) + 1;
        }

        $createdCount++;
        echo "  ✓ {$orderId}  {$shops[$shopCode]['name']}  ({$category})  明細" . count($items) . "件\n";
    }

    commit();
} catch (Throwable $e) {
    rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 完了: {$createdCount} 件の依頼中・備品発注を作成 ===\n\n";
echo "仕入先別の明細件数:\n";
foreach ($bySupplierCount as $sup => $cnt) {
    echo "  - {$sup}: {$cnt} 明細\n";
}

echo "\nヒント: 削除したいときは  php tools/seed_draft_mail_test_data.php --reset\n";
