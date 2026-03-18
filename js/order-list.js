// ===== Role Detection (URL param ?role=admin) =====
var params = new URLSearchParams(window.location.search);
var viewMode = params.get('role') === 'admin' ? 'admin' : 'store';

// ===== Status Constants =====
var STATUS = { REQUESTING: 0, INPROGRESS: 1, ORDERED: 2, REPAIRED: 3, COMPLETED: 4 };
var STATUS_LABELS = { 0: '依頼中', 1: '対応中', 2: '発注済', 3: '修理済', 4: '完了' };
var STATUS_CLASSES = { 0: 'status-requesting', 1: 'status-inprogress', 2: 'status-ordered', 3: 'status-repaired', 4: 'status-completed' };

// ===== System Settings =====
var systemSettings = {
  equipmentDeadlineWeekday: 3  // 備品発注の締め曜日 (0=日,1=月,2=火,3=水,4=木,5=金,6=土)
};

// ===== Helper: 締め曜日の3営業日後を算出 =====
function getEquipmentDeliveryDate() {
  var deadlineDay = systemSettings.equipmentDeadlineWeekday;
  var today = new Date();
  // 直近の締め曜日を求める（今日含む）
  var daysUntil = (deadlineDay - today.getDay() + 7) % 7;
  var deadlineDate = new Date(today);
  deadlineDate.setDate(today.getDate() + daysUntil);
  // 締め曜日から3営業日後（土日スキップ）
  var bizDays = 0;
  var delivery = new Date(deadlineDate);
  while (bizDays < 3) {
    delivery.setDate(delivery.getDate() + 1);
    var dow = delivery.getDay();
    if (dow !== 0 && dow !== 6) bizDays++;
  }
  var y = delivery.getFullYear();
  var m = String(delivery.getMonth() + 1).padStart(2, '0');
  var d = String(delivery.getDate()).padStart(2, '0');
  return y + '-' + m + '-' + d;
}

// ===== Zone / Area / Shop Master =====
var zones = [
  { code: '100', name: '東日本' },
  { code: '200', name: '西日本' }
];
var areas = [
  { code: '101', name: '北海道', zone: '100' },
  { code: '102', name: '東北', zone: '100' },
  { code: '103', name: '関東', zone: '100' },
  { code: '201', name: '関西', zone: '200' },
  { code: '202', name: '中国・四国', zone: '200' }
];
var shops = [
  { code: '10101', name: '札幌', area: '101' },
  { code: '10102', name: '函館', area: '101' },
  { code: '10201', name: '仙台', area: '102' },
  { code: '10301', name: '新宿東口', area: '103' },
  { code: '10302', name: '池袋西口', area: '103' },
  { code: '10303', name: '横浜', area: '103' },
  { code: '20101', name: '梅田', area: '201' },
  { code: '20102', name: '難波', area: '201' },
  { code: '20201', name: '広島', area: '202' }
];

// ===== Helper: current user name =====
function getCurrentUser() {
  return viewMode === 'admin' ? '商品部' : '新宿東口店';
}
function todayStr() {
  var d = new Date();
  return d.getFullYear() + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + String(d.getDate()).padStart(2, '0');
}
function nowStr() {
  var d = new Date();
  return todayStr() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

// ===== Store-view orders (current store only) =====
var storeOrders = [
  {
    id: 'ORD-2026-001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常',
    amount: null, status: 0, date: '2026-02-25', shop: '10301',
    equipment: 'ランニングマシン TR-800', issue: 'ベルトが滑る。異音が発生。',
    unavailDates: ['2026-03-05（終日）', '2026-03-12（午前）'], unavailDays: ['火曜日', '木曜日'],
    photos: 2, estimateAmount: null, repairScheduleDate: '', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/25 09:15', user: '新宿東口店', memo: '' }]
  },
  {
    id: 'ORD-2026-002', type: 'equipment', category: 'fitness', title: 'トレーニングマット 他1商品',
    amount: 22900, status: 1, date: '2026-02-24', shop: '10301',
    equipDetails: [
      { name: 'トレーニングマット', code: 'MAT-001', price: 3500, qty: 5, supplier: 'フィットネスジャパン', arrivalDate: '2026-02-27' },
      { name: 'バランスボール 65cm', code: 'BB-065', price: 1800, qty: 3, supplier: 'スポーツ用品販売', arrivalDate: '2026-02-27' }
    ],
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/24 14:30', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/24 16:00', user: '商品部', memo: '確認済み。手配開始します。' }
    ]
  },
  {
    id: 'ORD-2026-003', type: 'parts', category: 'golf', title: 'スイング診断機 センサー交換部品',
    amount: null, status: 0, date: '2026-02-23', shop: '10301',
    partsName: 'センサーユニット SU-100', targetEquip: 'スイング診断機 GST-7',
    reason: 'センサー応答が遅くなっている', quantity: 1, partsPhotos: 2,
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [{ status: 0, date: '2026/02/23 11:20', user: '新宿東口店', memo: '' }]
  },
  {
    id: 'ORD-2026-004', type: 'equipment', category: 'golf', title: 'ゴルフボール 1ダース × 10',
    amount: 42000, status: 2, date: '2026-02-22', shop: '10301',
    equipDetails: [{ name: 'ゴルフボール 1ダース', code: 'GB-012', price: 4200, qty: 10, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-25' }],
    estimateAmount: 42000, finalAmount: null, deliveryDate: '2026-03-01', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/22 10:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/22 14:30', user: '商品部', memo: '' },
      { status: 2, date: '2026/02/23 09:00', user: '商品部', memo: 'ゴルフサプライへ発注済。3/1納品予定。' }
    ]
  },
  {
    id: 'ORD-2026-005', type: 'repair', category: 'fitness', title: 'エアロバイク 表示パネル故障',
    amount: null, status: 2, date: '2026-02-21', shop: '10301',
    equipment: 'エアロバイク AB-200', issue: '液晶パネルが表示されない',
    unavailDates: ['2026-03-03（午前）'], unavailDays: [], photos: 1,
    estimateAmount: 35000, repairScheduleDate: '2026-03-10', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/21 09:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/22 10:30', user: '商品部', memo: '業者へ見積もり依頼済。' },
      { status: 2, date: '2026/02/24 15:00', user: '商品部', memo: '見積もり回答あり。35,000円。修理日は3/10で調整中。' }
    ]
  },
  {
    id: 'ORD-2026-006', type: 'repair', category: 'golf', title: 'パッティングマシン モーター異常',
    amount: null, status: 4, date: '2026-02-18', shop: '10301',
    equipment: 'パッティングマシン PM-300', issue: 'モーターが回転しない',
    unavailDates: [], unavailDays: ['日曜日'], photos: 3,
    estimateAmount: 52000, repairScheduleDate: '2026-02-28', finalAmount: 48000, repairCompletedDate: '2026-02-27', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/18 08:30', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/19 09:00', user: '商品部', memo: '業者の手配済。日程の最終調整中。' },
      { status: 2, date: '2026/02/21 14:00', user: '商品部', memo: '第一希望日に修理業者が店舗へ伺うことに確定。' },
      { status: 3, date: '2026/02/27 17:00', user: '新宿東口店', memo: '修理完了。正常稼働を確認しました。' },
      { status: 4, date: '2026/02/28 10:00', user: '商品部', memo: '最終金額48,000円で確定。未使用部品分を差引。' }
    ]
  }
];

