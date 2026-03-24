// ===== Role Detection =====
var params = new URLSearchParams(window.location.search);
var viewMode = params.get('role') === 'admin' ? 'admin' : 'store';

// ===== Status Constants =====
// 全種別共通の5段階（番号は共通、ラベルは種別で異なる）
var STATUS = {
  REQUESTING: 0, // ①依頼中
  ORDERED: 1,    // ②発注済
  DELIVERING: 2, // ③配達中（備品・部品）/ 修理待ち（修理）
  DELIVERED: 3,  // ④納品済（備品・部品）/ 修理済（修理）
  COMPLETED: 4   // ⑤完了
};

// 種別ごとのステータスラベル
var STATUS_LABELS_BY_TYPE = {
  equipment: { 0: '依頼中', 1: '発注済', 2: '配達中', 3: '納品済', 4: '完了' },
  parts:     { 0: '依頼中', 1: '発注済', 2: '配達中', 3: '納品済', 4: '完了' },
  repair:    { 0: '依頼中', 1: '発注済', 2: '修理待ち', 3: '修理済', 4: '完了' }
};

// 種別ごとのCSSクラス
var STATUS_CLASSES_BY_TYPE = {
  equipment: { 0: 'status-requesting', 1: 'status-ordered', 2: 'status-delivering', 3: 'status-delivered', 4: 'status-completed' },
  parts:     { 0: 'status-requesting', 1: 'status-ordered', 2: 'status-delivering', 3: 'status-delivered', 4: 'status-completed' },
  repair:    { 0: 'status-requesting', 1: 'status-ordered', 2: 'status-waiting-repair', 3: 'status-repaired', 4: 'status-completed' }
};

// フィルタ用の共通ラベル（種別横断）
var FILTER_STATUS_OPTIONS = [
  { value: '0', label: '依頼中' },
  { value: '1', label: '発注済' },
  { value: '2', label: '配達中/修理待ち' },
  { value: '3', label: '納品済/修理済' },
  { value: '4', label: '完了' }
];

function getStatusLabel(status, type) {
  return (STATUS_LABELS_BY_TYPE[type] || STATUS_LABELS_BY_TYPE.equipment)[status] || '';
}

function getStatusClass(status, type) {
  return (STATUS_CLASSES_BY_TYPE[type] || STATUS_CLASSES_BY_TYPE.equipment)[status] || '';
}

// ===== System Settings =====
var systemSettings = {
  equipmentDeadlineWeekday: 3 // 締め曜日 (0=日,1=月,2=火,3=水,4=木,5=金,6=土)
};

// ===== Date Helpers =====
function getNextDeadlineDate() {
  var deadlineDay = systemSettings.equipmentDeadlineWeekday;
  var today = new Date();
  var daysUntil = (deadlineDay - today.getDay() + 7) % 7;
  if (daysUntil === 0) daysUntil = 7;
  var d = new Date(today);
  d.setDate(today.getDate() + daysUntil);
  return formatDate(d);
}

function getEquipmentDeliveryDate() {
  var deadlineDay = systemSettings.equipmentDeadlineWeekday;
  var today = new Date();
  var daysUntil = (deadlineDay - today.getDay() + 7) % 7;
  var deadlineDate = new Date(today);
  deadlineDate.setDate(today.getDate() + daysUntil);
  // 締め曜日の翌日を納品予定日とする（カレンダー日ベース）
  var delivery = new Date(deadlineDate);
  delivery.setDate(delivery.getDate() + 1);
  return formatDate(delivery);
}

function formatDate(d) {
  var y = d.getFullYear();
  var m = String(d.getMonth() + 1).padStart(2, '0');
  var day = String(d.getDate()).padStart(2, '0');
  return y + '-' + m + '-' + day;
}

function todayStr() {
  var d = new Date();
  return d.getFullYear() + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + String(d.getDate()).padStart(2, '0');
}

function nowStr() {
  var d = new Date();
  return todayStr() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
}

function getCurrentUser() {
  return viewMode === 'admin' ? '商品部' : '新宿東口店';
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
  { code: '10301', name: '新宿東口', shortCode: 'S01', area: '103' },
  { code: '10302', name: '池袋西口', shortCode: 'S02', area: '103' },
  { code: '10303', name: '横浜', shortCode: 'S03', area: '103' },
  { code: '10101', name: '札幌', shortCode: 'S04', area: '101' },
  { code: '10102', name: '函館', shortCode: 'S05', area: '101' },
  { code: '10201', name: '仙台', shortCode: 'S06', area: '102' },
  { code: '20101', name: '梅田', shortCode: 'S07', area: '201' },
  { code: '20102', name: '難波', shortCode: 'S08', area: '201' },
  { code: '20201', name: '広島', shortCode: 'S09', area: '202' }
];

function getShopName(code) {
  var shop = shops.find(function(s) { return s.code === code; });
  return shop ? shop.name : code;
}

