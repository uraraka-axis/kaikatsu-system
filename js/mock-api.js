/* ===================================================================
 * mock-api.js — GitHub Pages 用モック API シム
 *
 * 役割:
 *   ・develop の各 PHP API 呼び出し（fetch）をブラウザ側で傍受
 *   ・ハードコードされたモックデータで応答
 *   ・本番 PHP/MySQL は一切呼ばない（=master ブランチ=モック版）
 *
 * 使い方:
 *   各 HTML の <head> 内で、他の JS よりも先に読み込むこと。
 *     <script src="js/mock-api.js"></script>
 *
 * デモ用ログイン ID（パスワードは任意）:
 *   ・10101 / 10102 / ... = 店舗ユーザー (shop)  ※5桁の店舗コード
 *   ・admin               = 商品部 (admin)
 *   ・system              = システム管理者 (system)
 *   ・Z100 / Z200         = ゾーンマネージャー (zone)
 *   ・A101 / A102 / ...   = エリアマネージャー (area)
 * =================================================================== */
(function() {
  'use strict';

  // ====== マスタデータ ======
  var ZONES = [
    { zone_code: '100', zone_name: '東日本' },
    { zone_code: '200', zone_name: '西日本' }
  ];

  var AREAS = [
    { area_code: '101', area_name: '北海道',     zone_code: '100' },
    { area_code: '102', area_name: '東北',       zone_code: '100' },
    { area_code: '103', area_name: '関東',       zone_code: '100' },
    { area_code: '104', area_name: '甲信越',     zone_code: '100' },
    { area_code: '201', area_name: '関西',       zone_code: '200' },
    { area_code: '202', area_name: '中国・四国', zone_code: '200' },
    { area_code: '203', area_name: '九州',       zone_code: '200' }
  ];

  // 30 店舗
  var SHOPS = [
    { shop_code: '10101', shop_name: '札幌',       area_code: '101' },
    { shop_code: '10102', shop_name: '函館',       area_code: '101' },
    { shop_code: '10103', shop_name: '旭川',       area_code: '101' },
    { shop_code: '10201', shop_name: '仙台',       area_code: '102' },
    { shop_code: '10202', shop_name: '盛岡',       area_code: '102' },
    { shop_code: '10203', shop_name: '郡山',       area_code: '102' },
    { shop_code: '10301', shop_name: '新宿東口',   area_code: '103' },
    { shop_code: '10302', shop_name: '池袋西口',   area_code: '103' },
    { shop_code: '10303', shop_name: '横浜',       area_code: '103' },
    { shop_code: '10304', shop_name: '渋谷',       area_code: '103' },
    { shop_code: '10305', shop_name: '上野',       area_code: '103' },
    { shop_code: '10306', shop_name: '秋葉原',     area_code: '103' },
    { shop_code: '10307', shop_name: '錦糸町',     area_code: '103' },
    { shop_code: '10308', shop_name: '大宮',       area_code: '103' },
    { shop_code: '10401', shop_name: '新潟',       area_code: '104' },
    { shop_code: '10402', shop_name: '長野',       area_code: '104' },
    { shop_code: '10403', shop_name: '甲府',       area_code: '104' },
    { shop_code: '20101', shop_name: '梅田',       area_code: '201' },
    { shop_code: '20102', shop_name: '難波',       area_code: '201' },
    { shop_code: '20103', shop_name: '京都',       area_code: '201' },
    { shop_code: '20104', shop_name: '神戸三宮',   area_code: '201' },
    { shop_code: '20105', shop_name: '天王寺',     area_code: '201' },
    { shop_code: '20201', shop_name: '広島',       area_code: '202' },
    { shop_code: '20202', shop_name: '岡山',       area_code: '202' },
    { shop_code: '20203', shop_name: '高松',       area_code: '202' },
    { shop_code: '20204', shop_name: '松山',       area_code: '202' },
    { shop_code: '20301', shop_name: '博多',       area_code: '203' },
    { shop_code: '20302', shop_name: '小倉',       area_code: '203' },
    { shop_code: '20303', shop_name: '熊本',       area_code: '203' },
    { shop_code: '20304', shop_name: '鹿児島',     area_code: '203' }
  ];

  // develop の seed.sql / categories マイグレーションに合わせて 2 カテゴリ
  var CATEGORIES = [
    { code: 'fitness', name: 'フィットネス',   closing_type: 'monthly', closing_day: 8 },
    { code: 'golf',    name: 'インドアゴルフ', closing_type: 'weekly',  closing_day: 2 }
  ];

  // 全店舗は 2 カテゴリ両方を取り扱う（seed.sql の shop_categories 相当）
  function categoriesForShop(/*shopCode*/) { return CATEGORIES.slice(); }

  // ====== ユーザーマスタ（develop seed の名前と一致させる） ======
  var USERS = [
    { login_id: 'admin',  name: '商品部',           role: 'admin',  shop_code: null,    zone_code: null,  area_code: null  },
    { login_id: 'system', name: 'システム管理者',   role: 'system', shop_code: null,    zone_code: null,  area_code: null  },
    { login_id: 'Z100',   name: '東日本ゾーンマネージャー', role: 'zone', shop_code: null, zone_code: '100', area_code: null },
    { login_id: 'Z200',   name: '西日本ゾーンマネージャー', role: 'zone', shop_code: null, zone_code: '200', area_code: null },
    { login_id: 'A101',   name: '北海道エリアマネージャー', role: 'area', shop_code: null, zone_code: null, area_code: '101' },
    { login_id: 'A102',   name: '東北エリアマネージャー',   role: 'area', shop_code: null, zone_code: null, area_code: '102' },
    { login_id: 'A103',   name: '関東エリアマネージャー',   role: 'area', shop_code: null, zone_code: null, area_code: '103' },
    { login_id: 'A201',   name: '関西エリアマネージャー',   role: 'area', shop_code: null, zone_code: null, area_code: '201' },
    { login_id: 'A202',   name: '中四国エリアマネージャー', role: 'area', shop_code: null, zone_code: null, area_code: '202' }
  ];
  // 全店舗ユーザーを自動生成
  SHOPS.forEach(function(s) {
    USERS.push({
      login_id: s.shop_code,
      name:     s.shop_name + '店',
      role:     'shop',
      shop_code: s.shop_code,
      zone_code: null,
      area_code: null
    });
  });

  function findUserByLoginId(id) {
    for (var i = 0; i < USERS.length; i++) {
      if (USERS[i].login_id === id) return USERS[i];
    }
    return null;
  }

  // ====== ログインセッション（localStorage） ======
  var SESSION_KEY = 'mockSessionUser';
  function getSessionUser() {
    try {
      var raw = localStorage.getItem(SESSION_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }
  function setSessionUser(u) {
    try { localStorage.setItem(SESSION_KEY, JSON.stringify(u)); } catch (e) {}
  }
  function clearSessionUser() {
    try { localStorage.removeItem(SESSION_KEY); } catch (e) {}
  }

  // ====== currentUser（API 応答用に整形） ======
  function buildMeResponse() {
    var u = getSessionUser();
    if (!u) return null;
    var shopName = null;
    if (u.shop_code) {
      var s = SHOPS.find(function(x) { return x.shop_code === u.shop_code; });
      shopName = s ? s.shop_name : null;
    }
    var zoneName = null;
    if (u.zone_code) {
      var z = ZONES.find(function(x) { return x.zone_code === u.zone_code; });
      zoneName = z ? z.zone_name : null;
    }
    var areaName = null;
    if (u.area_code) {
      var a = AREAS.find(function(x) { return x.area_code === u.area_code; });
      areaName = a ? a.area_name : null;
    }
    // 店舗ユーザーは取扱カテゴリを付与（develop の auth.php 相当）
    var userCats = [];
    if (u.role === 'shop' && u.shop_code) {
      userCats = categoriesForShop(u.shop_code).map(function(c) {
        return { code: c.code, name: c.name };
      });
    }
    return {
      id: u.login_id,
      login_id: u.login_id,
      name: u.name,
      role: u.role,
      shop_code: u.shop_code,
      shop_name: shopName,
      zone_code: u.zone_code,
      zone_name: zoneName,
      area_code: u.area_code,
      area_name: areaName,
      is_active: 1,
      categories: userCats
    };
  }

  // ====== サンプル発注データ生成 ======
  // develop の api/orders.php と同じ shape を返す
  function makeStatusHistory(stages, shopName) {
    // stages: [{status, date, by, memo}, ...]
    return stages.map(function(s) {
      return {
        status:     s.status,
        changed_at: s.date,
        changed_by: s.by || shopName + '店',
        memo:       s.memo || ''
      };
    });
  }
  // 備品発注 × 依頼中（status 0）用のパターン：店舗 idx で振り分け
  // 各パターンに category_code, 商品構成, タイトルラベルを定義
  var EQUIPMENT_REQUESTING_PATTERNS = [
    {
      category_code: 'fitness',
      equip_items: [
        { product_name: 'トレーニングマット', product_code: 'FIT-00001', price: 3500, qty: 10, supplier: 'フィットネスジャパン', arrival_date: '' }
      ],
      content_label: 'トレーニングマット × 10'
    },
    {
      category_code: 'fitness',
      equip_items: [
        { product_name: 'タオル（大）10枚セット', product_code: 'FIT-00005', price: 5600, qty: 4, supplier: 'リネンサービス',     arrival_date: '' },
        { product_name: '消毒スプレー 500ml',     product_code: 'FIT-00006', price: 980,  qty: 12, supplier: '衛生用品販売',     arrival_date: '' }
      ],
      content_label: 'タオル（大）10枚セット 他1商品'
    },
    {
      category_code: 'golf',
      equip_items: [
        { product_name: 'ゴルフボール 1ダース', product_code: 'GLF-00001', price: 4200, qty: 6, supplier: 'ゴルフサプライ', arrival_date: '' }
      ],
      content_label: 'ゴルフボール 1ダース × 6'
    },
    {
      category_code: 'golf',
      equip_items: [
        { product_name: 'ゴルフティー 100本入り', product_code: 'GLF-00002', price: 800,  qty: 20, supplier: 'ゴルフサプライ', arrival_date: '' },
        { product_name: 'グローブ Lサイズ',       product_code: 'GLF-00003', price: 1500, qty: 8,  supplier: 'ゴルフサプライ', arrival_date: '' },
        { product_name: 'スコアカード 100枚',     product_code: 'GLF-00004', price: 1200, qty: 3,  supplier: 'ゴルフサプライ', arrival_date: '' }
      ],
      content_label: 'ゴルフティー 100本入り 他2商品'
    },
    {
      category_code: 'fitness',
      equip_items: [
        { product_name: 'バランスボール 65cm', product_code: 'FIT-00003', price: 1800, qty: 5, supplier: 'フィットネスジャパン', arrival_date: '' },
        { product_name: 'ヨガブロック',         product_code: 'FIT-00004', price: 1200, qty: 8, supplier: 'フィットネスジャパン', arrival_date: '' }
      ],
      content_label: 'バランスボール 65cm 他1商品'
    }
  ];

  function buildSampleOrders() {
    var orders = [];
    var alt = ['fitness', 'golf'];
    SHOPS.forEach(function(shop, idx) {
      var sName = shop.shop_name;
      var ymd = String(idx % 28 + 1).padStart(2, '0');

      // ① 修理 × 依頼中
      orders.push({
        id: 'REP-' + shop.shop_code + '-202603' + ymd + '-0001',
        type: 'repair',
        category_code: alt[idx % 2],
        status: 0,
        shop_code: shop.shop_code,
        shop_name: sName,
        date: '2026-03-' + ymd,
        estimate_amount: null,
        final_amount: null,
        delivery_date: null,
        actual_delivery_date: null,
        equipment_name: idx % 2 === 0 ? 'ランニングマシン TR-800' : 'ゴルフシミュレーター GS-Pro',
        issue: idx % 2 === 0 ? 'ベルトが滑る。異音が発生。' : 'プロジェクターの映像がちらつく',
        repair_schedule_date: null,
        repair_completed_date: null,
        unavail_dates: [
          { date: '2026-03-10', is_all_day: 1, time_start: null, time_end: null }
        ],
        unavail_days: [ { day_of_week: 'tue' } ],
        photos: [],
        content_label: idx % 2 === 0 ? 'ランニングマシン TR-800' : 'ゴルフシミュレーター GS-Pro',
        status_history: makeStatusHistory([
          { status: 0, date: '2026/03/' + ymd + ' 09:15' }
        ], sName)
      });

      // ② 備品 × 依頼中（パターンを店舗ごとに振り分け）
      var pat = EQUIPMENT_REQUESTING_PATTERNS[idx % EQUIPMENT_REQUESTING_PATTERNS.length];
      orders.push({
        id: 'EQU-' + shop.shop_code + '-202603' + ymd + '-0002',
        type: 'equipment',
        category_code: pat.category_code,
        status: 0,
        shop_code: shop.shop_code,
        shop_name: sName,
        date: '2026-03-' + ymd,
        estimate_amount: null,
        final_amount: null,
        delivery_date: null,
        actual_delivery_date: null,
        equip_items: pat.equip_items.map(function(it) { return Object.assign({}, it); }),
        content_label: pat.content_label,
        status_history: makeStatusHistory([
          { status: 0, date: '2026/03/' + ymd + ' 11:00' }
        ], sName)
      });

      // ③ 備品 × 発注済
      orders.push({
        id: 'EQU-' + shop.shop_code + '-20260226-0001',
        type: 'equipment',
        category_code: 'fitness',
        status: 1,
        shop_code: shop.shop_code,
        shop_name: sName,
        date: '2026-02-26',
        estimate_amount: null,
        final_amount: null,
        delivery_date: '2026-03-05',
        actual_delivery_date: null,
        equip_items: [
          { product_name: 'トレーニングマット',   product_code: 'FIT-00001', price: 3500, qty: 5,  supplier: 'フィットネスジャパン', arrival_date: '2026-03-05' },
          { product_name: 'ダンベルセット 10kg',  product_code: 'FIT-00010',  price: 8400, qty: 4,  supplier: 'フィットネスジャパン', arrival_date: '2026-03-05' }
        ],
        content_label: 'トレーニングマット 他1商品',
        status_history: makeStatusHistory([
          { status: 0, date: '2026/02/26 10:00' },
          { status: 1, date: '2026/02/27 14:00', by: '商品部', memo: '発注済み' }
        ], sName)
      });

      // ④ 部品 × 配達中
      orders.push({
        id: 'PRT-' + shop.shop_code + '-20260224-0001',
        type: 'parts',
        category_code: 'golf',
        status: 2,
        shop_code: shop.shop_code,
        shop_name: sName,
        date: '2026-02-24',
        estimate_amount: null,
        final_amount: null,
        delivery_date: '2026-03-03',
        actual_delivery_date: null,
        parts_name: 'シャフト ASSY',
        target_equipment: 'ゴルフシミュレーター',
        reason: '経年劣化',
        quantity: 1,
        content_label: 'シャフト ASSY × 1',
        status_history: makeStatusHistory([
          { status: 0, date: '2026/02/24 11:00' },
          { status: 1, date: '2026/02/25 13:00', by: '商品部', memo: '発注済み' },
          { status: 2, date: '2026/02/26 16:00', by: '商品部', memo: '配送手配済み' }
        ], sName)
      });

      // ⑤ 備品 × 完了
      orders.push({
        id: 'EQU-' + shop.shop_code + '-20260210-0002',
        type: 'equipment',
        category_code: 'fitness',
        status: 4,
        shop_code: shop.shop_code,
        shop_name: sName,
        date: '2026-02-10',
        estimate_amount: null,
        final_amount: 22400,
        delivery_date: '2026-02-18',
        actual_delivery_date: '2026-02-18',
        equip_items: [
          { product_name: 'バランスボール 65cm', product_code: 'FIT-00003', price: 1800, qty: 8, supplier: 'フィットネスジャパン', arrival_date: '2026-02-18' }
        ],
        content_label: 'バランスボール 65cm × 8',
        status_history: makeStatusHistory([
          { status: 0, date: '2026/02/10 09:00' },
          { status: 1, date: '2026/02/12 10:00', by: '商品部', memo: '発注済み' },
          { status: 2, date: '2026/02/15 11:00', by: '商品部', memo: '配送手配' },
          { status: 3, date: '2026/02/18 14:00', memo: '納品確認' },
          { status: 4, date: '2026/02/19 10:00', by: '商品部', memo: '最終金額確定' }
        ], sName)
      });
    });
    return orders;
  }
  var ALL_ORDERS = buildSampleOrders();

  // ====== サンプル予算データ ======
  // 各店舗 × 各カテゴリ × 12 ヶ月
  var FISCAL_MONTHS = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
  function buildBudgetForShop(shop, dept) {
    // 部門別に基準金額を変える
    var base = dept === 'all' ? 200000 :
               dept === 'fitness' ? 80000 :
               dept === 'golf' ? 60000 :
               dept === 'darts' ? 25000 :
               dept === 'billiard' ? 20000 :
               dept === 'common' ? 15000 : 30000;
    var monthly = [];
    FISCAL_MONTHS.forEach(function(m, i) {
      var budget = base + (i % 3) * 10000;
      var actual = (i < 6) ? Math.round(budget * (0.6 + (i % 5) * 0.1)) : 0;
      monthly.push({ month: m, budget: budget, actual: actual });
    });
    return monthly;
  }

  // ====== procurement (自店調達) 履歴 ======
  function buildProcurementHistory() {
    var rows = [];
    SHOPS.slice(0, 8).forEach(function(shop, idx) {
      rows.push({
        id: 1000 + idx,
        report_date: '2026-02-' + String(20 - idx).padStart(2, '0'),
        shop_code: shop.shop_code,
        shop_name: shop.shop_name,
        zone_code: zoneCodeForShop(shop),
        area_code: shop.area_code,
        category: 'fitness',
        item_name: '消耗品（タオル等）',
        amount: 2400 + idx * 100,
        reason: '近隣店舗から急遽借用、清算購入',
        purchaser: shop.shop_name + '店長',
        created_at: '2026-02-' + String(20 - idx).padStart(2, '0') + ' 17:00:00'
      });
    });
    return rows;
  }
  function zoneCodeForShop(shop) {
    var a = AREAS.find(function(x) { return x.area_code === shop.area_code; });
    return a ? a.zone_code : null;
  }
  var PROCUREMENT = buildProcurementHistory();

  // ====== products ======
  var PRODUCTS = [
    { code: 'FIT-00001', name: 'トレーニングマット', price: 3500, supplier: 'フィットネスジャパン', category: 'fitness', stock: 30, image_path: null },
    { code: 'FIT-00010',  name: 'ダンベル 10kg',      price: 2800, supplier: 'フィットネスジャパン', category: 'fitness', stock: 50, image_path: null },
    { code: 'FIT-00012',  name: 'ランニングマシン TR-800', price: 420000, supplier: 'フィットネスジャパン', category: 'fitness', stock: 5, image_path: null },
    { code: 'GLF-00001',  name: 'ゴルフボール 1ダース', price: 4200, supplier: 'ゴルフサプライ', category: 'golf', stock: 100, image_path: null },
    { code: 'GLF-00005',  name: 'ゴルフクラブ アイアン7番', price: 28000, supplier: 'ゴルフサプライ', category: 'golf', stock: 12, image_path: null },
    { code: 'DRT-00001',  name: 'ダーツボード', price: 14000, supplier: 'ダーツライブ', category: 'darts', stock: 8, image_path: null },
    { code: 'BIL-00001',  name: 'ビリヤードキュー', price: 22000, supplier: 'ビリヤードプロ', category: 'billiard', stock: 6, image_path: null },
    { code: 'CMN-00001',  name: '洗剤 業務用 5L',     price: 3200, supplier: 'クリーンサプライ', category: 'common', stock: 40, image_path: null }
  ];

  // ====== システム設定 ======
  var SYSTEM_SETTINGS = {
    equipment_deadline_weekday: 3,
    parts_deadline_weekday:     5,
    fiscal_year_start_month:    4,
    sender_email:               'system@example.test',
    notify_emails:              'admin@example.test'
  };

  // ====== マスタ変更ログ・ログインログ ======
  var MASTER_CHANGE_LOG = [
    { id: 1, target: 'users',    action: 'INSERT', target_id: '20104', summary: '神戸三宮ユーザー追加',     operator: 'admin', changed_at: '2026-03-15 10:00:00' },
    { id: 2, target: 'budgets',  action: 'UPDATE', target_id: 'FY2026', summary: 'フィットネス予算 +5%',     operator: 'admin', changed_at: '2026-03-10 14:30:00' },
    { id: 3, target: 'products', action: 'INSERT', target_id: 'DRT-00001', summary: 'ダーツボード新製品登録',   operator: 'admin', changed_at: '2026-02-28 11:20:00' }
  ];
  var LOGIN_HISTORY = [
    { id: 1, login_id: 'admin',  name: '商品部 管理者', role: 'admin', result: 'success', ip: '192.0.2.10', logged_at: '2026-05-27 09:00:00' },
    { id: 2, login_id: '10301',  name: '新宿東口店',    role: 'shop',  result: 'success', ip: '192.0.2.11', logged_at: '2026-05-27 09:15:00' },
    { id: 3, login_id: 'Z100',   name: '東日本ゾーンマネージャー', role: 'zone', result: 'success', ip: '192.0.2.12', logged_at: '2026-05-27 09:30:00' }
  ];

  // ====== 共通レスポンス ======
  function jsonResponse(payload, status) {
    status = status || 200;
    return new Response(JSON.stringify(payload), {
      status: status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
  }
  function ok(data) { return jsonResponse({ success: true, data: data }); }
  function err(msg, status) { return jsonResponse({ success: false, error: msg }, status || 400); }

  // ====== ルーティング ======
  function parseQuery(qs) {
    var out = {};
    if (!qs) return out;
    qs.replace(/^\?/, '').split('&').forEach(function(kv) {
      if (!kv) return;
      var i = kv.indexOf('=');
      var k = i < 0 ? kv : kv.substring(0, i);
      var v = i < 0 ? '' : decodeURIComponent(kv.substring(i + 1).replace(/\+/g, '%20'));
      out[k] = v;
    });
    return out;
  }

  function route(method, path, query, body) {
    // ----- 認証 -----
    if (path === 'api/login.php' && method === 'POST') {
      var loginId = body && body.login_id ? String(body.login_id) : '';
      var u = findUserByLoginId(loginId);
      if (!u) {
        return err('ログイン ID またはパスワードが違います', 401);
      }
      // パスワードはモックなので何でも OK
      setSessionUser(u);
      return jsonResponse({ success: true, user: buildMeResponse() });
    }
    if (path === 'api/logout.php') {
      clearSessionUser();
      return ok({});
    }
    if (path === 'api/me.php') {
      var me = buildMeResponse();
      if (!me) return jsonResponse({ success: false, error: 'ログインが必要です' }, 401);
      return jsonResponse({ success: true, user: me });
    }

    // ----- マスタ -----
    if (path === 'api/master/zones.php')      return ok(ZONES);
    if (path === 'api/master/areas.php')      return ok(AREAS);
    if (path === 'api/master/shops.php') {
      var me1 = buildMeResponse();
      var list = SHOPS.slice();
      if (me1) {
        if (me1.role === 'zone' && me1.zone_code) {
          var areaCodes = AREAS.filter(function(a) { return a.zone_code === me1.zone_code; }).map(function(a) { return a.area_code; });
          list = list.filter(function(s) { return areaCodes.indexOf(s.area_code) !== -1; });
        } else if (me1.role === 'area' && me1.area_code) {
          list = list.filter(function(s) { return s.area_code === me1.area_code; });
        }
      }
      // shop_code とともに area の zone も埋め込む（develop の SQL JOIN 相当）
      list = list.map(function(s) {
        var a = AREAS.find(function(x) { return x.area_code === s.area_code; });
        return {
          shop_code: s.shop_code,
          shop_name: s.shop_name,
          area_code: s.area_code,
          area_name: a ? a.area_name : '',
          zone_code: a ? a.zone_code : ''
        };
      });
      return ok(list);
    }
    if (path === 'api/master/categories.php') {
      var me2 = buildMeResponse();
      var cats = CATEGORIES.slice();
      if (me2 && me2.role === 'shop') {
        cats = categoriesForShop(me2.shop_code);
      }
      return ok(cats);
    }

    // ----- 発注 -----
    if (path === 'api/orders.php') {
      var me3 = buildMeResponse();
      var list3 = ALL_ORDERS.slice();
      if (me3) {
        if (me3.role === 'shop') {
          list3 = list3.filter(function(o) { return o.shop_code === me3.shop_code; });
        } else if (me3.role === 'zone' && me3.zone_code) {
          var areaCodes3 = AREAS.filter(function(a) { return a.zone_code === me3.zone_code; }).map(function(a) { return a.area_code; });
          var shopCodes3 = SHOPS.filter(function(s) { return areaCodes3.indexOf(s.area_code) !== -1; }).map(function(s) { return s.shop_code; });
          list3 = list3.filter(function(o) { return shopCodes3.indexOf(o.shop_code) !== -1; });
        } else if (me3.role === 'area' && me3.area_code) {
          var shopCodes3b = SHOPS.filter(function(s) { return s.area_code === me3.area_code; }).map(function(s) { return s.shop_code; });
          list3 = list3.filter(function(o) { return shopCodes3b.indexOf(o.shop_code) !== -1; });
        }
      }
      if (query.category) list3 = list3.filter(function(o) { return o.category_code === query.category; });
      if (query.type)     list3 = list3.filter(function(o) { return o.type === query.type; });
      if (query.status !== undefined && query.status !== '') {
        var st = parseInt(query.status, 10);
        list3 = list3.filter(function(o) { return o.status === st; });
      }
      if (query.shop)     list3 = list3.filter(function(o) { return o.shop_code === query.shop; });
      if (query.zone) {
        var areaInZone = AREAS.filter(function(a) { return a.zone_code === query.zone; }).map(function(a) { return a.area_code; });
        var shopInZone = SHOPS.filter(function(s) { return areaInZone.indexOf(s.area_code) !== -1; }).map(function(s) { return s.shop_code; });
        list3 = list3.filter(function(o) { return shopInZone.indexOf(o.shop_code) !== -1; });
      }
      if (query.area) {
        var shopInArea = SHOPS.filter(function(s) { return s.area_code === query.area; }).map(function(s) { return s.shop_code; });
        list3 = list3.filter(function(o) { return shopInArea.indexOf(o.shop_code) !== -1; });
      }
      if (query.date_from) list3 = list3.filter(function(o) { return o.date >= query.date_from; });
      if (query.date_to)   list3 = list3.filter(function(o) { return o.date <= query.date_to; });
      return ok(list3);
    }
    if (path === 'api/orders/create.php')      return ok({ id: 'NEW-' + Date.now() });
    if (path === 'api/orders/status.php')      return ok({});
    if (path === 'api/orders/update-info.php') return ok({});
    if (path === 'api/orders/bulk-status.php') return ok({});
    if (path === 'api/orders/draft-mails.php') return ok([]);

    // ----- 予算 -----
    if (path === 'api/budgets.php') {
      if (query.action === 'years') {
        return ok([2024, 2025, 2026]);
      }
      var me4 = buildMeResponse();
      var year = parseInt(query.year, 10) || 2026;
      var dept = query.dept || 'all';
      var shopList = SHOPS.slice();
      if (me4) {
        if (me4.role === 'shop') shopList = shopList.filter(function(s) { return s.shop_code === me4.shop_code; });
        else if (me4.role === 'zone' && me4.zone_code) {
          var areaCodes4 = AREAS.filter(function(a) { return a.zone_code === me4.zone_code; }).map(function(a) { return a.area_code; });
          shopList = shopList.filter(function(s) { return areaCodes4.indexOf(s.area_code) !== -1; });
        } else if (me4.role === 'area' && me4.area_code) {
          shopList = shopList.filter(function(s) { return s.area_code === me4.area_code; });
        }
      }
      if (query.shop) shopList = shopList.filter(function(s) { return s.shop_code === query.shop; });
      if (query.zone) {
        var areaCodes5 = AREAS.filter(function(a) { return a.zone_code === query.zone; }).map(function(a) { return a.area_code; });
        shopList = shopList.filter(function(s) { return areaCodes5.indexOf(s.area_code) !== -1; });
      }
      if (query.area) shopList = shopList.filter(function(s) { return s.area_code === query.area; });
      var bd = shopList.map(function(s) {
        var a = AREAS.find(function(x) { return x.area_code === s.area_code; });
        return {
          shop_code: s.shop_code,
          shop_name: s.shop_name,
          area_code: s.area_code,
          zone_code: a ? a.zone_code : '',
          year: year,
          dept: dept,
          monthly: buildBudgetForShop(s, dept)
        };
      });
      return ok(bd);
    }

    // ----- 自店調達 -----
    if (path === 'api/procurement.php') {
      if (query.action === 'years') return ok([2024, 2025, 2026]);
      var me5 = buildMeResponse();
      var list5 = PROCUREMENT.slice();
      if (me5) {
        if (me5.role === 'shop')        list5 = list5.filter(function(p) { return p.shop_code === me5.shop_code; });
        else if (me5.role === 'zone' && me5.zone_code)
          list5 = list5.filter(function(p) { return p.zone_code === me5.zone_code; });
        else if (me5.role === 'area' && me5.area_code)
          list5 = list5.filter(function(p) { return p.area_code === me5.area_code; });
      }
      return ok(list5);
    }

    // ----- 商品 -----
    if (path === 'api/products.php') {
      var pList = PRODUCTS.slice();
      if (query.category) pList = pList.filter(function(p) { return p.category === query.category; });
      return ok(pList);
    }

    // ----- 画像（404 で placeholder 表示にフォールバック） -----
    if (path === 'api/product-image.php' || path === 'api/photo.php') {
      return new Response('', { status: 404 });
    }

    // ----- システム設定 / 管理 -----
    if (path === 'api/admin/system-settings.php') {
      if (method === 'POST') return ok({});
      return ok(SYSTEM_SETTINGS);
    }
    if (path === 'api/admin/categories.php')     return ok(CATEGORIES);
    if (path.indexOf('api/admin/master/') === 0) {
      // 一覧取得: 各マスタ → 既存の master/* と同じデータを返す
      if (path === 'api/admin/master/zones.php')     return ok(ZONES);
      if (path === 'api/admin/master/areas.php')     return ok(AREAS);
      if (path === 'api/admin/master/shops.php')     return ok(SHOPS);
      if (path === 'api/admin/master/users.php')     return ok(USERS);
      if (path === 'api/admin/master/products.php')  return ok(PRODUCTS);
      if (path === 'api/admin/master/suppliers.php') return ok([
        { code: 'FJP', name: 'フィットネスジャパン', email: 'order@fitness.test' },
        { code: 'GS',  name: 'ゴルフサプライ',       email: 'order@golf.test' },
        { code: 'DL',  name: 'ダーツライブ',         email: 'order@darts.test' }
      ]);
      if (path === 'api/admin/master/budgets.php')   return ok([]);
      // 更新系はすべて success
      return ok({});
    }
    if (path.indexOf('api/export/') === 0) {
      // Excel 出力（モック版では非対応 → そのまま 200 でダミー）
      return new Response('mock-excel', {
        status: 200,
        headers: { 'Content-Type': 'application/octet-stream' }
      });
    }
    if (path === 'api/system/master-change-log.php')        return ok(MASTER_CHANGE_LOG);
    if (path === 'api/system/master-scheduled-changes.php') return ok([]);
    if (path === 'api/system/login-history.php')            return ok(LOGIN_HISTORY);

    // ----- それ以外 -----
    return err('mock: 未実装エンドポイント: ' + path, 404);
  }

  // ====== window.fetch を上書き ======
  var originalFetch = window.fetch ? window.fetch.bind(window) : null;
  window.fetch = function(input, init) {
    init = init || {};
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    // 絶対 URL（外部）は素通し
    if (/^https?:\/\//i.test(url)) {
      if (originalFetch) return originalFetch(input, init);
      return Promise.reject(new Error('fetch unavailable'));
    }
    // api/ で始まらないリクエスト（画像など）も素通し
    var apiIdx = url.indexOf('api/');
    if (apiIdx !== 0 && url.indexOf('./api/') !== 0 && url.indexOf('/api/') !== 0) {
      if (originalFetch) return originalFetch(input, init);
      return Promise.reject(new Error('fetch unavailable'));
    }
    var path = url.replace(/^\.?\//, '').replace(/^\//, '');
    var qIdx = path.indexOf('?');
    var query = {};
    if (qIdx >= 0) {
      query = parseQuery(path.substring(qIdx));
      path = path.substring(0, qIdx);
    }
    var method = (init.method || 'GET').toUpperCase();
    var body = null;
    if (init.body) {
      try { body = JSON.parse(init.body); }
      catch (e) {
        if (init.body instanceof FormData) {
          body = {};
          init.body.forEach(function(v, k) { body[k] = v; });
        } else {
          body = init.body;
        }
      }
    }
    // 非同期で返す（実 API と同等の挙動）
    return new Promise(function(resolve) {
      setTimeout(function() {
        try {
          resolve(route(method, path, query, body));
        } catch (e) {
          console.error('[mock-api] route error:', e);
          resolve(jsonResponse({ success: false, error: 'mock route error: ' + e.message }, 500));
        }
      }, 30);
    });
  };

  // 公開: デバッグ用
  window.__mockApi = {
    getSessionUser: getSessionUser,
    setSessionUser: setSessionUser,
    clearSessionUser: clearSessionUser,
    findUserByLoginId: findUserByLoginId,
    USERS: USERS, SHOPS: SHOPS, ZONES: ZONES, AREAS: AREAS, CATEGORIES: CATEGORIES,
    ORDERS: ALL_ORDERS, PROCUREMENT: PROCUREMENT, PRODUCTS: PRODUCTS
  };
})();