// ===== Admin-view orders (all stores) =====
var adminOrders = [
  // Copy of storeOrders for shop 10301
  storeOrders[0], storeOrders[1], storeOrders[2], storeOrders[3], storeOrders[4], storeOrders[5],
  // 札幌店
  {
    id: 'ORD-2026-007', type: 'equipment', category: 'fitness', title: 'ダンベルセット 10kg × 3',
    amount: 25200, status: 1, date: '2026-02-24', shop: '10101',
    equipDetails: [{ name: 'ダンベルセット 10kg', code: 'DB-010', price: 8400, qty: 3, supplier: 'フィットネスジャパン', arrivalDate: '2026-02-28' }],
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/24 10:00', user: '札幌店', memo: '' },
      { status: 1, date: '2026/02/24 15:00', user: '商品部', memo: '' }
    ]
  },
  {
    id: 'ORD-2026-008', type: 'repair', category: 'fitness', title: 'トレッドミル 異音発生',
    amount: null, status: 0, date: '2026-02-23', shop: '10101',
    equipment: 'トレッドミル TM-500', issue: '動作時に異音が発生',
    unavailDates: ['2026-03-07（終日）'], unavailDays: ['土曜日'], photos: 0,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/23 13:00', user: '札幌店', memo: '' }]
  },
  // 函館店
  {
    id: 'ORD-2026-009', type: 'parts', category: 'fitness', title: 'エアロバイク ペダル交換部品',
    amount: null, status: 2, date: '2026-02-22', shop: '10102',
    partsName: 'ペダルユニット PD-200', targetEquip: 'エアロバイク AB-150',
    reason: 'ペダル軸の摩耗', quantity: 2, partsPhotos: 1,
    estimateAmount: 8500, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/22 09:00', user: '函館店', memo: '' },
      { status: 1, date: '2026/02/22 14:00', user: '商品部', memo: '' },
      { status: 2, date: '2026/02/23 11:00', user: '商品部', memo: '8,500円で発注確定。' }
    ]
  },
  // 池袋西口店
  {
    id: 'ORD-2026-010', type: 'equipment', category: 'golf', title: 'グローブ Lサイズ 他1商品',
    amount: 38000, status: 1, date: '2026-02-25', shop: '10302',
    equipDetails: [
      { name: 'グローブ Lサイズ', code: 'GL-L01', price: 1500, qty: 20, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-28' },
      { name: 'ゴルフティー 100本入り', code: 'GT-100', price: 800, qty: 10, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-28' }
    ],
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/25 08:30', user: '池袋西口店', memo: '' },
      { status: 1, date: '2026/02/25 14:00', user: '商品部', memo: '' }
    ]
  },
  {
    id: 'ORD-2026-011', type: 'repair', category: 'golf', title: 'シミュレーター プロジェクター不具合',
    amount: null, status: 0, date: '2026-02-24', shop: '10302',
    equipment: 'ゴルフシミュレーター GS-Pro', issue: 'プロジェクターの映像がちらつく',
    unavailDates: ['2026-03-10（午後）'], unavailDays: ['月曜日'], photos: 1,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/24 10:15', user: '池袋西口店', memo: '' }]
  },
  // 横浜店
  {
    id: 'ORD-2026-012', type: 'equipment', category: 'fitness', title: 'タオル（大）10枚セット × 3',
    amount: 16800, status: 2, date: '2026-02-20', shop: '10303',
    equipDetails: [{ name: 'タオル（大）10枚セット', code: 'TW-L10', price: 5600, qty: 3, supplier: 'リネンサービス', arrivalDate: '2026-02-28' }],
    estimateAmount: 16800, finalAmount: null, deliveryDate: '2026-02-28', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/20 09:00', user: '横浜店', memo: '' },
      { status: 1, date: '2026/02/20 14:00', user: '商品部', memo: '' },
      { status: 2, date: '2026/02/21 10:00', user: '商品部', memo: 'リネンサービスへ発注。2/28納品予定。' }
    ]
  },
  // 仙台店
  {
    id: 'ORD-2026-013', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ',
    amount: null, status: 2, date: '2026-02-19', shop: '10201',
    equipment: 'レッグプレス LP-400', issue: '油圧シリンダーから微量の漏れ',
    unavailDates: ['2026-03-20（終日）'], unavailDays: [], photos: 2,
    estimateAmount: 65000, repairScheduleDate: '2026-03-15', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/19 08:00', user: '仙台店', memo: '' },
      { status: 1, date: '2026/02/20 09:00', user: '商品部', memo: '業者へ見積もり依頼。' },
      { status: 2, date: '2026/02/23 14:00', user: '商品部', memo: '見積もり回答あり。65,000円。3/15で修理予定。' }
    ]
  },
  // 梅田店
  {
    id: 'ORD-2026-014', type: 'equipment', category: 'golf', title: 'スコアカード 100枚 × 5',
    amount: 6000, status: 4, date: '2026-02-17', shop: '20101',
    equipDetails: [{ name: 'スコアカード 100枚', code: 'SC-100', price: 1200, qty: 5, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-21' }],
    estimateAmount: 6000, finalAmount: 6000, deliveryDate: '2026-02-21', actualDeliveryDate: '2026-02-21', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/17 09:00', user: '梅田店', memo: '' },
      { status: 1, date: '2026/02/17 14:00', user: '商品部', memo: '' },
      { status: 2, date: '2026/02/18 10:00', user: '商品部', memo: '2/21納品予定。' },
      { status: 4, date: '2026/02/22 00:00', user: 'システム（自動）', memo: '納品予定日翌日により自動完了。' }
    ]
  },
  {
    id: 'ORD-2026-015', type: 'parts', category: 'golf', title: 'スイングカメラ レンズユニット',
    amount: null, status: 0, date: '2026-02-26', shop: '20101',
    partsName: 'レンズユニット LC-300', targetEquip: 'スイングカメラ SC-200',
    reason: 'レンズに傷。映像にノイズ', quantity: 1, partsPhotos: 3,
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [{ status: 0, date: '2026/02/26 11:00', user: '梅田店', memo: '' }]
  },
  // 難波店
  {
    id: 'ORD-2026-016', type: 'repair', category: 'fitness', title: 'ランニングマシン 速度制御不良',
    amount: null, status: 0, date: '2026-02-26', shop: '20102',
    equipment: 'ランニングマシン TR-900', issue: '速度が安定しない',
    unavailDates: [], unavailDays: ['水曜日', '金曜日'], photos: 0,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/26 08:45', user: '難波店', memo: '' }]
  },
  // 広島店
  {
    id: 'ORD-2026-017', type: 'equipment', category: 'fitness', title: 'ヨガマット × 10',
    amount: 15000, status: 1, date: '2026-02-25', shop: '20201',
    equipDetails: [{ name: 'ヨガマット', code: 'YM-001', price: 1500, qty: 10, supplier: 'フィットネスジャパン', arrivalDate: '2026-02-28' }],
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/25 09:30', user: '広島店', memo: '' },
      { status: 1, date: '2026/02/25 15:00', user: '商品部', memo: '' }
    ]
  }
];