// ===== Sample Data =====
// 新ステータス: 0=依頼中, 1=発注済, 2=配達中/修理待ち, 3=納品済/修理済, 4=完了
var storeOrders = [
  // ===== 修理 × 全5ステータス =====
  {
    id: 'REP-S01-20260301-0001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常',
    amount: null, status: STATUS.REQUESTING, date: '2026-03-01', shop: '10301',
    equipment: 'ランニングマシン TR-800', issue: 'ベルトが滑る。異音が発生。',
    unavailDates: ['2026-03-10（終日）', '2026-03-17（午前）'], unavailDays: ['火曜日', '木曜日'],
    photos: 2, estimateAmount: null, repairScheduleDate: '', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/03/01 09:15', user: '新宿東口店', memo: '' }]
  },
  {
    id: 'REP-S01-20260225-0001', type: 'repair', category: 'fitness', title: 'エアロバイク 表示パネル故障',
    amount: null, status: STATUS.ORDERED, date: '2026-02-25', shop: '10301',
    equipment: 'エアロバイク AB-200', issue: '液晶パネルが表示されない',
    unavailDates: ['2026-03-05（午前）'], unavailDays: [], photos: 1,
    estimateAmount: 35000, repairScheduleDate: '2026-03-15', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/25 09:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/27 15:00', user: '商品部', memo: '見積もり回答あり。35,000円。修理日は3/15で調整中。' }
    ]
  },
  {
    id: 'REP-S01-20260220-0001', type: 'repair', category: 'golf', title: 'パッティングマシン モーター異常',
    amount: null, status: STATUS.DELIVERING, date: '2026-02-20', shop: '10301',
    equipment: 'パッティングマシン PM-300', issue: 'モーターが回転しない',
    unavailDates: [], unavailDays: ['日曜日'], photos: 3,
    estimateAmount: 52000, repairScheduleDate: '2026-03-05', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/20 08:30', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/23 14:00', user: '商品部', memo: '見積52,000円。修理予定3/5。' },
      { status: 2, date: '2026/02/25 10:00', user: '商品部', memo: '修理業者手配完了。3/5に訪問予定。' }
    ]
  },
  {
    id: 'REP-S01-20260215-0001', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ',
    amount: null, status: STATUS.DELIVERED, date: '2026-02-15', shop: '10301',
    equipment: 'レッグプレス LP-400', issue: '油圧シリンダーから微量の漏れ',
    unavailDates: ['2026-02-28（終日）'], unavailDays: [], photos: 2,
    estimateAmount: 65000, repairScheduleDate: '2026-02-28', finalAmount: null, repairCompletedDate: '2026-02-28', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/15 08:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/18 14:00', user: '商品部', memo: '見積65,000円。2/28で修理予定。' },
      { status: 2, date: '2026/02/20 10:00', user: '商品部', memo: '修理業者手配完了。' },
      { status: 3, date: '2026/02/28 17:00', user: '新宿東口店', memo: '修理完了。正常稼働を確認しました。' }
    ]
  },
  {
    id: 'REP-S01-20260210-0001', type: 'repair', category: 'golf', title: 'ゴルフシミュレーター 映像不具合',
    amount: null, status: STATUS.COMPLETED, date: '2026-02-10', shop: '10301',
    equipment: 'ゴルフシミュレーター GS-Pro', issue: 'プロジェクターの映像がちらつく',
    unavailDates: [], unavailDays: ['月曜日'], photos: 1,
    estimateAmount: 45000, repairScheduleDate: '2026-02-20', finalAmount: 42000, repairCompletedDate: '2026-02-19', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/10 10:15', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/13 11:00', user: '商品部', memo: '見積45,000円。2/20で修理予定。' },
      { status: 2, date: '2026/02/14 10:00', user: '商品部', memo: '修理業者手配完了。' },
      { status: 3, date: '2026/02/19 16:00', user: '新宿東口店', memo: '修理完了。映像の乱れ解消を確認。' },
      { status: 4, date: '2026/02/20 10:00', user: '商品部', memo: '最終金額42,000円で確定。部品代差引。' }
    ]
  },
  // ===== 備品 × 全5ステータス =====
  {
    id: 'EQU-S01-20260302-0001', type: 'equipment', category: 'fitness', title: 'トレーニングマット × 5',
    amount: 17500, status: STATUS.REQUESTING, date: '2026-03-02', shop: '10301',
    equipDetails: [
      { name: 'トレーニングマット', code: 'MAT-001', price: 3500, qty: 5, supplier: 'フィットネスジャパン', arrivalDate: '' }
    ],
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [{ status: 0, date: '2026/03/02 14:30', user: '新宿東口店', memo: '' }]
  },
  {
    id: 'EQU-S01-20260226-0001', type: 'equipment', category: 'golf', title: 'ゴルフボール 他1商品',
    amount: 72000, status: STATUS.ORDERED, date: '2026-02-26', shop: '10301',
    equipDetails: [
      { name: 'ゴルフボール 1ダース', code: 'GB-012', price: 4200, qty: 10, supplier: 'ゴルフサプライ', arrivalDate: '2026-03-05' },
      { name: 'グローブ Lサイズ', code: 'GL-L01', price: 1500, qty: 20, supplier: 'ゴルフサプライ', arrivalDate: '2026-03-05' }
    ],
    estimateAmount: 72000, finalAmount: null, deliveryDate: '2026-03-05', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/26 10:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/27 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。見積金額: ¥72,000' }
    ]
  },
  {
    id: 'EQU-S01-20260222-0001', type: 'equipment', category: 'fitness', title: 'バランスボール × 3',
    amount: 5400, status: STATUS.DELIVERING, date: '2026-02-22', shop: '10301',
    equipDetails: [
      { name: 'バランスボール 65cm', code: 'BB-065', price: 1800, qty: 3, supplier: 'スポーツ用品販売', arrivalDate: '2026-03-01' }
    ],
    estimateAmount: 5400, finalAmount: null, deliveryDate: '2026-03-01', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/22 10:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/23 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' },
      { status: 2, date: '2026/02/24 00:00', user: 'システム（自動:締め日翌日）', memo: '締め日翌日により自動遷移。納品予定日: 3/1' }
    ]
  },
  {
    id: 'EQU-S01-20260218-0001', type: 'equipment', category: 'fitness', title: 'タオル（大）10枚セット × 3',
    amount: 16800, status: STATUS.DELIVERED, date: '2026-02-18', shop: '10301',
    equipDetails: [{ name: 'タオル（大）10枚セット', code: 'TW-L10', price: 5600, qty: 3, supplier: 'リネンサービス', arrivalDate: '2026-02-25' }],
    estimateAmount: 16800, finalAmount: null, deliveryDate: '2026-02-25', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/18 09:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/19 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' },
      { status: 2, date: '2026/02/20 00:00', user: 'システム（自動:締め日翌日）', memo: '自動遷移。' },
      { status: 3, date: '2026/02/25 00:00', user: 'システム（自動:納品予定日）', memo: '納品予定日により自動遷移。' }
    ]
  },
  {
    id: 'EQU-S01-20260212-0001', type: 'equipment', category: 'golf', title: 'スコアカード 100枚 × 5',
    amount: 6000, status: STATUS.COMPLETED, date: '2026-02-12', shop: '10301',
    equipDetails: [{ name: 'スコアカード 100枚', code: 'SC-100', price: 1200, qty: 5, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-19' }],
    estimateAmount: 6000, finalAmount: 6000, deliveryDate: '2026-02-19', actualDeliveryDate: '2026-02-19', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/12 09:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/13 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' },
      { status: 2, date: '2026/02/14 00:00', user: 'システム（自動:締め日翌日）', memo: '自動遷移。' },
      { status: 3, date: '2026/02/19 00:00', user: 'システム（自動:納品予定日）', memo: '納品予定日により自動遷移。' },
      { status: 4, date: '2026/02/20 00:00', user: 'システム（自動:納品予定日翌日）', memo: '納品予定日翌日により自動完了。見積金額を最終金額に適用。' }
    ]
  },
  // ===== 部品 × 全5ステータス =====
  {
    id: 'PTS-S01-20260303-0001', type: 'parts', category: 'golf', title: 'スイング診断機 センサー交換部品',
    amount: null, status: STATUS.REQUESTING, date: '2026-03-03', shop: '10301',
    partsName: 'センサーユニット SU-100', targetEquip: 'スイング診断機 GST-7',
    reason: 'センサー応答が遅くなっている', quantity: 1, partsPhotos: 2,
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [{ status: 0, date: '2026/03/03 11:20', user: '新宿東口店', memo: '' }]
  },
  {
    id: 'PTS-S01-20260227-0001', type: 'parts', category: 'fitness', title: 'エアロバイク ペダル交換部品',
    amount: null, status: STATUS.ORDERED, date: '2026-02-27', shop: '10301',
    partsName: 'ペダルユニット PD-200', targetEquip: 'エアロバイク AB-150',
    reason: 'ペダル軸の摩耗', quantity: 2, partsPhotos: 1,
    estimateAmount: 8500, finalAmount: null, deliveryDate: '2026-03-15', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/27 09:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/03/01 11:00', user: '商品部', memo: '8,500円で発注確定。3/15納品予定。' }
    ]
  },
  {
    id: 'PTS-S01-20260221-0001', type: 'parts', category: 'golf', title: 'スイングカメラ レンズユニット',
    amount: null, status: STATUS.DELIVERING, date: '2026-02-21', shop: '10301',
    partsName: 'レンズユニット LC-300', targetEquip: 'スイングカメラ SC-200',
    reason: 'レンズに傷。映像にノイズ', quantity: 1, partsPhotos: 3,
    estimateAmount: 12000, finalAmount: null, deliveryDate: '2026-03-10', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/21 11:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/24 14:00', user: '商品部', memo: '12,000円で発注確定。' },
      { status: 2, date: '2026/02/26 10:00', user: '商品部', memo: '配達手配完了。3/10納品予定。' }
    ]
  },
  {
    id: 'PTS-S01-20260216-0001', type: 'parts', category: 'fitness', title: 'トレッドミル ベルト交換部品',
    amount: null, status: STATUS.DELIVERED, date: '2026-02-16', shop: '10301',
    partsName: 'ベルトユニット BT-100', targetEquip: 'トレッドミル TM-500',
    reason: 'ベルトの摩耗が進行', quantity: 1, partsPhotos: 1,
    estimateAmount: 18000, finalAmount: null, deliveryDate: '2026-02-28', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/16 10:00', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/19 11:00', user: '商品部', memo: '18,000円で発注確定。' },
      { status: 2, date: '2026/02/21 10:00', user: '商品部', memo: '配達手配完了。2/28納品予定。' },
      { status: 3, date: '2026/02/28 14:00', user: '新宿東口店', memo: '部品受領。問題なし。' }
    ]
  },
  {
    id: 'PTS-S01-20260211-0001', type: 'parts', category: 'golf', title: 'パッティングマシン センサー部品',
    amount: null, status: STATUS.COMPLETED, date: '2026-02-11', shop: '10301',
    partsName: 'モーターユニット MU-200', targetEquip: 'パッティングマシン PM-300',
    reason: 'モーター回転不良の予防交換', quantity: 1, partsPhotos: 0,
    estimateAmount: 25000, finalAmount: 25000, deliveryDate: '2026-02-20', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/11 09:30', user: '新宿東口店', memo: '' },
      { status: 1, date: '2026/02/14 10:00', user: '商品部', memo: '25,000円で発注確定。' },
      { status: 2, date: '2026/02/16 10:00', user: '商品部', memo: '配達手配完了。2/20納品予定。' },
      { status: 3, date: '2026/02/20 15:00', user: '新宿東口店', memo: '部品受領。' },
      { status: 4, date: '2026/02/21 10:00', user: '商品部', memo: '最終金額25,000円で確定。' }
    ]
  }
];

