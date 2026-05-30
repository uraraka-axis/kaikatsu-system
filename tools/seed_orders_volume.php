<?php declare(strict_types=1);
/**
 * 発注一覧 ボリューム検証用テストデータ生成（約3000件）
 *
 *  - 構造化セット: 2店舗(30101札幌西岡店 / 30134 BiVi仙台駅東口店, 共にフィットネス)
 *      × 全種別(repair/equipment/parts/seat-replacement)
 *      × 全ステータス(0..4) × 2 = 160件（直近日付・データ整合）
 *  - 残りを全143店舗に充填して合計約3000件（カテゴリ整合・種別妥当・日付は約14ヶ月に分散）
 *  - 種別ごとの詳細テーブル / ステータス履歴(0..現ステータス) を生成
 *  - status>=3 は予算実績(budgets.actual_amount)へ計上（納品/完了月ベース）
 *
 * 使い方: php tools/seed_orders_volume.php [合計件数]
 *   既定 3000。orders が空でない場合は警告のうえ続行（追記）。
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

date_default_timezone_set('Asia/Tokyo');
mt_srand(20260530); // 再現性のため固定シード

$TOTAL  = isset($argv[1]) ? max(160, (int)$argv[1]) : 3000;
$TODAY  = new DateTimeImmutable('2026-05-30');
$STRUCT_SHOPS = ['30101', '30134']; // フィットネス2店舗（seat-replacement可）

// ---- 事前ロード ----
$existing = (int)getOne("SELECT COUNT(*) c FROM orders")['c'];
if ($existing > 0) {
    fwrite(STDERR, "[WARN] orders には既に {$existing} 件あります（追記します）\n");
}

// 店舗→カテゴリ（単一）, 店舗→ユーザーID
$shopCat = [];
foreach (query("SELECT shop_code, category_code FROM shop_categories") as $r) {
    $shopCat[$r['shop_code']] = $r['category_code']; // 1店舗1カテゴリ前提
}
$shopUser = [];
foreach (query("SELECT shop_code, id FROM users WHERE role='shop' AND shop_code IS NOT NULL") as $r) {
    $shopUser[$r['shop_code']] = (int)$r['id'];
}
$allShops = array_map('strval', array_keys($shopCat)); // 数値文字列キーのint化対策

// カテゴリ別 商品（equipment 明細用）
$prodByCat = ['fitness' => [], 'golf' => []];
foreach (query("SELECT p.id,p.name,p.code,p.price,p.category_code,s.name AS supplier
                  FROM products p LEFT JOIN suppliers s ON p.supplier_id=s.id
                 WHERE p.is_active=1") as $r) {
    $prodByCat[$r['category_code']][] = $r;
}

$REPAIR_MACHINES = ['ランニングマシン XT-3000','チェストプレス ASERG01','ラットプルダウン LP-200',
    'レッグプレス LG-9','スミスマシン SM-5','エアロバイク AB-7','ローイングマシン RW-2'];
$PARTS_NAMES = ['丸ネジ CXADF098','駆動ベルト V-440','ローラー RB-21','ワイヤー WR-15',
    'グリップ GP-3','ベアリング BR-88','パッド PD-12'];
$ISSUES = ['電源が入らない','異音がする','ベルトが滑る','表示パネル不良','可動部が固い'];

function pad2($n){ return ($n<10?'0':'').$n; }
function dstr(DateTimeImmutable $d){ return $d->format('Y-m-d'); }
function dt(DateTimeImmutable $d, int $h=10){ return $d->format('Y-m-d') . ' ' . pad2($h) . ':00:00'; }

/** budgets.actual_amount へ計上（納品/完了月ベース） */
function addBudgetActual(string $shop, string $cat, string $dateStr, int $delta): void {
    if ($delta === 0) return;
    $d = new DateTimeImmutable($dateStr);
    $m = (int)$d->format('n'); $y = (int)$d->format('Y');
    $fy = $m >= 4 ? $y : $y - 1;
    execute("INSERT INTO budgets (shop_code,fiscal_year,month,department,budget_amount,actual_amount)
             VALUES (:s,:y,:m,:d,0,:a)
             ON DUPLICATE KEY UPDATE actual_amount = actual_amount + VALUES(actual_amount)",
        [':s'=>$shop, ':y'=>$fy, ':m'=>$m, ':d'=>$cat, ':a'=>$delta]);
}

$counts = ['repair'=>0,'equipment'=>0,'parts'=>0,'seat-replacement'=>0];
$statusCounts = [0,0,0,0,0];

/**
 * 1件の発注を生成（詳細・履歴・予算反映まで）。
 */