var expandedIds = {};

// ===== Init: apply role-based UI =====
function initView() {
  document.getElementById('storeFilterBar').style.display = viewMode === 'store' ? '' : 'none';
  document.getElementById('storeActionBar').style.display = viewMode === 'store' ? 'flex' : 'none';
  document.getElementById('adminFilterBar').style.display = viewMode === 'admin' ? 'block' : 'none';
  document.getElementById('adminActionBar').style.display = viewMode === 'admin' ? 'flex' : 'none';

  renderTableHeader();
  renderOrders();
}

// ===== Dynamic Table Header =====
function renderTableHeader() {
  var thead = document.getElementById('orderTableHead');
  if (viewMode === 'admin') {
    thead.innerHTML = '<tr>' +
      '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
      '<th>種別</th><th>発注番号</th><th>店舗</th><th>カテゴリ</th><th>内容</th>' +
      '<th>発注数</th><th>金額</th><th>ステータス</th><th>発注日</th>' +
      '<th style="width:60px">詳細</th></tr>';
  } else {
    thead.innerHTML = '<tr>' +
      '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
      '<th>種別</th><th>発注番号</th><th>カテゴリ</th><th>内容</th>' +
      '<th>発注数</th><th>金額</th><th>ステータス</th><th>発注日</th>' +
      '<th style="width:60px">詳細</th></tr>';
  }
}

// ===== Cascading Zone > Area > Shop =====
function onZoneChange() {
  var zoneVal = document.getElementById('filterZone').value;
  var areaSelect = document.getElementById('filterArea');
  var filtered = zoneVal ? areas.filter(function(a) { return a.zone === zoneVal; }) : areas;
  areaSelect.innerHTML = '<option value="">すべて</option>' +
    filtered.map(function(a) { return '<option value="' + a.code + '">' + a.code + ':' + a.name + '</option>'; }).join('');
  onAreaChange();
}

function onAreaChange() {
  var areaVal = document.getElementById('filterArea').value;
  var zoneVal = document.getElementById('filterZone').value;
  var shopSelect = document.getElementById('filterShop');
  var filtered = shops;
  if (areaVal) {
    filtered = shops.filter(function(s) { return s.area === areaVal; });
  } else if (zoneVal) {
    var areaCodes = areas.filter(function(a) { return a.zone === zoneVal; }).map(function(a) { return a.code; });
    filtered = shops.filter(function(s) { return areaCodes.indexOf(s.area) >= 0; });
  }
  shopSelect.innerHTML = '<option value="">すべて</option>' +
    filtered.map(function(s) { return '<option value="' + s.code + '">' + s.code + ':' + s.name + '</option>'; }).join('');
  renderOrders();
}