var adminOrders = storeOrders.concat([
  // 札幌店
  {
    id: 'REP-S04-20260223-0001', type: 'repair', category: 'fitness', title: 'トレッドミル 異音発生',
    amount: null, status: STATUS.REQUESTING, date: '2026-02-23', shop: '10101',
    equipment: 'トレッドミル TM-500', issue: '動作時に異音が発生',
    unavailDates: ['2026-03-07（終日）'], unavailDays: ['土曜日'], photos: 0,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/23 13:00', user: '札幌店', memo: '' }]
  },
  {
    id: 'EQU-S04-20260224-0001', type: 'equipment', category: 'fitness', title: 'ダンベルセット 10kg × 3',
    amount: 25200, status: STATUS.ORDERED, date: '2026-02-24', shop: '10101',
    equipDetails: [{ name: 'ダンベルセット 10kg', code: 'DB-010', price: 8400, qty: 3, supplier: 'フィットネスジャパン', arrivalDate: '2026-02-28' }],
    estimateAmount: 25200, finalAmount: null, deliveryDate: '2026-03-05', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/24 10:00', user: '札幌店', memo: '' },
      { status: 1, date: '2026/02/26 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' }
    ]
  },
  // 函館店
  {
    id: 'PTS-S05-20260222-0001', type: 'parts', category: 'fitness', title: 'エアロバイク ペダル交換部品',
    amount: null, status: STATUS.DELIVERING, date: '2026-02-22', shop: '10102',
    partsName: 'ペダルユニット PD-200', targetEquip: 'エアロバイク AB-150',
    reason: 'ペダル軸の摩耗', quantity: 2, partsPhotos: 1,
    estimateAmount: 8500, finalAmount: null, deliveryDate: '2026-03-10', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/22 09:00', user: '函館店', memo: '' },
      { status: 1, date: '2026/02/23 11:00', user: '商品部', memo: '8,500円で発注確定。' },
      { status: 2, date: '2026/02/24 10:00', user: '商品部', memo: '配達手配完了。3/10納品予定。' }
    ]
  },
  // 池袋西口店
  {
    id: 'EQU-S02-20260225-0001', type: 'equipment', category: 'golf', title: 'グローブ Lサイズ 他1商品',
    amount: 38000, status: STATUS.ORDERED, date: '2026-02-25', shop: '10302',
    equipDetails: [
      { name: 'グローブ Lサイズ', code: 'GL-L01', price: 1500, qty: 20, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-28' },
      { name: 'ゴルフティー 100本入り', code: 'GT-100', price: 800, qty: 10, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-28' }
    ],
    estimateAmount: 38000, finalAmount: null, deliveryDate: '2026-03-06', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/25 08:30', user: '池袋西口店', memo: '' },
      { status: 1, date: '2026/02/26 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' }
    ]
  },
  {
    id: 'REP-S02-20260224-0001', type: 'repair', category: 'golf', title: 'シミュレーター プロジェクター不具合',
    amount: null, status: STATUS.REQUESTING, date: '2026-02-24', shop: '10302',
    equipment: 'ゴルフシミュレーター GS-Pro', issue: 'プロジェクターの映像がちらつく',
    unavailDates: ['2026-03-10（午後）'], unavailDays: ['月曜日'], photos: 1,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/24 10:15', user: '池袋西口店', memo: '' }]
  },
  // 横浜店
  {
    id: 'EQU-S03-20260220-0001', type: 'equipment', category: 'fitness', title: 'タオル（大）10枚セット × 3',
    amount: 16800, status: STATUS.DELIVERED, date: '2026-02-20', shop: '10303',
    equipDetails: [{ name: 'タオル（大）10枚セット', code: 'TW-L10', price: 5600, qty: 3, supplier: 'リネンサービス', arrivalDate: '2026-02-28' }],
    estimateAmount: 16800, finalAmount: null, deliveryDate: '2026-02-28', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/20 09:00', user: '横浜店', memo: '' },
      { status: 1, date: '2026/02/21 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' },
      { status: 2, date: '2026/02/22 00:00', user: 'システム（自動:締め日翌日）', memo: '自動遷移。' },
      { status: 3, date: '2026/02/28 00:00', user: 'システム（自動:納品予定日）', memo: '納品予定日により自動遷移。' }
    ]
  },
  // 仙台店
  {
    id: 'REP-S06-20260219-0001', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ',
    amount: null, status: STATUS.DELIVERING, date: '2026-02-19', shop: '10201',
    equipment: 'レッグプレス LP-400', issue: '油圧シリンダーから微量の漏れ',
    unavailDates: ['2026-03-20（終日）'], unavailDays: [], photos: 2,
    estimateAmount: 65000, repairScheduleDate: '2026-03-15', finalAmount: null, repairCompletedDate: '', deliveryDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/19 08:00', user: '仙台店', memo: '' },
      { status: 1, date: '2026/02/23 14:00', user: '商品部', memo: '見積65,000円。3/15で修理予定。' },
      { status: 2, date: '2026/02/24 10:00', user: '商品部', memo: '修理業者手配完了。' }
    ]
  },
  // 梅田店
  {
    id: 'EQU-S07-20260217-0001', type: 'equipment', category: 'golf', title: 'スコアカード 100枚 × 5',
    amount: 6000, status: STATUS.COMPLETED, date: '2026-02-17', shop: '20101',
    equipDetails: [{ name: 'スコアカード 100枚', code: 'SC-100', price: 1200, qty: 5, supplier: 'ゴルフサプライ', arrivalDate: '2026-02-21' }],
    estimateAmount: 6000, finalAmount: 6000, deliveryDate: '2026-02-21', actualDeliveryDate: '2026-02-21', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/17 09:00', user: '梅田店', memo: '' },
      { status: 1, date: '2026/02/18 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' },
      { status: 2, date: '2026/02/19 00:00', user: 'システム（自動:締め日翌日）', memo: '自動遷移。' },
      { status: 3, date: '2026/02/21 00:00', user: 'システム（自動:納品予定日）', memo: '納品予定日により自動遷移。' },
      { status: 4, date: '2026/02/22 00:00', user: 'システム（自動:納品予定日翌日）', memo: '納品予定日翌日により自動完了。見積金額を最終金額に適用。' }
    ]
  },
  {
    id: 'PTS-S07-20260226-0001', type: 'parts', category: 'golf', title: 'スイングカメラ レンズユニット',
    amount: null, status: STATUS.REQUESTING, date: '2026-02-26', shop: '20101',
    partsName: 'レンズユニット LC-300', targetEquip: 'スイングカメラ SC-200',
    reason: 'レンズに傷。映像にノイズ', quantity: 1, partsPhotos: 3,
    estimateAmount: null, finalAmount: null, deliveryDate: '', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [{ status: 0, date: '2026/02/26 11:00', user: '梅田店', memo: '' }]
  },
  // 難波店
  {
    id: 'REP-S08-20260226-0001', type: 'repair', category: 'fitness', title: 'ランニングマシン 速度制御不良',
    amount: null, status: STATUS.REQUESTING, date: '2026-02-26', shop: '20102',
    equipment: 'ランニングマシン TR-900', issue: '速度が安定しない',
    unavailDates: [], unavailDays: ['水曜日', '金曜日'], photos: 0,
    estimateAmount: null, finalAmount: null, repairScheduleDate: '', repairCompletedDate: '', deliveryDate: '',
    statusHistory: [{ status: 0, date: '2026/02/26 08:45', user: '難波店', memo: '' }]
  },
  // 広島店
  {
    id: 'EQU-S09-20260225-0001', type: 'equipment', category: 'fitness', title: 'ヨガマット × 10',
    amount: 15000, status: STATUS.ORDERED, date: '2026-02-25', shop: '20201',
    equipDetails: [{ name: 'ヨガマット', code: 'YM-001', price: 1500, qty: 10, supplier: 'フィットネスジャパン', arrivalDate: '2026-02-28' }],
    estimateAmount: 15000, finalAmount: null, deliveryDate: '2026-03-06', repairScheduleDate: '', repairCompletedDate: '',
    statusHistory: [
      { status: 0, date: '2026/02/25 09:30', user: '広島店', memo: '' },
      { status: 1, date: '2026/02/26 00:00', user: 'システム（自動:締め日）', memo: '締め日により自動発注。' }
    ]
  }
]);