function createTestOrder(string $shop, string $cat, string $type, int $status, DateTimeImmutable $orderDate): void {
    global $shopUser, $prodByCat, $REPAIR_MACHINES, $PARTS_NAMES, $ISSUES, $counts, $statusCounts;

    $orderId = generateOrderNumber($type, $shop, dstr($orderDate));
    $isRepairLike = in_array($type, ['repair','seat-replacement'], true);

    // 金額算出
    $estimate = null; $final = null; $deliveryDate = null; $actualDelivery = null;
    $equipItems = [];

    if ($type === 'equipment') {
        // 1〜4商品をカート
        $pool = $prodByCat[$cat];
        $pick = max(1, min(4, (int)mt_rand(1,4)));
        $sum = 0;
        for ($i=0; $i<$pick && $pool; $i++) {
            $p = $pool[mt_rand(0, count($pool)-1)];
            $qty = mt_rand(1,3);
            $sum += (int)$p['price'] * $qty;
            $equipItems[] = [$p, $qty];
        }
        $estimate = $sum; // 備品は作成時に見積確定
    } else {
        // repair/parts/seat は status>=1 で見積確定
        if ($status >= 1) {
            $estimate = $isRepairLike ? (int)(mt_rand(100,3000)*100) : (int)(mt_rand(30,1500)*100);
        }
    }

    // 予定日・実績日
    $schedule = $orderDate->modify('+'.mt_rand(3,10).' days');
    if ($status >= 1) {
        $deliveryDate = $schedule; // 納品予定日 / 修理予定日
    }
    if ($status >= 3) {
        $actualDelivery = $schedule->modify('+'.mt_rand(0,5).' days');
    }
    if ($status >= 4) {
        // 完了: 最終金額
        $base = $estimate ?? (int)(mt_rand(100,3000)*100);
        $final = max(1, $base + (int)(mt_rand(-15,15)/100 * $base));
    }

    // orders 挿入
    $cols = ['id','type','category_code','status','shop_code','date','created_by','created_at','updated_at'];
    $vals = [':id'=>$orderId, ':type'=>$type, ':cat'=>$cat, ':st'=>$status, ':shop'=>$shop,
             ':date'=>dstr($orderDate), ':cb'=>($shopUser[$shop] ?? null),
             ':ca'=>dt($orderDate,9), ':ua'=>dt($actualDelivery ?? $orderDate, 12)];
    $setExtra = '';
    if ($estimate !== null) { $setExtra .= ', estimate_amount'; $vals[':est']=$estimate; }
    if ($final !== null)    { $setExtra .= ', final_amount';    $vals[':fin']=$final; }
    if ($deliveryDate)      { $setExtra .= ', delivery_date';   $vals[':dd']=dstr($deliveryDate); }
    if ($actualDelivery && !$isRepairLike) { $setExtra .= ', actual_delivery_date'; $vals[':add']=dstr($actualDelivery); }

    $colSql = 'id,type,category_code,status,shop_code,date,created_by,created_at,updated_at'
            . ($estimate!==null ? ',estimate_amount':'')
            . ($final!==null ? ',final_amount':'')
            . ($deliveryDate ? ',delivery_date':'')
            . ($actualDelivery && !$isRepairLike ? ',actual_delivery_date':'');
    $phSql  = ':id,:type,:cat,:st,:shop,:date,:cb,:ca,:ua'
            . ($estimate!==null ? ',:est':'')
            . ($final!==null ? ',:fin':'')
            . ($deliveryDate ? ',:dd':'')
            . ($actualDelivery && !$isRepairLike ? ',:add':'');
    execute("INSERT INTO orders ($colSql) VALUES ($phSql)", $vals);

    // 詳細テーブル
    if ($type === 'repair' || $type === 'seat-replacement') {
        $table = $type === 'repair' ? 'order_repair_details' : 'order_seat_replacement_details';
        $equipName = $type === 'repair'
            ? $REPAIR_MACHINES[mt_rand(0,count($REPAIR_MACHINES)-1)]
            : ('シート交換対象 ' . $REPAIR_MACHINES[mt_rand(0,count($REPAIR_MACHINES)-1)]);
        $issue = $type === 'repair' ? $ISSUES[mt_rand(0,count($ISSUES)-1)] : 'マシンのシート交換';
        execute("INSERT INTO {$table} (order_id,equipment_name,issue,repair_schedule_date,repair_completed_date)
                 VALUES (:o,:e,:i,:rs,:rc)",
            [':o'=>$orderId, ':e'=>$equipName, ':i'=>$issue,
             ':rs'=>($status>=1 ? dstr($schedule) : null),
             ':rc'=>($status>=3 ? dstr($actualDelivery) : null)]);
    } elseif ($type === 'equipment') {
        foreach ($equipItems as $it) {
            [$p,$qty] = $it;
            execute("INSERT INTO order_equipment_items (order_id,product_id,product_name,product_code,price,qty,supplier)
                     VALUES (:o,:pid,:pn,:pc,:pr,:q,:su)",
                [':o'=>$orderId, ':pid'=>(int)$p['id'], ':pn'=>$p['name'], ':pc'=>$p['code'],
                 ':pr'=>(int)$p['price'], ':q'=>$qty, ':su'=>$p['supplier']]);
        }
    } else { // parts
        execute("INSERT INTO order_parts_details (order_id,parts_name,target_equipment,reason,quantity)
                 VALUES (:o,:pn,:te,:rs,:q)",
            [':o'=>$orderId, ':pn'=>$PARTS_NAMES[mt_rand(0,count($PARTS_NAMES)-1)],
             ':te'=>$REPAIR_MACHINES[mt_rand(0,count($REPAIR_MACHINES)-1)],
             ':rs'=>'消耗のため交換', ':q'=>mt_rand(1,5)]);
    }

    // ステータス履歴 0..status
    $histDates = [
        0 => dt($orderDate, 9),
        1 => dt($orderDate->modify('+1 days'), 10),
        2 => dt($orderDate->modify('+2 days'), 11),
        3 => dt(($actualDelivery ?? $schedule), 14),
        4 => dt(($actualDelivery ?? $schedule)->modify('+'.mt_rand(1,5).' days'), 15),
    ];
    $actor = [0=>'店舗', 1=>'商品部 管理者', 2=>'商品部 管理者', 3=>'店舗', 4=>'商品部 管理者'];
    for ($s=0; $s<=$status; $s++) {
        execute("INSERT INTO order_status_history (order_id,status,changed_at,changed_by,memo)
                 VALUES (:o,:s,:ca,:by,'')",
            [':o'=>$orderId, ':s'=>$s, ':ca'=>$histDates[$s], ':by'=>$actor[$s]]);
    }

    // 予算反映（status>=3）。計上月: 備品/部品=actual_delivery_date, 修理/シート=repair_completed_date
    if ($status >= 3) {
        $budgetDate = dstr($actualDelivery);
        $estForBudget = (int)($estimate ?? 0);
        if ($status === 3) {
            addBudgetActual($shop, $cat, $budgetDate, $estForBudget);
        } else { // status 4: 最終金額を計上（estimate相当+差分）
            addBudgetActual($shop, $cat, $budgetDate, (int)($final ?? $estForBudget));
        }
    }

    $counts[$type]++;
    $statusCounts[$status]++;
}

