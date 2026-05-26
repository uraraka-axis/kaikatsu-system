<?php declare(strict_types=1);

/**
 * 横浜店（10303 / S03）向けテストデータ生成
 *
 * 生成内容:
 *   - 修理発注 × 5 ステータス (0,1,2,3,4) = 5 件
 *   - 備品発注 × 5 ステータス = 5 件
 *   - 部品発注 × 5 ステータス = 5 件
 *   - 備品 status=0 (依頼中) で 10 明細の発注 × 1 件
 *   - 計 16 件
 *
 * 使い方:
 *   php tools/seed_yokohama_test_data.php
 *   php tools/seed_yokohama_test_data.php --reset
 *
 * 識別:
 *   status_history.memo に '[SEED_YOKOHAMA]' を付加してあるので一括削除可能。
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$SEED_TAG  = '[SEED_YOKOHAMA]';
$SHOP_CODE = '10303';
$TODAY     = new DateTime('today');

$args  = $argv ?? [];
$reset = in_array('--reset', $args, true);

// ============ Reset ============
if ($reset) {
    echo "=== [SEED_YOKOHAMA] 既存テストデータを削除 ===\n";
    $rows = query(
        "SELECT DISTINCT o.id
           FROM orders o
           JOIN order_status_history h ON h.order_id = o.id
          WHERE h.memo LIKE :tag",
        [':tag' => '%' . $SEED_TAG . '%']
    );
    if (!$rows) {
        echo "削除対象なし\n\n";
    } else {
        foreach ($rows as $r) {
            $oid = $r['id'];
            execute('DELETE FROM order_status_history    WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_equipment_items   WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_repair_details    WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_repair_unavail_dates WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_repair_unavail_days  WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM order_parts_details    WHERE order_id = :oid', [':oid' => $oid]);
            execute('DELETE FROM orders                  WHERE id = :oid',       [':oid' => $oid]);
        }
        echo count($rows) . " 件削除しました\n\n";
    }
}

// ============ マスタ取得 ============
$shopUser = getOne(
    "SELECT id, name FROM users WHERE shop_code = :sc AND role = 'shop' LIMIT 1",
    [':sc' => $SHOP_CODE]
);
if (!$shopUser) {
    echo "❌ 店舗 {$SHOP_CODE} のユーザーが見つかりません\n";
    exit(1);
}
$SHOP_USER_ID   = (int)$shopUser['id'];
$SHOP_USER_NAME = $shopUser['name'];

$products = [];
foreach (query('SELECT id, code, name, category_code, supplier_id, price FROM products WHERE is_active = 1') as $p) {
    $products[(int)$p['id']] = $p;
}
$suppliers = [];
foreach (query('SELECT id, name FROM suppliers WHERE is_active = 1') as $s) {
    $suppliers[(int)$s['id']] = $s['name'];
}

$ADMIN_NAME = '商品部';

// ============ ヘルパー ============
/**
 * 指定ステータスまでの履歴 + 関連カラムを順に積み上げる。
 * status_history は 0→1→2→...→targetStatus の順で1日刻みで日付を遡って付与する。
 *
 * @return array{order_updates: array<string,mixed>, history: array<int,array<string,mixed>>}
 */