function getShopName(code) {
  var shop = shops.find(function(s) { return s.code === code; });
  return shop ? shop.name : code;
}

// ===== Render Orders =====
function renderOrders() {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  var filtered;

  if (viewMode === 'admin') {
    var shopFilter = document.getElementById('filterShop').value;
    var zoneFilter = document.getElementById('filterZone').value;
    var areaFilter = document.getElementById('filterArea').value;
    var typeFilter = document.getElementById('adminFilterType').value;
    var statusFilter = document.getElementById('adminFilterStatus').value;
    var dateFrom = document.getElementById('filterDateFrom').value;
    var dateTo = document.getElementById('filterDateTo').value;

    filtered = orders.filter(function(o) {
      if (shopFilter && o.shop !== shopFilter) return false;
      if (!shopFilter && areaFilter) {
        var shop = shops.find(function(s) { return s.code === o.shop; });
        if (!shop || shop.area !== areaFilter) return false;
      }
      if (!shopFilter && !areaFilter && zoneFilter) {
        var shop = shops.find(function(s) { return s.code === o.shop; });
        if (!shop) return false;
        var area = areas.find(function(a) { return a.code === shop.area; });
        if (!area || area.zone !== zoneFilter) return false;
      }
      if (typeFilter && o.type !== typeFilter) return false;
      if (statusFilter !== '' && o.status !== parseInt(statusFilter)) return false;
      if (dateFrom && o.date < dateFrom) return false;
      if (dateTo && o.date > dateTo) return false;
      return true;
    });
  } else {
    var catFilter = document.getElementById('filterCategory').value;
    var typeFilter = document.getElementById('filterType').value;
    var statusFilter = document.getElementById('filterStatus').value;

    filtered = orders.filter(function(o) {
      if (catFilter && o.category !== catFilter) return false;
      if (typeFilter && o.type !== typeFilter) return false;
      if (statusFilter !== '' && o.status !== parseInt(statusFilter)) return false;
      return true;
    });
  }

  var colSpan = viewMode === 'admin' ? 11 : 10;
  var tbody = document.getElementById('orderTableBody');
  var html = '';

  filtered.forEach(function(o) {
    var typeClass = 'type-' + o.type;
    var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
    var statusClass = STATUS_CLASSES[o.status];
    var statusLabel = STATUS_LABELS[o.status];
    var catLabel = o.category === 'fitness' ? 'フィットネス' : 'ゴルフ';
    var displayAmount = getDisplayAmount(o);
    var isOpen = !!expandedIds[o.id];

    var orderCount = 1;
    var contentLabel = o.title;
    if (o.type === 'equipment' && o.equipDetails) {
      orderCount = o.equipDetails.length;
      if (orderCount === 1) {
        contentLabel = o.equipDetails[0].name + ' × ' + o.equipDetails[0].qty;
      } else {
        contentLabel = o.equipDetails[0].name + ' 他' + (orderCount - 1) + '商品';
      }
    }

    html += '<tr class="order-row ' + o.type + '">' +
      '<td><input type="checkbox" class="order-check" data-id="' + o.id + '"></td>' +
      '<td><span class="type-badge ' + typeClass + '">' + typeLabel + '</span></td>' +
      '<td><strong>' + o.id + '</strong></td>';

    if (viewMode === 'admin') {
      html += '<td>' + getShopName(o.shop) + '</td>';
    }

    html += '<td>' + catLabel + '</td>' +
      '<td>' + contentLabel + '</td>' +
      '<td>' + orderCount + '</td>' +
      '<td>' + displayAmount + '</td>' +
      '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
      '<td>' + o.date + '</td>' +
      '<td><button class="btn-sm" onclick="toggleDetail(\'' + o.id + '\')">' + (isOpen ? '−' : '+') + '</button></td>' +
    '</tr>';

    // Detail row
    html += '<tr class="detail-panel' + (isOpen ? ' open' : '') + '" id="detail-' + o.id + '">' +
      '<td colspan="' + colSpan + '"><div class="detail-content">';
    html += renderDetailContent(o);
    html += '</div></td></tr>';
  });

  if (!filtered.length) {
    html = '<tr><td colspan="' + colSpan + '" style="text-align:center;padding:40px;color:#94a3b8;">該当する発注がありません</td></tr>';
  }

  tbody.innerHTML = html;
}

// ===== Get display amount =====
function getDisplayAmount(o) {
  if (o.finalAmount) return '¥' + o.finalAmount.toLocaleString();
  if (o.estimateAmount) return '¥' + o.estimateAmount.toLocaleString();
  if (o.type === 'repair' || o.type === 'parts') return '—';
  if (o.amount) return '¥' + o.amount.toLocaleString();
  return '—';
}