// ---- 生成 ----
beginTransaction();
try {
    $generated = 0;

    // (1) 構造化セット 160件（直近50日以内・新しめ）
    $types = ['repair','equipment','parts','seat-replacement'];
    foreach ($STRUCT_SHOPS as $shop) {
        $cat = $shopCat[$shop] ?? 'fitness';
        foreach ($types as $type) {
            for ($status=0; $status<=4; $status++) {
                for ($k=0; $k<2; $k++) {
                    $od = $GLOBALS['TODAY']->modify('-'.mt_rand(1,50).' days');
                    createTestOrder($shop, $cat, $type, $status, $od);
                    $generated++;
                }
            }
        }
    }

    // (2) 充填（全店舗・約14ヶ月分散）
    while ($generated < $TOTAL) {
        $shop = (string)$allShops[mt_rand(0, count($allShops)-1)];
        $cat  = $shopCat[$shop];
        // 種別: カテゴリ整合（seat-replacement はフィットネスのみ）
        $pool = $cat === 'fitness' ? ['repair','equipment','parts','seat-replacement'] : ['repair','equipment','parts'];
        $type = $pool[mt_rand(0, count($pool)-1)];
        $status = mt_rand(0,4);
        $od = $GLOBALS['TODAY']->modify('-'.mt_rand(0,430).' days');
        createTestOrder($shop, $cat, $type, $status, $od);
        $generated++;
    }

    commit();
} catch (Throwable $e) {
    rollback();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

$total = (int)getOne("SELECT COUNT(*) c FROM orders")['c'];
echo "生成完了: {$generated} 件（orders 合計 {$total} 件）\n";
echo "種別別: " . json_encode($counts, JSON_UNESCAPED_UNICODE) . "\n";
echo "ステータス別: " . json_encode($statusCounts) . "\n";