function buildStatusJourney(int $type, int $targetStatus, DateTime $today, string $shopUserName, string $adminName, int $estimateBase): array
{
    // 起票日 = 今日から targetStatus×2 + 5 日前くらいから始める（古い注文ほど前から）
    $startOffset = -(($targetStatus + 1) * 2 + 3);
    $start = (clone $today)->modify("{$startOffset} day");

    $history = [];
    $orderUpdates = [];

    // status=0 依頼中
    $history[] = [
        'status'     => 0,
        'changed_by' => $shopUserName,
        'memo'       => '[SEED_YOKOHAMA]',
        'changed_at' => $start->format('Y-m-d') . ' 09:30:00',
    ];

    if ($targetStatus >= 1) {
        // status=1 発注済（admin）
        $d1 = (clone $start)->modify('+1 day');
        $history[] = [
            'status'     => 1,
            'changed_by' => $adminName,
            'memo'       => '[SEED_YOKOHAMA] 発注処理完了',
            'changed_at' => $d1->format('Y-m-d') . ' 10:00:00',
        ];
        // 見積金額・予定日を確定
        $orderUpdates['estimate_amount'] = $estimateBase;
        $deliveryOrSchedule = (clone $d1)->modify('+5 day')->format('Y-m-d');
        if ($type === 0) { // repair
            // repair_schedule_date は order_repair_details で別管理（呼び出し側で設定）
            $orderUpdates['__repair_schedule_date'] = $deliveryOrSchedule;
        } else {
            $orderUpdates['delivery_date'] = $deliveryOrSchedule;
        }
    }
    if ($targetStatus >= 2) {
        // status=2 配達中/修理待ち
        $d2 = (clone $start)->modify('+3 day');
        $history[] = [
            'status'     => 2,
            'changed_by' => $adminName,
            'memo'       => '[SEED_YOKOHAMA] ' . ($type === 0 ? '修理業者手配完了' : '配送中'),
            'changed_at' => $d2->format('Y-m-d') . ' 11:00:00',
        ];
    }
    if ($targetStatus >= 3) {
        // status=3 納品済/修理済
        $d3 = (clone $start)->modify('+6 day');
        $history[] = [
            'status'     => 3,
            'changed_by' => $adminName,
            'memo'       => '[SEED_YOKOHAMA] ' . ($type === 0 ? '修理完了' : '納品確認'),
            'changed_at' => $d3->format('Y-m-d') . ' 14:00:00',
        ];
        if ($type === 0) {
            $orderUpdates['__repair_completed_date'] = $d3->format('Y-m-d');
        } else {
            $orderUpdates['actual_delivery_date'] = $d3->format('Y-m-d');
        }
    }
    if ($targetStatus >= 4) {
        // status=4 完了
        $d4 = (clone $start)->modify('+7 day');
        $history[] = [
            'status'     => 4,
            'changed_by' => $adminName,
            'memo'       => '[SEED_YOKOHAMA] 最終金額確定',
            'changed_at' => $d4->format('Y-m-d') . ' 15:00:00',
        ];
        // 最終金額（見積 × 0.95 程度）
        $orderUpdates['final_amount'] = (int)round(($orderUpdates['estimate_amount'] ?? $estimateBase) * 0.95);
    }

    return ['order_updates' => $orderUpdates, 'history' => $history];
}

function insertOrder(string $orderId, string $type, string $categoryCode, int $status, string $shopCode, string $orderDate, int $createdBy, array $orderUpdates, string $createdAt): void
{
    $cols = ['id','type','category_code','status','shop_code','date','created_by','created_at'];
    $vals = [':id',':type',':cat',':status',':shop',':date',':uid',':created_at'];
    $params = [
        ':id'         => $orderId,
        ':type'       => $type,
        ':cat'        => $categoryCode,
        ':status'     => $status,
        ':shop'       => $shopCode,
        ':date'       => $orderDate,
        ':uid'        => $createdBy,
        ':created_at' => $createdAt,
    ];
    foreach (['estimate_amount','final_amount','delivery_date','actual_delivery_date'] as $k) {
        if (array_key_exists($k, $orderUpdates)) {
            $cols[] = $k;
            $vals[] = ':' . $k;
            $params[':' . $k] = $orderUpdates[$k];
        }
    }
    execute('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')', $params);
}

function insertHistory(string $orderId, array $history): void
{
    foreach ($history as $h) {
        execute(
            'INSERT INTO order_status_history (order_id, status, changed_by, memo, changed_at)
             VALUES (:oid, :st, :cb, :memo, :ca)',
            [
                ':oid'  => $orderId,
                ':st'   => $h['status'],
                ':cb'   => $h['changed_by'],
                ':memo' => $h['memo'],
                ':ca'   => $h['changed_at'],
            ]
        );
    }
}