var expandedIds = {};

// ===== Init =====
function initView() {
  document.getElementById('storeFilterBar').style.display = viewMode === 'store' ? '' : 'none';
  document.getElementById('storeActionBar').style.display = viewMode === 'store' ? 'flex' : 'none';
  document.getElementById('adminFilterBar').style.display = viewMode === 'admin' ? 'block' : 'none';
  document.getElementById('adminActionBar').style.display = viewMode === 'admin' ? 'flex' : 'none';
  renderTableHeader();
  renderOrders();
}

// ===== Table Header =====
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

// ===== Cascading Filters =====
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

  // 発注日の新しい順にソート
  filtered.sort(function(a, b) { return b.date.localeCompare(a.date); });

  var colSpan = viewMode === 'admin' ? 11 : 10;
  var tbody = document.getElementById('orderTableBody');
  var html = '';

  filtered.forEach(function(o) {
    var typeClass = 'type-' + o.type;
    var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
    var statusClass = getStatusClass(o.status, o.type);
    var statusLabel = getStatusLabel(o.status, o.type);
    var catLabel = o.category === 'fitness' ? 'フィットネス' : 'ゴルフ';
    var displayAmount = getDisplayAmount(o);
    var isOpen = !!expandedIds[o.id];

    var orderCount = 1;
    var contentLabel = o.title;
    if (o.type === 'repair') {
      contentLabel = o.issue || o.title;
    } else if (o.type === 'parts') {
      contentLabel = o.partsName || o.title;
    } else if (o.type === 'equipment' && o.equipDetails) {
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
      '<td class="td-content">' + contentLabel + '</td>' +
      '<td>' + orderCount + '</td>' +
      '<td>' + displayAmount + '</td>' +
      '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
      '<td>' + o.date + '</td>' +
      '<td><button class="btn-sm" onclick="toggleDetail(\'' + o.id + '\')">' + (isOpen ? '−' : '+') + '</button></td>' +
    '</tr>';

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

function getDisplayAmount(o) {
  if (o.finalAmount) return '¥' + o.finalAmount.toLocaleString();
  if (o.estimateAmount) return '¥' + o.estimateAmount.toLocaleString();
  if (o.type === 'repair' || o.type === 'parts') return '—';
  if (o.amount) return '¥' + o.amount.toLocaleString();
  return '—';
}

// ===== Detail Content =====
function renderDetailContent(o) {
  var html = '';

  if (viewMode === 'admin') {
    html += '<div style="margin-bottom:12px;"><span class="detail-label">店舗</span> <span class="detail-value" style="font-weight:600;">' + o.shop + ':' + getShopName(o.shop) + '</span></div>';
  }

  html += '<div class="detail-two-col">';

  // Left: 依頼内容（読み取り専用）
  html += '<div>';
  html += '<div class="detail-section-title store-info">店舗からの依頼内容</div>';

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

  // Right: 対応情報 + 履歴 + アクション
  html += '<div>';
  var canEdit = canEditResponseInfo(o);
  html += '<div class="detail-section-title response-info" style="justify-content:space-between;">' +
    '対応情報' +
    (canEdit ? '<button class="btn-edit-info" onclick="openEditInfoModal(\'' + o.id + '\')" title="対応情報を編集"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.5 1.5l3 3L5 14H2v-3L11.5 1.5z"/></svg></button>' : '') +
    '</div>';

  html += '<div class="response-info-card"><div class="detail-grid">';
  if (o.type === 'repair') {
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理予定日</div><div class="detail-value">' + (o.repairScheduleDate || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理完了日</div><div class="detail-value">' + (o.repairCompletedDate || '—') + '</div></div>';
  } else if (o.type === 'equipment') {
    var equipEstimate = o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : (o.amount ? '¥' + o.amount.toLocaleString() : '—');
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + equipEstimate + '</div></div>';
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + (o.deliveryDate || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">納品日</div><div class="detail-value">' + (o.actualDeliveryDate || '—') + '</div></div>';
  } else {
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimateAmount ? '¥' + o.estimateAmount.toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + (o.deliveryDate || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.finalAmount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.finalAmount ? '¥' + o.finalAmount.toLocaleString() : '—') + '</div></div>';
  }
  html += '</div></div>';

  html += renderStatusHistory(o);
  html += renderActionButton(o);

  html += '</div>';
  html += '</div>';
  return html;
}

function renderPhotos(count, label) {
  var html = '<div class="photo-section"><div class="detail-label">' + label + '（' + count + '枚）</div><div class="photo-grid">';
  for (var i = 0; i < count; i++) {
    html += '<div class="photo-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg><span>写真 ' + (i + 1) + '</span></div>';
  }
  html += '</div></div>';
  return html;
}

// ===== Status History =====
function renderStatusHistory(o) {
  var html = '<div class="status-history"><div class="status-history-title">ステータス履歴</div>';
  html += '<div class="status-timeline">';
  var history = o.statusHistory || [];
  for (var i = history.length - 1; i >= 0; i--) {
    var h = history[i];
    var isCurrent = (i === history.length - 1);
    html += '<div class="timeline-item">';
    html += '<div class="timeline-dot ' + (isCurrent ? 'current' : 'past') + '"></div>';
    html += '<div class="timeline-status ' + (isCurrent ? 'current' : 'past') + '">' + getStatusLabel(h.status, o.type) + (isCurrent ? ' ← 現在' : '') + '</div>';
    html += '<div class="timeline-meta">' + h.date + ' — ' + h.user + '</div>';
    if (h.memo) {
      html += '<div class="timeline-memo">' + h.memo + '</div>';
    }
    html += '</div>';
  }
  html += '</div></div>';
  return html;
}

// ===== Action Button =====
// 各ステータスで誰がどのアクションを取れるかを定義
function renderActionButton(o) {
  var html = '<div class="detail-actions">';
  var action = getAvailableAction(o);

  if (action) {
    html += '<button class="btn-sm ' + action.btnClass + '" onclick="openStatusModal(\'' + o.id + '\', \'' + action.key + '\')">' + action.label + '</button>';
  } else {
    html += '<span style="font-size:12px;color:#94a3b8;">' + getWaitingMessage(o) + '</span>';
  }

  html += '</div>';
  return html;
}

function getAvailableAction(o) {
  // ①依頼中 → ②発注済: 商品部（全種別）。備品は自動もあるが手動ボタンも提供
  if (o.status === STATUS.REQUESTING && viewMode === 'admin') {
    return { key: 'order', label: '発注済にする', btnClass: 'btn-sm-primary' };
  }

  // ②発注済 → ③配達中/修理待ち: 備品は自動のみ、部品・修理は商品部が手動
  if (o.status === STATUS.ORDERED && viewMode === 'admin' && o.type !== 'equipment') {
    var label = o.type === 'repair' ? '修理待ちにする' : '配達中にする';
    return { key: 'to-delivering', label: label, btnClass: 'btn-sm-primary' };
  }

  // ③修理待ち → ④修理済: 店舗が手動
  if (o.status === STATUS.DELIVERING && o.type === 'repair' && viewMode === 'store') {
    return { key: 'repair-done', label: '修理完了報告', btnClass: 'btn-sm-pink' };
  }

  // ③配達中 → ④納品済: 備品は自動もあるが店舗も手動可、部品は店舗が手動
  if (o.status === STATUS.DELIVERING && o.type !== 'repair' && viewMode === 'store') {
    return { key: 'delivery-done', label: '納品済にする', btnClass: 'btn-sm-pink' };
  }

  // ④納品済/修理済 → ⑤完了: 商品部が手動（備品は自動もあり）
  if (o.status === STATUS.DELIVERED && viewMode === 'admin') {
    return { key: 'complete', label: '完了にする', btnClass: 'btn-sm-success' };
  }

  return null;
}

function getWaitingMessage(o) {
  if (o.status === STATUS.COMPLETED) {
    return o.type === 'repair' ? '修理完了' : '納品完了';
  }
  if (o.status === STATUS.REQUESTING && viewMode === 'store') {
    return '本部対応待ち';
  }
  if (o.status === STATUS.ORDERED) {
    if (o.type === 'equipment') {
      return viewMode === 'store' ? '配達待ち（自動遷移）' : '自動遷移待ち（締め日翌日）';
    }
    return viewMode === 'store' ? '本部対応待ち' : '—';
  }
  if (o.status === STATUS.DELIVERING) {
    if (o.type === 'repair') {
      return viewMode === 'admin' ? '店舗の修理完了報告待ち' : '—';
    }
    return viewMode === 'admin' ? '店舗の納品確認待ち' : '—';
  }
  if (o.status === STATUS.DELIVERED) {
    return viewMode === 'store' ? '本部の最終確認待ち' : '—';
  }
  return '—';
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

  if (action === 'order') {
    // ①依頼中 → ②発注済
    title.textContent = '発注済にする';
    var isRepair = order.type === 'repair';
    var isEquipment = order.type === 'equipment';
    var dateLabel = isRepair ? '修理予定日' : '納品予定日';

    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">見積金額 <span class="required">*</span></span><input class="modal-input" id="modalAmount" type="text" inputmode="numeric" placeholder="金額を入力"></div>' +
      '<div class="modal-row"><span class="modal-label">' + dateLabel + '</span><input class="modal-input" id="modalDate" type="date"></div>' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-primary" onclick="doOrder(\'' + orderId + '\')">発注済にする</button>';

    if (isEquipment && order.equipDetails) {
      var total = 0;
      order.equipDetails.forEach(function(d) { total += d.price * d.qty; });
      setTimeout(function() {
        document.getElementById('modalAmount').value = total;
        document.getElementById('modalDate').value = getEquipmentDeliveryDate();
      }, 0);
    }

  } else if (action === 'to-delivering') {
    // ②発注済 → ③配達中/修理待ち（部品・修理: 商品部が手動）
    var nextLabel = order.type === 'repair' ? '修理待ち' : '配達中';
    title.textContent = nextLabel + 'にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">内容</span><input class="modal-input readonly" value="' + order.title + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-primary" onclick="doToDelivering(\'' + orderId + '\')">' + nextLabel + 'にする</button>';

  } else if (action === 'repair-done') {
    // ③修理待ち → ④修理済（店舗が手動）
    title.textContent = '修理完了報告';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">機材名</span><input class="modal-input readonly" value="' + (order.equipment || '') + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-info">修理が完了し、機材が正常に稼働していることを確認してから報告してください。</div>' +
      '<div class="modal-row"><span class="modal-label">修理完了日 <span class="required">*</span></span><input class="modal-input" id="modalRepairDate" type="date"></div>' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="稼働状況や備考"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-pink" onclick="doRepairDone(\'' + orderId + '\')">修理完了報告</button>';

  } else if (action === 'delivery-done') {
    // ③配達中 → ④納品済（店舗が手動: 備品・部品）
    title.textContent = '納品済にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">内容</span><input class="modal-input readonly" value="' + order.title + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-pink" onclick="doDeliveryDone(\'' + orderId + '\')">納品済にする</button>';

  } else if (action === 'complete') {
    // ④納品済/修理済 → ⑤完了（商品部が手動）
    title.textContent = '完了にする';
    var estAmt = order.estimateAmount || order.amount || 0;
    var isRepairComplete = order.type === 'repair';
    var amountRequired = isRepairComplete;
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">見積額</span><input class="modal-input readonly" value="¥' + estAmt.toLocaleString() + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">最終金額' + (amountRequired ? ' <span class="required">*</span>' : '') + '</span><input class="modal-input" id="modalFinalAmount" type="text" inputmode="numeric" placeholder="' + (amountRequired ? '最終金額を入力' : '未入力で見積金額を適用') + '" oninput="updateDiff(' + estAmt + ')"></div>' +
      '<div class="modal-diff" id="modalDiffRow" style="display:none;"><span class="modal-diff-label">差額</span><span class="modal-diff-value" id="modalDiffValue"></span></div>' +
      (order.type === 'equipment' ? '<div class="modal-row"><span class="modal-label">納品日</span><input class="modal-input" id="modalActualDeliveryDate" type="date"><div style="font-size:11px;color:#94a3b8;margin-top:2px;">未入力で納品予定日を適用</div></div>' : '') +
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

// ①依頼中 → ②発注済
function doOrder(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var amountInput = document.getElementById('modalAmount');
  var dateInput = document.getElementById('modalDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  var amount = parseInt(amountInput.value);
  if (isNaN(amount) || amount <= 0) {
    alert('見積金額を入力してください');
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

// ②発注済 → ③配達中/修理待ち（部品・修理: 商品部が手動）
function doToDelivering(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  order.status = STATUS.DELIVERING;
  order.statusHistory.push({ status: STATUS.DELIVERING, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

// ③修理待ち → ④修理済（店舗が手動）
function doRepairDone(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var dateInput = document.getElementById('modalRepairDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  if (!dateInput.value) {
    alert('修理完了日を入力してください');
    return;
  }

  order.repairCompletedDate = dateInput.value;
  order.status = STATUS.DELIVERED;
  order.statusHistory.push({ status: STATUS.DELIVERED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

// ③配達中 → ④納品済（店舗が手動: 備品・部品）
function doDeliveryDone(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  order.status = STATUS.DELIVERED;
  order.statusHistory.push({ status: STATUS.DELIVERED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

// ④→⑤ 完了（商品部が手動）
function doComplete(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  var finalInput = document.getElementById('modalFinalAmount');
  var finalAmount = parseInt(finalInput.value);

  if (order.type === 'repair') {
    // 修理: 最終金額は必須
    if (isNaN(finalAmount) || finalAmount <= 0) {
      alert('最終金額を入力してください');
      return;
    }
    order.finalAmount = finalAmount;
  } else {
    // 備品・部品: 最終金額は任意（未入力なら見積金額を適用）
    order.finalAmount = (!isNaN(finalAmount) && finalAmount > 0) ? finalAmount : (order.estimateAmount || order.amount || 0);
  }

  if (order.type === 'equipment') {
    var actualDateInput = document.getElementById('modalActualDeliveryDate');
    order.actualDeliveryDate = (actualDateInput && actualDateInput.value) ? actualDateInput.value : (order.deliveryDate || '');
  }

  order.status = STATUS.COMPLETED;
  order.statusHistory.push({ status: STATUS.COMPLETED, date: nowStr(), user: getCurrentUser(), memo: memo });
  closeModal();
  renderOrders();
}

// ===== Edit Response Info =====
function canEditResponseInfo(o) {
  if (o.status === STATUS.REQUESTING) return false;
  if (viewMode === 'admin') {
    return o.status >= STATUS.ORDERED;
  }
  // 店舗: 修理済（自分が報告した情報）のみ編集可能
  return o.type === 'repair' && o.status === STATUS.DELIVERED;
}

function getEditableFields(o) {
  var fields = [];
  if (viewMode === 'admin') {
    if (o.status >= STATUS.ORDERED && o.status < STATUS.COMPLETED) {
      if (o.type === 'repair') {
        fields.push({ key: 'estimateAmount', label: '見積金額', type: 'number', value: o.estimateAmount });
        fields.push({ key: 'repairScheduleDate', label: '修理予定日', type: 'date', value: o.repairScheduleDate });
      } else {
        fields.push({ key: 'estimateAmount', label: '見積金額', type: 'number', value: o.estimateAmount || o.amount });
        fields.push({ key: 'deliveryDate', label: '納品予定日', type: 'date', value: o.deliveryDate });
      }
      fields.push({ key: 'finalAmount', label: '最終金額', type: 'number', value: o.finalAmount });
      fields.push({ key: 'memo_current', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, o.status) });
    } else if (o.status === STATUS.COMPLETED) {
      fields.push({ key: 'finalAmount', label: '最終金額', type: 'number', value: o.finalAmount });
      if (o.type === 'equipment') {
        fields.push({ key: 'actualDeliveryDate', label: '納品日', type: 'date', value: o.actualDeliveryDate });
      }
      fields.push({ key: 'memo_completed', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.COMPLETED) });
    }
  } else {
    if (o.type === 'repair' && o.status === STATUS.DELIVERED) {
      fields.push({ key: 'repairCompletedDate', label: '修理完了日', type: 'date', value: o.repairCompletedDate });
      fields.push({ key: 'memo_repaired', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.DELIVERED) });
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

function bulkStatusChange() {
  var checked = getCheckedOrders();
  if (checked.length === 0) {
    alert('対象の発注をチェックしてください。');
    return;
  }

  var modal = document.getElementById('modalOverlay');
  var title = document.getElementById('modalTitle');
  var body = document.getElementById('modalBody');
  var footer = document.getElementById('modalFooter');

  // チェックされた発注のステータス分布を集計
  var byStatus = {};
  checked.forEach(function(o) {
    var key = o.status;
    if (!byStatus[key]) byStatus[key] = [];
    byStatus[key].push(o);
  });

  // 一括変更可能なアクションを提示
  var actions = [];
  if (byStatus[STATUS.REQUESTING]) {
    actions.push({ status: STATUS.REQUESTING, nextStatus: STATUS.ORDERED, label: '依頼中 → 発注済', count: byStatus[STATUS.REQUESTING].length });
  }
  if (byStatus[STATUS.ORDERED]) {
    var nonEquip = byStatus[STATUS.ORDERED].filter(function(o) { return o.type !== 'equipment'; });
    if (nonEquip.length > 0) {
      actions.push({ status: STATUS.ORDERED, nextStatus: STATUS.DELIVERING, label: '発注済 → 配達中/修理待ち', count: nonEquip.length, filter: function(o) { return o.type !== 'equipment'; } });
    }
  }
  if (byStatus[STATUS.DELIVERED]) {
    actions.push({ status: STATUS.DELIVERED, nextStatus: STATUS.COMPLETED, label: '納品済/修理済 → 完了', count: byStatus[STATUS.DELIVERED].length });
  }

  if (actions.length === 0) {
    alert('選択された発注に一括変更可能なものがありません。');
    return;
  }

  title.textContent = 'ステータス一括変更';

  var listHtml = '<div class="modal-info">チェックされた <strong>' + checked.length + '件</strong> から一括変更可能なアクションを選択してください。</div>';

  listHtml += '<div style="margin-bottom:12px;">';
  actions.forEach(function(a, idx) {
    listHtml += '<div style="padding:8px 12px;margin-bottom:6px;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;' +
      (idx === 0 ? 'background:#eff6ff;border-color:#3b82f6;' : '') + '" ' +
      'onclick="selectBulkAction(this, ' + idx + ')" class="bulk-action-option" data-index="' + idx + '">' +
      '<strong>' + a.label + '</strong> <span style="color:#64748b;font-size:12px;">（' + a.count + '件）</span></div>';
  });
  listHtml += '</div>';

  listHtml += '<input type="hidden" id="bulkActionIndex" value="0">';
  listHtml += '<hr class="modal-divider">';
  listHtml += '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力（全件共通）"></textarea></div>';

  body.innerHTML = listHtml;
  // Store actions data for use in execute
  window._bulkActions = actions;

  footer.innerHTML =
    '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
    '<button class="btn-modal btn-modal-primary" onclick="doBulkStatusChange()">一括変更</button>';
  modal.classList.add('open');
}

function selectBulkAction(el, idx) {
  document.querySelectorAll('.bulk-action-option').forEach(function(opt) {
    opt.style.background = '#fff';
    opt.style.borderColor = '#e2e8f0';
  });
  el.style.background = '#eff6ff';
  el.style.borderColor = '#3b82f6';
  document.getElementById('bulkActionIndex').value = idx;
}

function doBulkStatusChange() {
  var idx = parseInt(document.getElementById('bulkActionIndex').value);
  var actionDef = window._bulkActions[idx];
  if (!actionDef) return;

  var checked = getCheckedOrders();
  var targets = checked.filter(function(o) {
    if (o.status !== actionDef.status) return false;
    if (actionDef.filter && !actionDef.filter(o)) return false;
    return true;
  });

  var memo = (document.getElementById('modalMemo') || {}).value || '';
  var ts = nowStr();
  var user = getCurrentUser();

  targets.forEach(function(o) {
    // 依頼中→発注済の場合、見積金額が必要だがバルクでは省略（備品の自動発注シミュレーション）
    if (actionDef.nextStatus === STATUS.ORDERED && o.type === 'equipment' && o.equipDetails) {
      var total = 0;
      o.equipDetails.forEach(function(d) { total += d.price * d.qty; });
      o.estimateAmount = total;
      o.deliveryDate = o.deliveryDate || getEquipmentDeliveryDate();
    }
    // 納品済/修理済→完了の場合、最終金額が未入力なら見積金額を適用
    if (actionDef.nextStatus === STATUS.COMPLETED && !o.finalAmount) {
      o.finalAmount = o.estimateAmount || o.amount || 0;
    }
    o.status = actionDef.nextStatus;
    o.statusHistory.push({ status: actionDef.nextStatus, date: ts, user: user, memo: memo });
  });

  closeModal();
  var selectAll = document.getElementById('selectAll');
  if (selectAll) selectAll.checked = false;
  renderOrders();
}

// ===== Init =====
initView();