// ===== Render Detail Content (two-column layout) =====
function renderDetailContent(o) {
  var html = '';

  // Admin: show shop name at top
  if (viewMode === 'admin') {
    html += '<div style="margin-bottom:12px;"><span class="detail-label">店舗</span> <span class="detail-value" style="font-weight:600;">' + o.shop + ':' + getShopName(o.shop) + '</span></div>';
  }

  html += '<div class="detail-two-col">';

  // === Left: Store input info (read-only) ===
  html += '<div>';
  html += '<div class="detail-section-title store-info">' +
    (o.type === 'repair' ? '店舗からの依頼内容' : o.type === 'equipment' ? '発注内容' : '部品発注内容') + '</div>';

  if (o.type === 'repair') {
    html += '<div class="detail-grid">' +
      '<div><div class="detail-label">故障機材</div><div class="detail-value">' + (o.equipment || '') + '</div></div>' +
      '<div><div class="detail-label">不具合内容</div><div class="detail-value">' + (o.issue || '') + '</div></div>' +
    '</div>';
    if ((o.unavailDates && o.unavailDates.length) || (o.unavailDays && o.unavailDays.length)) {
      html += '<div class="detail-grid">';
      if (o.unavailDates && o.unavailDates.length) {
        html += '<div><div class="detail-label">対応不可日時</div><div class="detail-value">' + o.unavailDates.join('、') + '</div></div>';
      }
      if (o.unavailDays && o.unavailDays.length) {
        html += '<div><div class="detail-label">対応不可曜日</div><div class="detail-value">' + o.unavailDays.join('、') + '</div></div>';
      }
      html += '</div>';
    }
    if (o.photos && o.photos > 0) {
      html += renderPhotos(o.photos, '故障写真');
    }
  } else if (o.type === 'equipment') {
    if (o.equipDetails && o.equipDetails.length) {
      html += '<div class="equip-items-table"><table class="equip-table"><thead><tr><th>商品名</th><th>商品コード</th><th>仕入先</th><th>単価</th><th>数量</th><th>小計</th></tr></thead><tbody>';
      o.equipDetails.forEach(function(d) {
        html += '<tr><td>' + d.name + '</td><td>' + d.code + '</td><td>' + (d.supplier || '') + '</td><td>¥' + d.price.toLocaleString() + '</td><td>' + d.qty + '</td><td>¥' + (d.price * d.qty).toLocaleString() + '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
  } else {
    html += '<div class="detail-grid">' +
      '<div><div class="detail-label">部品名・品番</div><div class="detail-value">' + (o.partsName || '') + '</div></div>' +
      '<div><div class="detail-label">対象機材</div><div class="detail-value">' + (o.targetEquip || '') + '</div></div>' +
      '<div><div class="detail-label">数量</div><div class="detail-value">' + (o.quantity || 1) + '</div></div>' +
      '<div><div class="detail-label">発注理由・備考</div><div class="detail-value">' + (o.reason || '') + '</div></div>' +
    '</div>';
    if (o.partsPhotos && o.partsPhotos > 0) {
      html += renderPhotos(o.partsPhotos, '写真');
    }
  }
  html += '</div>';

  // === Right: Response info + Status history + Action button ===
  html += '<div>';
  var canEdit = canEditResponseInfo(o);
  html += '<div class="detail-section-title response-info" style="justify-content:space-between;">' +
    '対応情報' +
    (canEdit ? '<button class="btn-edit-info" onclick="openEditInfoModal(\'' + o.id + '\')" title="対応情報を編集"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/></svg></button>' : '') +
    '</div>';

  // Response info card
  html += '<div class="response-info-card"><div class="detail-grid">';
  if (o.type === 'repair') {
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理予定日</div><div class="detail-value">' + (o.repairScheduleDate || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理完了日</div><div class="detail-value">' + (o.repairCompletedDate || '—') + '</div></div>';
  } else if (o.type === 'equipment') {
    var equipEstimate = o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : (o.amount ? '¥' + o.amount.toLocaleString() : '—');
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + equipEstimate + '</div></div>';
    var equipDelivery = o.deliveryDate || getEquipmentDeliveryDate();
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + equipDelivery + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
    var equipActualDate = '';
    if (o.actualDeliveryDate) {
      equipActualDate = o.actualDeliveryDate;
    } else if (o.status === STATUS.COMPLETED) {
      // 完了時は最後のステータス履歴日付を納品日として表示
      var lastHist = o.statusHistory && o.statusHistory.length ? o.statusHistory[o.statusHistory.length - 1] : null;
      equipActualDate = lastHist ? lastHist.date.split(' ')[0].replace(/\//g, '-') : '—';
    }
    html += '<div><div class="detail-label">納品日</div><div class="detail-value">' + (equipActualDate || '—') + '</div></div>';
  } else {
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + (o.deliveryDate || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
  }
  html += '</div></div>';

  // Status history
  html += renderStatusHistory(o);

  // Action button
  html += renderActionButton(o);

  html += '</div>'; // end right column
  html += '</div>'; // end detail-two-col
  return html;
}

// ===== Render Photos =====
function renderPhotos(count, label) {
  var html = '<div class="photo-section"><div class="detail-label">' + label + '（' + count + '枚）</div><div class="photo-grid">';
  for (var i = 0; i < count; i++) {
    html += '<div class="photo-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg><span>写真 ' + (i + 1) + '</span></div>';
  }
  html += '</div></div>';
  return html;
}

// ===== Render Status History =====
function renderStatusHistory(o) {
  var html = '<div class="status-history"><div class="status-history-title">ステータス履歴</div>';
  html += '<div class="status-timeline">';
  var history = o.statusHistory || [];
  for (var i = history.length - 1; i >= 0; i--) {
    var h = history[i];
    var isCurrent = (i === history.length - 1);
    html += '<div class="timeline-item">';
    html += '<div class="timeline-dot ' + (isCurrent ? 'current' : 'past') + '"></div>';
    html += '<div class="timeline-status ' + (isCurrent ? 'current' : 'past') + '">' + STATUS_LABELS[h.status] + (isCurrent ? ' ← 現在' : '') + '</div>';
    html += '<div class="timeline-meta">' + h.date + ' — ' + h.user + '</div>';
    if (h.memo) {
      html += '<div class="timeline-memo">' + h.memo + '</div>';
    }
    html += '</div>';
  }
  html += '</div></div>';
  return html;
}

// ===== Render Action Button =====
function renderActionButton(o) {
  var html = '<div class="detail-actions">';
  var canAct = false;

  if (o.status === STATUS.REQUESTING && viewMode === 'admin') {
    html += '<button class="btn-sm btn-sm-primary" onclick="openStatusModal(\'' + o.id + '\', \'inprogress\')">対応中にする</button>';
    canAct = true;
  } else if (o.status === STATUS.INPROGRESS && viewMode === 'admin') {
    html += '<button class="btn-sm btn-sm-primary" onclick="openStatusModal(\'' + o.id + '\', \'ordered\')">発注確定にする</button>';
    canAct = true;
  } else if (o.status === STATUS.ORDERED && o.type === 'repair' && viewMode === 'store') {
    html += '<button class="btn-sm btn-sm-pink" onclick="openStatusModal(\'' + o.id + '\', \'repaired\')">修理完了にする</button>';
    canAct = true;
  } else if (o.status === STATUS.REPAIRED && viewMode === 'admin') {
    html += '<button class="btn-sm btn-sm-success" onclick="openStatusModal(\'' + o.id + '\', \'completed\')">完了にする</button>';
    canAct = true;
  } else if (o.status === STATUS.ORDERED && o.type !== 'repair' && viewMode === 'admin') {
    html += '<button class="btn-sm btn-sm-success" onclick="openStatusModal(\'' + o.id + '\', \'completed\')">完了にする</button>';
    canAct = true;
  }

  if (!canAct) {
    var infoMsg = '';
    if (o.status === STATUS.COMPLETED) {
      if (o.type === 'repair') {
        infoMsg = '修理完了';
      } else {
        infoMsg = '納品完了';
      }
    } else if (o.status === STATUS.ORDERED && o.type === 'repair') {
      infoMsg = '店舗修理待ち';
    } else if (o.status === STATUS.REQUESTING && viewMode === 'store') {
      infoMsg = '本部対応待ち';
    } else if (o.status === STATUS.INPROGRESS && viewMode === 'store') {
      infoMsg = '本部対応待ち';
    } else if (o.status === STATUS.ORDERED && viewMode === 'store') {
      infoMsg = '納品待ち';
    } else if (o.status === STATUS.REPAIRED && viewMode === 'store') {
      infoMsg = '金額確定待ち';
    } else {
      infoMsg = '—';
    }
    html += '<span style="font-size:12px;color:#94a3b8;">' + infoMsg + '</span>';
  }

  html += '</div>';
  return html;
}

// ===== Modal Dialog =====
function openStatusModal(orderId, action) {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  var order = orders.find(function(o) { return o.id === orderId; });
  if (!order) return;

  var modal = document.getElementById('modalOverlay');
  var title = document.getElementById('modalTitle');
  var body = document.getElementById('modalBody');
  var footer = document.getElementById('modalFooter');

  if (action === 'inprogress') {
    title.textContent = '対応中にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">内容</span><input class="modal-input readonly" value="' + order.title + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-primary" onclick="doStatusChange(\'' + orderId + '\', ' + STATUS.INPROGRESS + ')">対応中にする</button>';
  } else if (action === 'ordered') {
    title.textContent = '発注を確定する';
    var isRepair = order.type === 'repair';
    var isEquipment = order.type === 'equipment';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">見積金額 <span class="required">*</span></span><input class="modal-input" id="modalAmount" type="text" inputmode="numeric" placeholder="金額を入力"></div>' +
      '<div class="modal-row"><span class="modal-label">' + (isRepair ? '修理予定日' : '納品予定日') + (isEquipment ? ' <span class="required">*</span>' : '') + '</span><input class="modal-input" id="modalDate" type="date"></div>' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-primary" onclick="doOrderConfirm(\'' + orderId + '\')">発注確定にする</button>';
    // 備品: 金額を商品マスタ合計から自動入力、納品予定日を締め曜日の3営業日後で自動入力
    if (isEquipment) {
      if (order.equipDetails) {
        var total = 0;
        order.equipDetails.forEach(function(d) { total += d.price * d.qty; });
        document.getElementById('modalAmount').value = total;
      }
      document.getElementById('modalDate').value = getEquipmentDeliveryDate();
    }
  } else if (action === 'repaired') {
    title.textContent = '修理完了にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">機材名</span><input class="modal-input readonly" value="' + (order.equipment || '') + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-info">修理が完了し、機材が正常に稼働していることを確認してから報告してください。</div>' +
      '<div class="modal-row"><span class="modal-label">修理完了日 <span class="required">*</span></span><input class="modal-input" id="modalRepairDate" type="date"></div>' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="稼働状況や備考"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-pink" onclick="doRepairComplete(\'' + orderId + '\')">修理完了にする</button>';
  } else if (action === 'completed') {
    title.textContent = '完了にする';
    var estAmt = order.estimateAmount || order.amount || 0;
    var isEquipComplete = order.type === 'equipment';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">見積額</span><input class="modal-input readonly" value="¥' + estAmt.toLocaleString() + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">最終金額' + (isEquipComplete ? '' : ' <span class="required">*</span>') + '</span><input class="modal-input" id="modalFinalAmount" type="text" inputmode="numeric" placeholder="' + (isEquipComplete ? '未入力で見積金額を適用' : '最終金額を入力') + '" oninput="updateDiff(' + estAmt + ')"></div>' +
      '<div class="modal-diff" id="modalDiffRow" style="display:none;"><span class="modal-diff-label">差額</span><span class="modal-diff-value" id="modalDiffValue"></span></div>' +
      (isEquipComplete ? '<div class="modal-row"><span class="modal-label">納品日</span><input class="modal-input" id="modalActualDeliveryDate" type="date"><div style="font-size:11px;color:#94a3b8;margin-top:2px;">未入力で納品予定日を適用</div></div>' : '') +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-success" onclick="doComplete(\'' + orderId + '\')">完了にする</button>';
  }

  modal.classList.add('open');
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function updateDiff(estimateAmount) {
  var input = document.getElementById('modalFinalAmount');
  var diffRow = document.getElementById('modalDiffRow');
  var diffValue = document.getElementById('modalDiffValue');
  var val = parseInt(input.value);
  if (isNaN(val)) {
    diffRow.style.display = 'none';
    return;
  }
  var diff = val - estimateAmount;
  diffRow.style.display = 'flex';
  if (diff <= 0) {
    diffValue.textContent = '¥' + diff.toLocaleString();
    diffValue.className = 'modal-diff-value minus';
  } else {
    diffValue.textContent = '+¥' + diff.toLocaleString();
    diffValue.className = 'modal-diff-value plus';
  }
}

// ===== Status Change Actions =====
function findOrder(orderId) {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  return orders.find(function(o) { return o.id === orderId; });
}

function doStatusChange(orderId, newStatus) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  order.status = newStatus;
  order.statusHistory.push({ status: newStatus, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

function doOrderConfirm(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var amountInput = document.getElementById('modalAmount');
  var dateInput = document.getElementById('modalDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  var amount = parseInt(amountInput.value);
  if (isNaN(amount) || amount <= 0) {
    alert('金額を入力してください');
    return;
  }

  if (order.type === 'equipment' && !dateInput.value) {
    alert('備品は納品予定日を入力してください');
    return;
  }

  order.estimateAmount = amount;
  if (order.type === 'repair') {
    order.repairScheduleDate = dateInput.value || '';
  } else {
    order.deliveryDate = dateInput.value || '';
  }
  order.status = STATUS.ORDERED;
  order.statusHistory.push({ status: STATUS.ORDERED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

function doRepairComplete(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var dateInput = document.getElementById('modalRepairDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  if (!dateInput.value) {
    alert('修理完了日を入力してください');
    return;
  }

  order.repairCompletedDate = dateInput.value;
  order.status = STATUS.REPAIRED;
  order.statusHistory.push({ status: STATUS.REPAIRED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

function doComplete(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  var finalInput = document.getElementById('modalFinalAmount');
  var finalAmount = parseInt(finalInput.value);

  if (order.type === 'equipment') {
    // 備品: 最終金額は任意（未入力なら見積金額を適用）
    order.finalAmount = (!isNaN(finalAmount) && finalAmount > 0) ? finalAmount : (order.estimateAmount || order.amount || 0);
    // 納品日は任意（未入力なら納品予定日を適用）
    var actualDateInput = document.getElementById('modalActualDeliveryDate');
    order.actualDeliveryDate = actualDateInput.value || order.deliveryDate || getEquipmentDeliveryDate();
  } else {
    // 修理・部品: 最終金額は必須
    if (isNaN(finalAmount) || finalAmount <= 0) {
      alert('最終金額を入力してください');
      return;
    }
    order.finalAmount = finalAmount;
  }

  order.status = STATUS.COMPLETED;
  order.statusHistory.push({ status: STATUS.COMPLETED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

// ===== Edit Response Info =====
function canEditResponseInfo(o) {
  // 対応中以降で、そのステータスで入力された情報を編集可能
  // 管理者: 対応中/発注済/完了の情報を編集可能
  // 店舗: 修理済（修理のみ、店舗が入力）の情報を編集可能
  if (o.status === STATUS.REQUESTING) return false;
  if (viewMode === 'admin') {
    // 管理者は対応中以降いつでも編集可能
    return o.status >= STATUS.INPROGRESS;
  } else {
    // 店舗は修理済のみ（自分が修理完了報告した情報）
    return o.type === 'repair' && o.status === STATUS.REPAIRED;
  }
}

function getEditableFields(o) {
  var fields = [];
  if (viewMode === 'admin') {
    if (o.status === STATUS.INPROGRESS) {
      fields.push({ key: 'memo_inprogress', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.INPROGRESS) });
    } else if (o.status === STATUS.ORDERED) {
      if (o.type === 'repair') {
        fields.push({ key: 'estimateAmount', label: '見積金額', type: 'number', value: o.estimateAmount });
        fields.push({ key: 'repairScheduleDate', label: '修理予定日', type: 'date', value: o.repairScheduleDate });
      } else if (o.type === 'equipment') {
        fields.push({ key: 'estimateAmount', label: '見積金額', type: 'number', value: o.estimateAmount || o.amount });
        fields.push({ key: 'deliveryDate', label: '納品予定日', type: 'date', value: o.deliveryDate });
      } else {
        fields.push({ key: 'estimateAmount', label: '見積金額', type: 'number', value: o.estimateAmount });
        fields.push({ key: 'deliveryDate', label: '納品予定日', type: 'date', value: o.deliveryDate });
      }
      fields.push({ key: 'memo_ordered', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.ORDERED) });
    } else if (o.status === STATUS.COMPLETED) {
      fields.push({ key: 'finalAmount', label: '最終金額', type: 'number', value: o.finalAmount });
      if (o.type === 'equipment') {
        fields.push({ key: 'actualDeliveryDate', label: '納品日', type: 'date', value: o.actualDeliveryDate });
      }
      fields.push({ key: 'memo_completed', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.COMPLETED) });
    }
  } else {
    // 店舗: 修理済の情報のみ
    if (o.type === 'repair' && o.status === STATUS.REPAIRED) {
      fields.push({ key: 'repairCompletedDate', label: '修理完了日', type: 'date', value: o.repairCompletedDate });
      fields.push({ key: 'memo_repaired', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.REPAIRED) });
    }
  }
  return fields;
}

function findHistoryIndex(o, status) {
  if (!o.statusHistory) return -1;
  for (var i = o.statusHistory.length - 1; i >= 0; i--) {
    if (o.statusHistory[i].status === status) return i;
  }
  return -1;
}

function openEditInfoModal(orderId) {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  var order = orders.find(function(o) { return o.id === orderId; });
  if (!order) return;

  var fields = getEditableFields(order);
  if (fields.length === 0) return;

  var modal = document.getElementById('modalOverlay');
  var title = document.getElementById('modalTitle');
  var body = document.getElementById('modalBody');
  var footer = document.getElementById('modalFooter');

  title.textContent = '対応情報の編集';

  var html = '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
    '<hr class="modal-divider">';

  fields.forEach(function(f) {
    if (f.type === 'number') {
      html += '<div class="modal-row"><span class="modal-label">' + f.label + '</span>' +
        '<input class="modal-input" id="editField_' + f.key + '" type="text" inputmode="numeric" value="' + (f.value || '') + '"></div>';
    } else if (f.type === 'date') {
      html += '<div class="modal-row"><span class="modal-label">' + f.label + '</span>' +
        '<input class="modal-input" id="editField_' + f.key + '" type="date" value="' + (f.value || '') + '"></div>';
    } else if (f.type === 'textarea') {
      var memoVal = '';
      if (f.statusIndex >= 0 && order.statusHistory[f.statusIndex]) {
        memoVal = order.statusHistory[f.statusIndex].memo || '';
      }
      html += '<div class="modal-row"><span class="modal-label">' + f.label + '</span>' +
        '<textarea class="modal-textarea" id="editField_' + f.key + '">' + memoVal + '</textarea></div>';
    }
  });

  body.innerHTML = html;
  footer.innerHTML =
    '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
    '<button class="btn-modal btn-modal-primary" onclick="doSaveEditInfo(\'' + orderId + '\')">保存</button>';

  modal.classList.add('open');
}

function doSaveEditInfo(orderId) {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  var order = orders.find(function(o) { return o.id === orderId; });
  if (!order) return;

  var fields = getEditableFields(order);

  fields.forEach(function(f) {
    var el = document.getElementById('editField_' + f.key);
    if (!el) return;

    if (f.type === 'number') {
      var val = parseInt(el.value);
      if (!isNaN(val) && val > 0) {
        order[f.key] = val;
      }
    } else if (f.type === 'date') {
      order[f.key] = el.value || '';
    } else if (f.type === 'textarea') {
      if (f.statusIndex >= 0 && order.statusHistory[f.statusIndex]) {
        order.statusHistory[f.statusIndex].memo = el.value || '';
      }
    }
  });

  closeModal();
  renderOrders();
}

// ===== Other Actions =====
function toggleDetail(id) {
  expandedIds[id] = !expandedIds[id];
  renderOrders();
}

function toggleAll(checkbox) {
  document.querySelectorAll('.order-check').forEach(function(cb) { cb.checked = checkbox.checked; });
}

function exportExcel() {
  alert('Excel出力（モックアップ）\n\n選択された発注をExcelファイルとしてダウンロードします。');
}

// ===== Bulk Status Change =====
function getCheckedOrders() {
  var orders = viewMode === 'admin' ? adminOrders : storeOrders;
  var ids = [];
  document.querySelectorAll('.order-check:checked').forEach(function(cb) { ids.push(cb.dataset.id); });
  return orders.filter(function(o) { return ids.indexOf(o.id) >= 0; });
}

function bulkInprogress() {
  var checked = getCheckedOrders();
  if (checked.length === 0) {
    alert('対象の発注をチェックしてください。');
    return;
  }
  var targets = checked.filter(function(o) { return o.status === STATUS.REQUESTING; });
  if (targets.length === 0) {
    alert('チェックされた発注の中に「依頼中」ステータスのものがありません。');
    return;
  }

  var modal = document.getElementById('modalOverlay');
  var title = document.getElementById('modalTitle');
  var body = document.getElementById('modalBody');
  var footer = document.getElementById('modalFooter');

  title.textContent = '一括対応中にする';
  var listHtml = '<div class="modal-info">以下の <strong>' + targets.length + '件</strong> を「対応中」に変更します。</div>';
  listHtml += '<div style="max-height:200px;overflow-y:auto;margin-bottom:12px;">';
  targets.forEach(function(o) {
    var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
    listHtml += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">' +
      '<span><strong>' + o.id + '</strong> ' + o.title + '</span>' +
      '<span class="type-badge type-' + o.type + '" style="margin-left:8px;">' + typeLabel + '</span>' +
    '</div>';
  });
  listHtml += '</div>';
  if (checked.length > targets.length) {
    var skipped = checked.length - targets.length;
    listHtml += '<div style="font-size:11px;color:#94a3b8;">※ 依頼中以外の ' + skipped + '件 はスキップされます</div>';
  }
  listHtml += '<hr class="modal-divider">';
  listHtml += '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力（全件共通）"></textarea></div>';

  body.innerHTML = listHtml;
  footer.innerHTML =
    '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
    '<button class="btn-modal btn-modal-primary" onclick="doBulkInprogress()">一括対応中にする</button>';
  modal.classList.add('open');
}

function doBulkInprogress() {
  var checked = getCheckedOrders();
  var targets = checked.filter(function(o) { return o.status === STATUS.REQUESTING; });
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  var ts = nowStr();
  var user = getCurrentUser();
  targets.forEach(function(o) {
    o.status = STATUS.INPROGRESS;
    o.statusHistory.push({ status: STATUS.INPROGRESS, date: ts, user: user, memo: memo });
  });
  closeModal();
  var selectAll = document.getElementById('selectAll');
  if (selectAll) selectAll.checked = false;
  renderOrders();
}

// ===== Init =====
initView();