// ============ 生成 ============
echo "=== 横浜店 (10303) テストデータ生成 ===\n";

beginTransaction();
try {
    $createdCount = 0;

    // --- 修理発注 5件 (status 0-4) ---
    $repairCases = [
        [0, 'fitness', 'ランニングマシン TM-2000', 'モニター画面が点灯しない'],
        [1, 'fitness', 'クロストレーナー CT-300',  'ペダル動作異音'],
        [2, 'golf',    'スイング診断機 GST-7 BLE', 'BLE 接続不安定'],
        [3, 'fitness', 'エアロバイク AB-300',      'ベルト摩耗'],
        [4, 'golf',    'パッティングマット PM-500','センサー反応せず'],
    ];
    foreach ($repairCases as $idx => $rc) {
        [$status, $cat, $equipName, $issue] = $rc;
        $journey = buildStatusJourney(0, $status, $TODAY, $SHOP_USER_NAME, $ADMIN_NAME, 35000 + $idx * 5000);
        $orderDate = substr($journey['history'][0]['changed_at'], 0, 10);
        $orderId = generateOrderNumber('repair', $SHOP_CODE, $orderDate);
        insertOrder($orderId, 'repair', $cat, $status, $SHOP_CODE, $orderDate, $SHOP_USER_ID, $journey['order_updates'], $journey['history'][0]['changed_at']);
        execute(
            'INSERT INTO order_repair_details (order_id, equipment_name, issue, repair_schedule_date, repair_completed_date)
             VALUES (:oid, :en, :iss, :rsd, :rcd)',
            [
                ':oid' => $orderId,
                ':en'  => $equipName,
                ':iss' => $issue,
                ':rsd' => $journey['order_updates']['__repair_schedule_date'] ?? null,
                ':rcd' => $journey['order_updates']['__repair_completed_date'] ?? null,
            ]
        );
        insertHistory($orderId, $journey['history']);
        echo "  ✓ {$orderId} 修理 status={$status}\n";
        $createdCount++;
    }

    // --- 備品発注 5件 (status 0-4, 各 1〜2 明細) ---
    $equipCases = [
        [0, 'fitness', [[1, 5], [4, 8]]],          // マット5・ヨガブロック8
        [1, 'fitness', [[15, 12]]],                // ヨガマット12
        [2, 'golf',    [[5, 10], [6, 40]]],        // ゴルフボール10・ティー40
        [3, 'fitness', [[9, 24]]],                 // 消毒スプレー24
        [4, 'fitness', [[8, 6]]],                  // タオル10枚×6
    ];
    foreach ($equipCases as $idx => $ec) {
        [$status, $cat, $items] = $ec;
        // 見積金額 = 商品単価×数量の合計
        $estimate = 0;
        foreach ($items as $it) {
            [$pid, $qty] = $it;
            $estimate += ($products[$pid]['price'] ?? 0) * $qty;
        }
        $journey = buildStatusJourney(1, $status, $TODAY, $SHOP_USER_NAME, $ADMIN_NAME, $estimate);
        $orderDate = substr($journey['history'][0]['changed_at'], 0, 10);
        $orderId = generateOrderNumber('equipment', $SHOP_CODE, $orderDate);
        insertOrder($orderId, 'equipment', $cat, $status, $SHOP_CODE, $orderDate, $SHOP_USER_ID, $journey['order_updates'], $journey['history'][0]['changed_at']);
        foreach ($items as $it) {
            [$pid, $qty] = $it;
            $p = $products[$pid] ?? null;
            if (!$p) continue;
            execute(
                'INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier)
                 VALUES (:oid, :pid, :name, :code, :price, :qty, :sup)',
                [
                    ':oid' => $orderId, ':pid' => $pid, ':name' => $p['name'], ':code' => $p['code'],
                    ':price' => (int)$p['price'], ':qty' => $qty,
                    ':sup'   => $suppliers[(int)$p['supplier_id']] ?? '',
                ]
            );
        }
        insertHistory($orderId, $journey['history']);
        echo "  ✓ {$orderId} 備品 status={$status} 明細" . count($items) . "件\n";
        $createdCount++;
    }

    // --- 部品発注 5件 (status 0-4) ---
    $partsCases = [
        [0, 'fitness', 'ベルト RB-4520',     'ランニングマシン TR-800', 'モーター駆動ベルト摩耗', 2],
        [1, 'fitness', 'スピーカーユニット', 'エアロバイク AB-300',     '音割れのため交換',        1],
        [2, 'golf',    'センサー SN-9',     'スイング診断機 GST-7',    '反応せず',                3],
        [3, 'fitness', 'グリップ G-12',     'ダンベルセット 10kg',     '滑り止め劣化',            10],
        [4, 'golf',    'マットローラー',    'パッティングマット',      '巻き取り部交換',          1],
    ];
    foreach ($partsCases as $idx => $pc) {
        [$status, $cat, $partsName, $targetEq, $reason, $qty] = $pc;
        $journey = buildStatusJourney(2, $status, $TODAY, $SHOP_USER_NAME, $ADMIN_NAME, 8000 + $idx * 2500);
        $orderDate = substr($journey['history'][0]['changed_at'], 0, 10);
        $orderId = generateOrderNumber('parts', $SHOP_CODE, $orderDate);
        insertOrder($orderId, 'parts', $cat, $status, $SHOP_CODE, $orderDate, $SHOP_USER_ID, $journey['order_updates'], $journey['history'][0]['changed_at']);
        execute(
            'INSERT INTO order_parts_details (order_id, parts_name, target_equipment, reason, quantity)
             VALUES (:oid, :pn, :te, :r, :q)',
            [
                ':oid' => $orderId, ':pn' => $partsName, ':te' => $targetEq, ':r' => $reason, ':q' => $qty,
            ]
        );
        insertHistory($orderId, $journey['history']);
        echo "  ✓ {$orderId} 部品 status={$status}\n";
        $createdCount++;
    }

    // --- 備品 依頼中 10 明細 1件 ---
    $bigItems = [
        [1, 8],  [2, 12], [3, 4], [4, 30], [8, 6],
        [9, 18], [11, 1], [12, 2], [14, 4], [15, 10],
    ];
    $bigEstimate = 0;
    foreach ($bigItems as $it) {
        $bigEstimate += ($products[$it[0]]['price'] ?? 0) * $it[1];
    }
    $journey = buildStatusJourney(1, 0, $TODAY, $SHOP_USER_NAME, $ADMIN_NAME, $bigEstimate);
    $orderDate = substr($journey['history'][0]['changed_at'], 0, 10);
    $orderId = generateOrderNumber('equipment', $SHOP_CODE, $orderDate);
    insertOrder($orderId, 'equipment', 'fitness', 0, $SHOP_CODE, $orderDate, $SHOP_USER_ID, $journey['order_updates'], $journey['history'][0]['changed_at']);
    foreach ($bigItems as $it) {
        [$pid, $qty] = $it;
        $p = $products[$pid] ?? null;
        if (!$p) continue;
        execute(
            'INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier)
             VALUES (:oid, :pid, :name, :code, :price, :qty, :sup)',
            [
                ':oid' => $orderId, ':pid' => $pid, ':name' => $p['name'], ':code' => $p['code'],
                ':price' => (int)$p['price'], ':qty' => $qty,
                ':sup'   => $suppliers[(int)$p['supplier_id']] ?? '',
            ]
        );
    }
    insertHistory($orderId, $journey['history']);
    echo "  ✓ {$orderId} 備品 status=0 明細10件（多明細サンプル）\n";
    $createdCount++;

    commit();
    echo "\n=== 完了: {$createdCount} 件のテストデータを作成 ===\n";
    echo "ヒント: 削除は  php tools/seed_yokohama_test_data.php --reset\n";

} catch (Throwable $e) {
    rollback();
    echo "\n❌ エラー: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
