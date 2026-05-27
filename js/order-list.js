// ===== Role Detection (from API via userLoaded event) =====
var viewMode = 'store';
var currentUser = null;

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

// ===== Categories Master（締めルール参照用） =====
var categoriesMap = {}; // code -> { closing_type, closing_day }

// ===== Date Helpers =====
// 備品の納品予定日デフォルト値
// カテゴリの締めルールから「次の締め日 + 4日」を返す（配達に約4日かかる前提）。
// setup/auto_advance_status.php の 1→2 自動遷移フォールバック値（締め日+4日）と同じロジック。
// none の場合は空文字を返し、admin に手入力させる。
function getEquipmentDeliveryDate(order) {
  if (!order || !order.category_code) return '';
  var closing = categoriesMap[order.category_code];
  if (!closing) return '';
  var today = new Date();

  if (closing.closing_type === 'monthly') {
    var day = today.getDate();
    var year = today.getFullYear();
    var month = today.getMonth(); // 0-based
    if (day > closing.closing_day) month += 1;
    return formatDate(new Date(year, month, closing.closing_day + 4));
  }

  if (closing.closing_type === 'weekly') {
    var currentDow = today.getDay();
    var daysUntil = ((closing.closing_day - currentDow + 7) % 7) + 4;
    var d = new Date(today);
    d.setDate(today.getDate() + daysUntil);
    return formatDate(d);
  }

  return '';
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
  return currentUser ? currentUser.name : '';
}

// ===== Zone / Area / Shop Master (populated from API) =====
var zones = [];
var areas = [];
var shops = [];

function getShopName(code) {
  var shop = shops.find(function(s) { return s.shop_code === code; });
  return shop ? shop.shop_name : code;
}

// ===== Order Data (populated from API) =====
var currentOrders = [];
var expandedIds = {};
var selectedIds = {}; // 一括ステータス変更用チェック状態（再描画で消えないよう保持）

// ===== Pagination state =====
var PAGE_SIZE_STORAGE_KEY = 'pagesize:order-list';
var defaultPageSize = 20;
var pageSize = defaultPageSize; // 1ページの表示件数（数値 or 'all'）
var displayLimit = defaultPageSize; // 現在表示している件数（次を表示で増加）

function loadPageSize() {
  try {
    var stored = sessionStorage.getItem(PAGE_SIZE_STORAGE_KEY);
    if (stored === null) return;
    if (stored === 'all') { pageSize = 'all'; return; }
    var n = parseInt(stored, 10);
    if (n > 0) pageSize = n;
  } catch (e) {}
}

function savePageSize() {
  try { sessionStorage.setItem(PAGE_SIZE_STORAGE_KEY, String(pageSize)); } catch (e) {}
}

// ===== API Helpers =====
function apiGet(url) {
  return fetch(url, { credentials: 'same-origin' })
    .then(function(r) { return r.json(); });
}

function apiPost(url, body) {
  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  }).then(function(r) { return r.json(); });
}

// ===== Fetch Master Data =====
function fetchMasterData(callback) {
  if (viewMode !== 'admin') {
    callback();
    return;
  }
  var done = 0;
  var total = 4;

  function checkDone() {
    done++;
    if (done < total) return;
    // ロール別に zones/areas/shops を管轄スコープに絞り込む
    applyRoleScopeToMasters();
    // Populate zone select
    var zoneSelect = document.getElementById('filterZone');
    if (zoneSelect) {
      // zone/area ロールは管轄ゾーン 1 つだけ表示 + disabled
      if (currentUser && (currentUser.role === 'zone' || currentUser.role === 'area')) {
        zoneSelect.innerHTML = '';
        zones.forEach(function(z) {
          zoneSelect.innerHTML += '<option value="' + z.zone_code + '">' + z.zone_code + ':' + z.zone_name + '</option>';
        });
        zoneSelect.disabled = true;
        if (zones[0]) zoneSelect.value = zones[0].zone_code;
      } else {
        zoneSelect.innerHTML = '<option value="">すべて</option>';
        zones.forEach(function(z) {
          zoneSelect.innerHTML += '<option value="' + z.zone_code + '">' + z.zone_code + ':' + z.zone_name + '</option>';
        });
      }
    }
    // area/shop の初期化は initView の restoreFilters 内で行う（applyFiltersの二重発火防止）
    callback();
  }

  // ロール別の管轄スコープを zones/areas/shops に適用
  function applyRoleScopeToMasters() {
    if (!currentUser) return;
    if (currentUser.role === 'zone' && currentUser.zone_code) {
      zones = zones.filter(function(z) { return z.zone_code === currentUser.zone_code; });
      areas = areas.filter(function(a) { return a.zone_code === currentUser.zone_code; });
      var validAreaCodes = areas.map(function(a) { return a.area_code; });
      shops = shops.filter(function(s) { return validAreaCodes.indexOf(s.area_code) !== -1; });
    } else if (currentUser.role === 'area' && currentUser.area_code) {
      var myArea = null;
      for (var i = 0; i < areas.length; i++) {
        if (areas[i].area_code === currentUser.area_code) { myArea = areas[i]; break; }
      }
      var myZoneCode = myArea ? myArea.zone_code : null;
      zones = zones.filter(function(z) { return z.zone_code === myZoneCode; });
      areas = areas.filter(function(a) { return a.area_code === currentUser.area_code; });
      shops = shops.filter(function(s) { return s.area_code === currentUser.area_code; });
    }
  }

  apiGet('api/master/zones.php')
    .then(function(data) { zones = data.data || []; checkDone(); })
    .catch(function(e) { console.error('Failed to fetch zones:', e); zones = []; checkDone(); });

  apiGet('api/master/areas.php')
    .then(function(data) { areas = data.data || []; checkDone(); })
    .catch(function(e) { console.error('Failed to fetch areas:', e); areas = []; checkDone(); });

  apiGet('api/master/shops.php')
    .then(function(data) { shops = data.data || []; checkDone(); })
    .catch(function(e) { console.error('Failed to fetch shops:', e); shops = []; checkDone(); });

  apiGet('api/master/categories.php')
    .then(function(data) {
      var cats = data.data || [];
      cats.forEach(function(c) {
        categoriesMap[c.code] = { closing_type: c.closing_type, closing_day: c.closing_day, name: c.name };
      });
      // カテゴリプルダウンを動的構築（管理者/店舗どちらのフィルタも対応）
      ['adminFilterCategory', 'filterCategory'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        // 「すべて」「カテゴリ」など先頭の option は維持
        while (sel.options.length > 1) sel.remove(1);
        cats.forEach(function(c) {
          var opt = document.createElement('option');
          opt.value = c.code;
          opt.textContent = c.name;
          sel.appendChild(opt);
        });
      });
      checkDone();
    })
    .catch(function(e) { console.error('Failed to fetch categories:', e); checkDone(); });
}

// ===== Fetch Orders =====
function fetchOrders(callback) {
  var params = [];

  if (viewMode === 'admin') {
    var catFilterAdmin = document.getElementById('adminFilterCategory').value;
    var typeFilter = document.getElementById('adminFilterType').value;
    var statusFilter = document.getElementById('adminFilterStatus').value;
    var shopFilter = document.getElementById('filterShop').value;
    var zoneFilter = document.getElementById('filterZone').value;
    var areaFilter = document.getElementById('filterArea').value;
    var dateFrom = document.getElementById('filterDateFrom').value;
    var dateTo = document.getElementById('filterDateTo').value;

    if (catFilterAdmin) params.push('category=' + encodeURIComponent(catFilterAdmin));
    if (typeFilter) params.push('type=' + encodeURIComponent(typeFilter));
    if (statusFilter !== '') params.push('status=' + encodeURIComponent(statusFilter));
    if (shopFilter) params.push('shop=' + encodeURIComponent(shopFilter));
    if (zoneFilter) params.push('zone=' + encodeURIComponent(zoneFilter));
    if (areaFilter) params.push('area=' + encodeURIComponent(areaFilter));
    if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
    if (dateTo) params.push('date_to=' + encodeURIComponent(dateTo));
  } else {
    var catFilter = document.getElementById('filterCategory').value;
    var typeFilter2 = document.getElementById('filterType').value;
    var statusFilter2 = document.getElementById('filterStatus').value;

    if (catFilter) params.push('category=' + encodeURIComponent(catFilter));
    if (typeFilter2) params.push('type=' + encodeURIComponent(typeFilter2));
    if (statusFilter2 !== '') params.push('status=' + encodeURIComponent(statusFilter2));
  }

  var url = 'api/orders.php' + (params.length ? '?' + params.join('&') : '');

  if (typeof window.showLoading === 'function') window.showLoading('発注一覧を読み込み中…');
  apiGet(url)
    .then(function(data) {
      currentOrders = data.data || [];
      if (callback) callback();
    })
    .catch(function(e) {
      console.error('Failed to fetch orders:', e);
      currentOrders = [];
      if (callback) callback();
    })
    .finally(function() {
      if (typeof window.hideLoading === 'function') window.hideLoading();
    });
}

// ===== 検索条件の保存/復元（同タブ内のみ保持） =====
var FILTER_STORAGE_KEY = 'filters:order-list';
var FILTER_FIELDS_ADMIN = ['adminFilterCategory', 'adminFilterType', 'adminFilterStatus',
                            'filterShop', 'filterZone', 'filterArea', 'filterDateFrom', 'filterDateTo'];
var FILTER_FIELDS_STORE = ['filterCategory', 'filterType', 'filterStatus'];
var isInitializing = true; // 初期化中は saveFilters を抑止（空値での上書きを防ぐ）

function saveFilters() {
  if (isInitializing) return;
  var fields = viewMode === 'admin' ? FILTER_FIELDS_ADMIN : FILTER_FIELDS_STORE;
  var state = {};
  fields.forEach(function(id) {
    var el = document.getElementById(id);
    if (el && !el.disabled) state[id] = el.value;
  });
  try { sessionStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
}

function restoreFilters() {
  var raw;
  try { raw = sessionStorage.getItem(FILTER_STORAGE_KEY); } catch (e) { /* fall through to init dropdowns */ }
  var state;
  try { state = raw ? JSON.parse(raw) : {}; } catch (e) { state = {}; }

  var isZone = currentUser && currentUser.role === 'zone';
  var isArea = currentUser && currentUser.role === 'area';

  // adminの場合、zone→area→shop の順に値を設定しながらカスケード初期化
  if (viewMode === 'admin') {
    var zoneEl = document.getElementById('filterZone');
    if (zoneEl) {
      // zone/area は管轄コードを強制（前回フィルタの値で上書きされないように）
      if (isZone || isArea) {
        zoneEl.value = currentUser.zone_code || (zoneEl.options[0] && zoneEl.options[0].value) || '';
      } else {
        zoneEl.value = state.filterZone || '';
      }
    }
    onZoneChange(); // area選択肢を再生成（applyFiltersはisInitializingで抑止）

    var areaEl = document.getElementById('filterArea');
    if (areaEl) {
      if (isArea) {
        // area ロールは管轄エリアを強制
        areaEl.value = currentUser.area_code || (areaEl.options[0] && areaEl.options[0].value) || '';
      } else {
        areaEl.value = state.filterArea || '';
      }
    }
    onAreaChange(); // shop選択肢を再生成

    var shopEl = document.getElementById('filterShop');
    if (shopEl && state.filterShop) shopEl.value = state.filterShop;
  }

  // その他のフィルタ（カスケード非依存）
  Object.keys(state).forEach(function(id) {
    if (id === 'filterZone' || id === 'filterArea' || id === 'filterShop') return;
    var el = document.getElementById(id);
    if (el && !el.disabled) el.value = state[id];
  });
}

// ===== Init =====
function initView() {
  document.getElementById('storeFilterBar').style.display = viewMode === 'store' ? 'block' : 'none';
  document.getElementById('storeActionBar').style.display = viewMode === 'store' ? 'flex' : 'none';
  document.getElementById('adminFilterBar').style.display = viewMode === 'admin' ? 'block' : 'none';
  // 一括操作バーは admin/system のみ表示（zone/area は閲覧専用）
  document.getElementById('adminActionBar').style.display =
    (viewMode === 'admin' && window.__canOperate) ? 'flex' : 'none';
  renderTableHeader();
  loadPageSize();
  displayLimit = (pageSize === 'all') ? Number.MAX_SAFE_INTEGER : pageSize;
  restoreFilters();
  // 初期化完了：以降の applyFilters → saveFilters はユーザー操作によるもの
  isInitializing = false;
  fetchOrders(function() {
    renderOrders();
  });
}

// ===== Table Header =====
function renderTableHeader() {
  var thead = document.getElementById('orderTableHead');
  if (viewMode === 'admin') {
    thead.innerHTML = '<tr>' +
      '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
      '<th>発注日</th><th>種別</th><th>発注番号</th><th>店舗</th><th>カテゴリ</th><th>内容</th>' +
      '<th>発注数</th><th>金額</th><th>ステータス</th>' +
      '<th style="width:60px">詳細</th></tr>';
  } else {
    thead.innerHTML = '<tr>' +
      '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
      '<th>発注日</th><th>種別</th><th>発注番号</th><th>カテゴリ</th><th>内容</th>' +
      '<th>発注数</th><th>金額</th><th>ステータス</th>' +
      '<th style="width:60px">詳細</th></tr>';
  }
}

// ===== Cascading Filters =====
function onZoneChange() {
  var zoneVal = document.getElementById('filterZone').value;
  var areaSelect = document.getElementById('filterArea');
  var filtered = zoneVal ? areas.filter(function(a) { return a.zone_code === zoneVal; }) : areas;
  var isArea = currentUser && currentUser.role === 'area';
  if (isArea) {
    // area ロールは管轄エリアのみ + disabled
    areaSelect.innerHTML = filtered.map(function(a) {
      return '<option value="' + a.area_code + '">' + a.area_code + ':' + a.area_name + '</option>';
    }).join('');
    areaSelect.disabled = true;
    if (filtered[0]) areaSelect.value = filtered[0].area_code;
  } else {
    areaSelect.innerHTML = '<option value="">すべて</option>' +
      filtered.map(function(a) { return '<option value="' + a.area_code + '">' + a.area_code + ':' + a.area_name + '</option>'; }).join('');
  }
  onAreaChange();
}

function onAreaChange() {
  var areaVal = document.getElementById('filterArea').value;
  var zoneVal = document.getElementById('filterZone').value;
  var shopSelect = document.getElementById('filterShop');
  var filtered = shops;
  if (areaVal) {
    filtered = shops.filter(function(s) { return s.area_code === areaVal; });
  } else if (zoneVal) {
    var areaCodes = areas.filter(function(a) { return a.zone_code === zoneVal; }).map(function(a) { return a.area_code; });
    filtered = shops.filter(function(s) { return areaCodes.indexOf(s.area_code) >= 0; });
  }
  shopSelect.innerHTML = '<option value="">すべて</option>' +
    filtered.map(function(s) { return '<option value="' + s.shop_code + '">' + s.shop_code + ':' + s.shop_name + '</option>'; }).join('');
  applyFilters();
}

// ===== Apply Filters (fetch from API and re-render) =====
function applyFilters() {
  if (isInitializing) return; // 初期化中の二重発火を防ぐ
  saveFilters();
  // フィルタ変更時は表示件数をリセット
  displayLimit = (pageSize === 'all') ? Number.MAX_SAFE_INTEGER : pageSize;
  fetchOrders(function() {
    renderOrders();
  });
}

// ===== Format unavail_dates for display =====
function formatUnavailDate(ud) {
  var label = ud.date;
  if (ud.is_all_day) {
    label += '（終日）';
  } else {
    var parts = [];
    if (ud.time_start) parts.push(ud.time_start);
    if (ud.time_end) parts.push(ud.time_end);
    if (parts.length) {
      label += '（' + parts.join('〜') + '）';
    }
  }
  return label;
}

function formatUnavailDates(arr) {
  if (!arr || !arr.length) return [];
  return arr.map(formatUnavailDate);
}

function formatUnavailDays(arr) {
  if (!arr || !arr.length) return [];
  var dayMap = { monday: '月曜日', tuesday: '火曜日', wednesday: '水曜日', thursday: '木曜日', friday: '金曜日', saturday: '土曜日', sunday: '日曜日' };
  return arr.filter(function(d) { return d; }).map(function(d) { return dayMap[d] || d; });
}

// ===== Render Orders =====
function renderOrders() {
  var filtered = currentOrders.slice();

  // APIの返却順（date DESC）を維持 — 再ソート不要

  // 表示件数のスライス
  var sliced;
  if (pageSize === 'all') {
    sliced = filtered;
    displayLimit = filtered.length;
  } else {
    displayLimit = Math.min(displayLimit, filtered.length);
    sliced = filtered.slice(0, displayLimit);
  }

  var colSpan = viewMode === 'admin' ? 11 : 10;
  var tbody = document.getElementById('orderTableBody');
  var html = '';

  sliced.forEach(function(o) {
    var typeClass = 'type-' + o.type;
    var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
    var statusClass = getStatusClass(o.status, o.type);
    var statusLabel = getStatusLabel(o.status, o.type);
    var catLabel = (categoriesMap[o.category_code] && categoriesMap[o.category_code].name) || o.category_code;
    var displayAmount = getDisplayAmount(o);
    var isOpen = !!expandedIds[o.id];

    var orderCount = 1;
    var contentLabel = o.content_label || '';
    if (o.type === 'equipment' && o.equip_items) {
      orderCount = o.equip_items.length;
    }

    var checkedAttr = selectedIds[o.id] ? ' checked' : '';
    html += '<tr class="order-row ' + o.type + '" onclick="onRowClick(event, \'' + o.id + '\')">' +
      '<td class="td-checkbox"><input type="checkbox" class="order-check" data-id="' + o.id + '" onchange="onCheckChange(this)"' + checkedAttr + '></td>' +
      '<td>' + o.date + '</td>' +
      '<td><span class="type-badge ' + typeClass + '">' + typeLabel + '</span></td>' +
      '<td><strong>' + o.id + '</strong></td>';

    if (viewMode === 'admin') {
      html += '<td>' + (o.shop_name || o.shop_code) + '</td>';
    }

    html += '<td>' + catLabel + '</td>' +
      '<td class="td-content">' + contentLabel + '</td>' +
      '<td>' + orderCount + '</td>' +
      '<td>' + displayAmount + '</td>' +
      '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
      '<td><button class="btn-sm">' + (isOpen ? '−' : '+') + '</button></td>' +
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
  renderPagination(filtered.length);
}

// ===== Pagination UI =====
function renderPagination(totalCount) {
  var info = document.getElementById('paginationInfo');
  var btn  = document.getElementById('showMoreBtn');
  var sel  = document.getElementById('pageSizeSelect');
  if (!info || !btn || !sel) return;

  // セレクトの初期値同期
  sel.value = pageSize === 'all' ? 'all' : String(pageSize);

  if (totalCount === 0) {
    info.textContent = '';
    btn.style.display = 'none';
    return;
  }

  var shown = pageSize === 'all' ? totalCount : Math.min(displayLimit, totalCount);
  info.textContent = '全 ' + totalCount + ' 件中 ' + shown + ' 件表示中';

  if (pageSize === 'all' || shown >= totalCount) {
    btn.style.display = 'none';
  } else {
    btn.style.display = '';
    var nextCount = Math.min(totalCount - shown, pageSize);
    btn.textContent = '次を表示（' + nextCount + '件）';
  }
}

function showMoreOrders() {
  if (pageSize === 'all') return;
  displayLimit += pageSize;
  renderOrders();
}

function onPageSizeChange() {
  var sel = document.getElementById('pageSizeSelect');
  if (!sel) return;
  if (sel.value === 'all') {
    pageSize = 'all';
  } else {
    pageSize = parseInt(sel.value, 10) || defaultPageSize;
    displayLimit = pageSize;
  }
  savePageSize();
  renderOrders();
}

function getDisplayAmount(o) {
  if (o.final_amount) return '¥' + Number(o.final_amount).toLocaleString();
  if (o.estimate_amount) return '¥' + Number(o.estimate_amount).toLocaleString();
  if (o.type === 'repair' || o.type === 'parts') return '—';
  return '—';
}

// ===== Detail Content =====
function renderDetailContent(o) {
  var html = '';

  html += '<div class="detail-two-col">';

  // Left: 依頼内容（読み取り専用）
  html += '<div>';
  html += '<div class="detail-section-title store-info">依頼内容</div>';

  if (o.type === 'repair') {
    html += '<div class="detail-grid">' +
      '<div><div class="detail-label">故障機材</div><div class="detail-value">' + (o.equipment_name || '') + '</div></div>' +
      '<div><div class="detail-label">不具合内容</div><div class="detail-value">' + (o.issue || '') + '</div></div>' +
    '</div>';
    var unavailDates = formatUnavailDates(o.unavail_dates);
    var unavailDays = formatUnavailDays(o.unavail_days);
    if (unavailDates.length || unavailDays.length) {
      html += '<div class="detail-grid">';
      if (unavailDates.length) {
        html += '<div><div class="detail-label">対応不可日時</div><div class="detail-value">' + unavailDates.join('、') + '</div></div>';
      }
      if (unavailDays.length) {
        html += '<div><div class="detail-label">対応不可曜日</div><div class="detail-value">' + unavailDays.join('、') + '</div></div>';
      }
      html += '</div>';
    }
    if (o.photos && o.photos.length > 0) {
      html += renderPhotos(o.photos, '故障写真');
    }
  } else if (o.type === 'equipment') {
    if (o.equip_items && o.equip_items.length) {
      html += '<div class="equip-items-table"><table class="equip-table"><thead><tr><th>商品名</th><th>商品コード</th><th>仕入先</th><th>単価</th><th>数量</th><th>小計</th></tr></thead><tbody>';
      o.equip_items.forEach(function(d) {
        html += '<tr><td>' + d.product_name + '</td><td>' + d.product_code + '</td><td>' + (d.supplier || '') + '</td><td>¥' + Number(d.price).toLocaleString() + '</td><td>' + d.qty + '</td><td>¥' + (Number(d.price) * Number(d.qty)).toLocaleString() + '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
  } else {
    html += '<div class="detail-grid">' +
      '<div><div class="detail-label">部品名・品番</div><div class="detail-value">' + (o.parts_name || '') + '</div></div>' +
      '<div><div class="detail-label">対象機材</div><div class="detail-value">' + (o.target_equipment || '') + '</div></div>' +
      '<div><div class="detail-label">数量</div><div class="detail-value">' + (o.quantity || 1) + '</div></div>' +
      '<div><div class="detail-label">発注理由・備考</div><div class="detail-value">' + (o.reason || '') + '</div></div>' +
    '</div>';
    if (o.photos && o.photos.length > 0) {
      html += renderPhotos(o.photos, '写真');
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
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimate_amount ? '¥' + Number(o.estimate_amount).toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理予定日</div><div class="detail-value">' + (o.repair_schedule_date || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.final_amount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.final_amount ? '¥' + Number(o.final_amount).toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">修理完了日</div><div class="detail-value">' + (o.repair_completed_date || '—') + '</div></div>';
  } else if (o.type === 'equipment') {
    var equipEstimate = o.estimate_amount ? '¥' + Number(o.estimate_amount).toLocaleString() : '—';
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + equipEstimate + '</div></div>';
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + (o.delivery_date || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.final_amount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.final_amount ? '¥' + Number(o.final_amount).toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">納品日</div><div class="detail-value">' + (o.actual_delivery_date || '—') + '</div></div>';
  } else {
    html += '<div><div class="detail-label">見積金額</div><div class="detail-value">' + (o.estimate_amount ? '¥' + Number(o.estimate_amount).toLocaleString() : '—') + '</div></div>';
    html += '<div><div class="detail-label">納品予定日</div><div class="detail-value">' + (o.delivery_date || '—') + '</div></div>';
    html += '<div><div class="detail-label">最終金額</div><div class="detail-value"' + (o.final_amount ? ' style="font-weight:600;color:#065f46;"' : '') + '>' + (o.final_amount ? '¥' + Number(o.final_amount).toLocaleString() : '—') + '</div></div>';
  }
  html += '</div></div>';

  html += renderStatusHistory(o);
  html += renderActionButton(o);

  html += '</div>';
  html += '</div>';
  return html;
}

function renderPhotos(photos, label) {
  var html = '<div class="photo-section"><div class="detail-label">' + label + '（' + photos.length + '枚）</div><div class="photo-grid">';
  photos.forEach(function(p, i) {
    // loading="lazy" + decoding="async" でビューポート外画像の遅延読込（C-26軽量化）
    // サムネイルAPI (size=thumb) で縮小版を取得 / クリック時のリンクは原寸版
    var thumbUrl = p.url + (p.url.indexOf('?') >= 0 ? '&' : '?') + 'size=thumb';
    html += '<div class="photo-thumb">' +
              '<a href="' + p.url + '" target="_blank">' +
                '<img src="' + thumbUrl + '" alt="' + (p.filename || ('写真' + (i + 1))) + '" loading="lazy" decoding="async">' +
              '</a></div>';
  });
  html += '</div></div>';
  return html;
}

// ===== Status History =====
function renderStatusHistory(o) {
  var html = '<div class="status-history"><div class="status-history-title">ステータス履歴</div>';
  html += '<div class="status-timeline">';
  var history = o.status_history || [];
  for (var i = history.length - 1; i >= 0; i--) {
    var h = history[i];
    var isCurrent = (i === history.length - 1);
    html += '<div class="timeline-item">';
    html += '<div class="timeline-dot ' + (isCurrent ? 'current' : 'past') + '"></div>';
    html += '<div class="timeline-status ' + (isCurrent ? 'current' : 'past') + '">' + getStatusLabel(h.status, o.type) + (isCurrent ? ' ← 現在' : '') + '</div>';
    html += '<div class="timeline-meta">' + escapeHtml(h.changed_at) + ' — ' + escapeHtml(h.changed_by || '') + '</div>';
    if (h.memo) {
      html += '<div class="timeline-memo">' + escapeHtml(h.memo) + '</div>';
    }
    html += '</div>';
  }
  html += '</div></div>';
  return html;
}

// ===== Action Button =====
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
  // zone / area は閲覧専用なのでアクションボタン無し（API 側でも 403 で防御）
  if (viewMode === 'admin' && !window.__canOperate) {
    return null;
  }
  // ①依頼中 → ②発注済: 商品部（全種別）
  if (o.status === STATUS.REQUESTING && viewMode === 'admin') {
    return { key: 'order', label: '発注済にする', btnClass: 'btn-sm-primary' };
  }

  // ②発注済 → ③配達中/修理待ち: 部品・修理は商品部が手動
  if (o.status === STATUS.ORDERED && viewMode === 'admin' && o.type !== 'equipment') {
    var label = o.type === 'repair' ? '修理待ちにする' : '配達中にする';
    return { key: 'to-delivering', label: label, btnClass: 'btn-sm-primary' };
  }

  // ③修理待ち → ④修理済: 店舗が手動
  if (o.status === STATUS.DELIVERING && o.type === 'repair' && viewMode === 'store') {
    return { key: 'repair-done', label: '修理完了報告', btnClass: 'btn-sm-pink' };
  }

  // ③配達中 → ④納品済: 店舗が手動
  if (o.status === STATUS.DELIVERING && o.type !== 'repair' && viewMode === 'store') {
    return { key: 'delivery-done', label: '納品済にする', btnClass: 'btn-sm-pink' };
  }

  // ④納品済/修理済 → ⑤完了: 商品部が手動
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

// ===== Find Order from current data =====
function findOrder(orderId) {
  return currentOrders.find(function(o) { return o.id === orderId; });
}

// ===== Modal Dialog =====
function openStatusModal(orderId, action) {
  var order = findOrder(orderId);
  if (!order) return;

  var modal = document.getElementById('modalOverlay');
  var title = document.getElementById('modalTitle');
  var body = document.getElementById('modalBody');
  var footer = document.getElementById('modalFooter');

  if (action === 'order') {
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

    if (isEquipment && order.equip_items) {
      var total = 0;
      order.equip_items.forEach(function(d) { total += Number(d.price) * Number(d.qty); });
      setTimeout(function() {
        document.getElementById('modalAmount').value = total;
        document.getElementById('modalDate').value = getEquipmentDeliveryDate(order);
      }, 0);
    }

  } else if (action === 'to-delivering') {
    var nextLabel = order.type === 'repair' ? '修理待ち' : '配達中';
    title.textContent = nextLabel + 'にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">内容</span><input class="modal-input readonly" value="' + (order.content_label || '') + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-primary" onclick="doToDelivering(\'' + orderId + '\')">' + nextLabel + 'にする</button>';

  } else if (action === 'repair-done') {
    title.textContent = '修理完了報告';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">機材名</span><input class="modal-input readonly" value="' + (order.equipment_name || '') + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-info">修理が完了し、機材が正常に稼働していることを確認してから報告してください。</div>' +
      '<div class="modal-row"><span class="modal-label">修理完了日 <span class="required">*</span></span><input class="modal-input" id="modalRepairDate" type="date"></div>' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="稼働状況や備考"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-pink" onclick="doRepairDone(\'' + orderId + '\')">修理完了報告</button>';

  } else if (action === 'delivery-done') {
    title.textContent = '納品済にする';
    body.innerHTML =
      '<div class="modal-row"><span class="modal-label">発注番号</span><input class="modal-input readonly" value="' + order.id + '" readonly></div>' +
      '<div class="modal-row"><span class="modal-label">内容</span><input class="modal-input readonly" value="' + (order.content_label || '') + '" readonly></div>' +
      '<hr class="modal-divider">' +
      '<div class="modal-row"><span class="modal-label">メモ</span><textarea class="modal-textarea" id="modalMemo" placeholder="任意入力"></textarea></div>';
    footer.innerHTML =
      '<button class="btn-modal btn-modal-cancel" onclick="closeModal()">キャンセル</button>' +
      '<button class="btn-modal btn-modal-pink" onclick="doDeliveryDone(\'' + orderId + '\')">納品済にする</button>';

  } else if (action === 'complete') {
    title.textContent = '完了にする';
    var estAmt = Number(order.estimate_amount) || 0;
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
  var val = parseInt(String(input.value).replace(/,/g, ''), 10);
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

// ===== Status Change Actions (via API) =====

// ①依頼中 → ②発注済
function doOrder(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var amountInput = document.getElementById('modalAmount');
  var dateInput = document.getElementById('modalDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  // カンマ区切り入力（例: "10,000"）にも対応
  var amount = parseInt(String(amountInput.value).replace(/,/g, ''), 10);
  if (isNaN(amount) || amount <= 0) {
    alert('見積金額を入力してください');
    return;
  }

  var body = {
    order_id: orderId,
    action: 'order',
    estimate_amount: amount,
    memo: memo
  };
  if (order.type === 'repair') {
    body.repair_schedule_date = dateInput.value || '';
  } else {
    body.delivery_date = dateInput.value || '';
  }

  apiPost('api/orders/status.php', body)
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doOrder failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ②発注済 → ③配達中/修理待ち
function doToDelivering(orderId) {
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  apiPost('api/orders/status.php', {
    order_id: orderId,
    action: 'to-delivering',
    memo: memo
  })
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doToDelivering failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ③修理待ち → ④修理済
function doRepairDone(orderId) {
  var dateInput = document.getElementById('modalRepairDate');
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  if (!dateInput.value) {
    alert('修理完了日を入力してください');
    return;
  }

  apiPost('api/orders/status.php', {
    order_id: orderId,
    action: 'repair-done',
    repair_completed_date: dateInput.value,
    memo: memo
  })
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doRepairDone failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ③配達中 → ④納品済
function doDeliveryDone(orderId) {
  var memo = (document.getElementById('modalMemo') || {}).value || '';

  apiPost('api/orders/status.php', {
    order_id: orderId,
    action: 'delivery-done',
    memo: memo
  })
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doDeliveryDone failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ④→⑤ 完了
function doComplete(orderId) {
  var order = findOrder(orderId);
  if (!order) return;
  var memo = (document.getElementById('modalMemo') || {}).value || '';
  var finalInput = document.getElementById('modalFinalAmount');
  // カンマ区切り入力（例: "10,000"）にも対応
  var finalAmount = parseInt(String(finalInput.value).replace(/,/g, ''), 10);

  if (order.type === 'repair') {
    if (isNaN(finalAmount) || finalAmount <= 0) {
      alert('最終金額を入力してください');
      return;
    }
  }

  var body = {
    order_id: orderId,
    action: 'complete',
    memo: memo
  };

  if (!isNaN(finalAmount) && finalAmount > 0) {
    body.final_amount = finalAmount;
  }

  if (order.type === 'equipment') {
    var actualDateInput = document.getElementById('modalActualDeliveryDate');
    if (actualDateInput && actualDateInput.value) {
      body.actual_delivery_date = actualDateInput.value;
    }
  }

  apiPost('api/orders/status.php', body)
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doComplete failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ===== Edit Response Info =====
function canEditResponseInfo(o) {
  if (o.status === STATUS.REQUESTING) return false;
  if (viewMode === 'admin') {
    return o.status >= STATUS.ORDERED;
  }
  return o.type === 'repair' && o.status === STATUS.DELIVERED;
}

function getEditableFields(o) {
  var fields = [];
  if (viewMode === 'admin') {
    if (o.status >= STATUS.ORDERED && o.status < STATUS.COMPLETED) {
      if (o.type === 'repair') {
        fields.push({ key: 'estimate_amount', label: '見積金額', type: 'number', value: o.estimate_amount });
        fields.push({ key: 'repair_schedule_date', label: '修理予定日', type: 'date', value: o.repair_schedule_date });
      } else {
        fields.push({ key: 'estimate_amount', label: '見積金額', type: 'number', value: o.estimate_amount });
        fields.push({ key: 'delivery_date', label: '納品予定日', type: 'date', value: o.delivery_date });
      }
      fields.push({ key: 'final_amount', label: '最終金額', type: 'number', value: o.final_amount });
      fields.push({ key: 'memo', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, o.status) });
    } else if (o.status === STATUS.COMPLETED) {
      fields.push({ key: 'final_amount', label: '最終金額', type: 'number', value: o.final_amount });
      if (o.type === 'equipment') {
        fields.push({ key: 'actual_delivery_date', label: '納品日', type: 'date', value: o.actual_delivery_date });
      }
      fields.push({ key: 'memo', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.COMPLETED) });
    }
  } else {
    if (o.type === 'repair' && o.status === STATUS.DELIVERED) {
      fields.push({ key: 'repair_completed_date', label: '修理完了日', type: 'date', value: o.repair_completed_date });
      fields.push({ key: 'memo', label: 'メモ', type: 'textarea', statusIndex: findHistoryIndex(o, STATUS.DELIVERED) });
    }
  }
  return fields;
}

function findHistoryIndex(o, status) {
  if (!o.status_history) return -1;
  for (var i = o.status_history.length - 1; i >= 0; i--) {
    if (o.status_history[i].status === status) return i;
  }
  return -1;
}

function openEditInfoModal(orderId) {
  var order = findOrder(orderId);
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
      if (f.statusIndex >= 0 && order.status_history[f.statusIndex]) {
        memoVal = order.status_history[f.statusIndex].memo || '';
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
  var order = findOrder(orderId);
  if (!order) return;

  var fields = getEditableFields(order);
  var body = { order_id: orderId };
  var validationError = null;

  fields.forEach(function(f) {
    if (validationError) return;
    var el = document.getElementById('editField_' + f.key);
    if (!el) return;
    if (f.type === 'number') {
      var rawVal = el.value.trim();
      if (rawVal === '') {
        // 空欄はスキップ（変更しない）
        return;
      }
      var val = parseInt(rawVal, 10);
      if (isNaN(val) || val <= 0) {
        validationError = f.label + 'は1以上の数値を入力してください';
        return;
      }
      body[f.key] = val;
    } else if (f.type === 'date') {
      body[f.key] = el.value || '';
    } else if (f.type === 'textarea') {
      // memo field - determine the status context for the memo
      body.memo = el.value || '';
      if (f.statusIndex >= 0 && order.status_history[f.statusIndex]) {
        body.memo_status = order.status_history[f.statusIndex].status;
      }
    }
  });

  if (validationError) {
    alert(validationError);
    return;
  }

  apiPost('api/orders/update-info.php', body)
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doSaveEditInfo failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ===== Other Actions =====
function onRowClick(e, id) {
  if (e.target.closest('.td-checkbox')) return;
  toggleDetail(id);
}

function toggleDetail(id) {
  expandedIds[id] = !expandedIds[id];
  renderOrders();
}

function toggleAll(checkbox) {
  document.querySelectorAll('.order-check').forEach(function(cb) {
    cb.checked = checkbox.checked;
    var id = cb.getAttribute('data-id');
    if (checkbox.checked) {
      selectedIds[id] = true;
    } else {
      delete selectedIds[id];
    }
  });
}

function onCheckChange(checkbox) {
  var id = checkbox.getAttribute('data-id');
  if (checkbox.checked) {
    selectedIds[id] = true;
  } else {
    delete selectedIds[id];
  }
}

function exportExcel() {
  var params = [];

  // チェックされた発注がある場合は ids パラメータで限定、それ以外はフィルタ条件で抽出
  var checkedIds = getCheckedOrderIds();
  if (checkedIds.length > 0) {
    params.push('ids=' + encodeURIComponent(checkedIds.join(',')));
  } else {
    var dateFrom = document.getElementById('filterDateFrom');
    var dateTo = document.getElementById('filterDateTo');
    if (dateFrom && dateFrom.value) params.push('date_from=' + encodeURIComponent(dateFrom.value));
    if (dateTo && dateTo.value) params.push('date_to=' + encodeURIComponent(dateTo.value));

    if (viewMode === 'admin') {
      var catA = document.getElementById('adminFilterCategory');
      var typeA = document.getElementById('adminFilterType');
      var statusA = document.getElementById('adminFilterStatus');
      var zone = document.getElementById('filterZone');
      var area = document.getElementById('filterArea');
      var shop = document.getElementById('filterShop');
      if (catA && catA.value) params.push('category=' + encodeURIComponent(catA.value));
      if (typeA && typeA.value) params.push('type=' + encodeURIComponent(typeA.value));
      if (statusA && statusA.value) params.push('status=' + encodeURIComponent(statusA.value));
      if (zone && zone.value) params.push('zone=' + encodeURIComponent(zone.value));
      if (area && area.value) params.push('area=' + encodeURIComponent(area.value));
      if (shop && shop.value) params.push('shop=' + encodeURIComponent(shop.value));
    } else {
      var cat = document.getElementById('filterCategory');
      var type = document.getElementById('filterType');
      var status = document.getElementById('filterStatus');
      if (cat && cat.value) params.push('category=' + encodeURIComponent(cat.value));
      if (type && type.value) params.push('type=' + encodeURIComponent(type.value));
      if (status && status.value) params.push('status=' + encodeURIComponent(status.value));
    }
  }

  window.location.href = 'api/export/orders.php' + (params.length ? '?' + params.join('&') : '');
}

// ===== Bulk Status Change =====
function getCheckedOrderIds() {
  return Object.keys(selectedIds);
}

function getCheckedOrders() {
  var ids = getCheckedOrderIds();
  return currentOrders.filter(function(o) { return ids.indexOf(o.id) >= 0; });
}

function clearSelection() {
  selectedIds = {};
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
  if (byStatus[STATUS.REQUESTING] && viewMode === 'admin') {
    actions.push({ status: STATUS.REQUESTING, action: 'order', label: '依頼中 → 発注済', count: byStatus[STATUS.REQUESTING].length });
  }
  if (byStatus[STATUS.ORDERED] && viewMode === 'admin') {
    var nonEquip = byStatus[STATUS.ORDERED].filter(function(o) { return o.type !== 'equipment'; });
    if (nonEquip.length > 0) {
      actions.push({ status: STATUS.ORDERED, action: 'to-delivering', label: '発注済 → 配達中/修理待ち', count: nonEquip.length, filterEquip: true });
    }
  }
  if (byStatus[STATUS.DELIVERED] && viewMode === 'admin') {
    actions.push({ status: STATUS.DELIVERED, action: 'complete', label: '納品済/修理済 → 完了', count: byStatus[STATUS.DELIVERED].length });
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
  var targetIds = [];
  checked.forEach(function(o) {
    if (o.status !== actionDef.status) return;
    if (actionDef.filterEquip && o.type === 'equipment') return;
    targetIds.push(o.id);
  });

  if (targetIds.length === 0) {
    alert('対象の発注がありません。');
    return;
  }

  var memo = (document.getElementById('modalMemo') || {}).value || '';

  apiPost('api/orders/bulk-status.php', {
    order_ids: targetIds,
    action: actionDef.action,
    memo: memo
  })
    .then(function(data) {
      if (!data.success) {
        alert(data.message || 'エラーが発生しました');
        return;
      }
      closeModal();
      var selectAll = document.getElementById('selectAll');
      if (selectAll) selectAll.checked = false;
      clearSelection();
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error('doBulkStatusChange failed:', e);
      alert('通信エラーが発生しました');
    });
}

// ===== Boot: wait for userLoaded event from common-nav.js =====
function bootOrderList(user) {
  if (currentUser) return; // 二重起動防止
  currentUser = user;
  // admin/system/zone/area は admin ビュー（複数店舗横断）。shop は store ビュー（自店のみ）
  var managerRoles = ['admin', 'system', 'zone', 'area'];
  viewMode = managerRoles.indexOf(currentUser.role) !== -1 ? 'admin' : 'store';
  // ステータス操作・一括変更は admin/system のみ可能。zone/area は閲覧専用。
  window.__canOperate = (currentUser.role === 'admin' || currentUser.role === 'system');

  // 店舗ユーザーの場合、取扱カテゴリのみをドロップダウンに表示（プレースホルダは「すべてのカテゴリ」）
  if (viewMode === 'store' && Array.isArray(user.categories)) {
    var filterCategoryEl = document.getElementById('filterCategory');
    if (filterCategoryEl) {
      var opts = '<option value="">すべてのカテゴリ</option>';
      user.categories.forEach(function(c) {
        opts += '<option value="' + c.code + '">' + c.name + '</option>';
      });
      filterCategoryEl.innerHTML = opts;
      // categoriesMap を user.categories から構築（表セルの日本語ラベル表示用）
      user.categories.forEach(function(c) {
        categoriesMap[c.code] = { name: c.name };
      });
    }
  }

  fetchMasterData(function() {
    initView();
  });
}

window.addEventListener('userLoaded', function(e) {
  bootOrderList(e.detail);
});

// レース条件対策: common-nav.jsが先に完了していた場合
if (window.__currentUser) {
  bootOrderList(window.__currentUser);
}

// ===== Draft Mails (Phase 1) =====
// 「依頼中」の備品発注を仕入先単位に集計し、メーラー起動 / 本文コピー / 発注済化を提供する
var draftMailsState = {
  suppliers: [],
  activeIndex: 0
};

function openDraftMails() {
  if (viewMode !== 'admin') return;

  var params = [];
  // チェックがある場合はその発注のみ対象、なければ画面のフィルタを引き継ぐ
  var checkedIds = getCheckedOrderIds();
  if (checkedIds.length > 0) {
    params.push('ids=' + encodeURIComponent(checkedIds.join(',')));
  } else {
    var zone = document.getElementById('filterZone');
    var area = document.getElementById('filterArea');
    var shop = document.getElementById('filterShop');
    var cat  = document.getElementById('adminFilterCategory');
    var df   = document.getElementById('filterDateFrom');
    var dt   = document.getElementById('filterDateTo');
    if (zone && zone.value) params.push('zone=' + encodeURIComponent(zone.value));
    if (area && area.value) params.push('area=' + encodeURIComponent(area.value));
    if (shop && shop.value) params.push('shop=' + encodeURIComponent(shop.value));
    if (cat && cat.value)   params.push('category=' + encodeURIComponent(cat.value));
    if (df && df.value)     params.push('date_from=' + encodeURIComponent(df.value));
    if (dt && dt.value)     params.push('date_to=' + encodeURIComponent(dt.value));
  }

  var url = 'api/orders/draft-mails.php' + (params.length ? '?' + params.join('&') : '');

  if (typeof window.showLoading === 'function') window.showLoading('集計中…');

  apiGet(url)
    .then(function(res) {
      if (!res || !res.success) {
        alert('メール下書きの取得に失敗しました');
        return;
      }
      draftMailsState.suppliers = (res.data && res.data.suppliers) || [];
      draftMailsState.activeIndex = 0;
      renderDraftMails();
      var ov = document.getElementById('draftMailsOverlay');
      if (ov) ov.classList.add('open');
    })
    .catch(function(e) {
      console.error(e);
      alert('通信エラーが発生しました');
    })
    .finally(function() {
      if (typeof window.hideLoading === 'function') window.hideLoading();
    });
}

function closeDraftMails() {
  var ov = document.getElementById('draftMailsOverlay');
  if (ov) ov.classList.remove('open');
}

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatYen(n) {
  return '¥' + (Number(n) || 0).toLocaleString();
}

function buildMailSubject(sup) {
  var today = new Date();
  var ymd = today.getFullYear() + ('0' + (today.getMonth() + 1)).slice(-2) + ('0' + today.getDate()).slice(-2);
  return '【発注依頼】' + ymd + '_' + sup.supplier + ' 御中';
}

function buildMailBody(sup) {
  var lines = [];
  var contactName = sup.contact ? (sup.contact + ' 様') : 'ご担当者様';
  lines.push(sup.supplier + ' 御中');
  lines.push(contactName);
  lines.push('');
  lines.push('いつもお世話になっております。');
  lines.push('快活フロンティア商品部です。');
  lines.push('');
  lines.push('下記のとおり発注いたしますので、ご手配のほど');
  lines.push('よろしくお願いいたします。');
  lines.push('');
  lines.push('─────────────────────────');
  lines.push('■ 発注明細');
  lines.push('─────────────────────────');

  // 店舗単位でまとめる
  var byShop = {};
  sup.items.forEach(function(it) {
    var key = it.shop_code + ':' + it.shop_name;
    if (!byShop[key]) byShop[key] = [];
    byShop[key].push(it);
  });

  Object.keys(byShop).forEach(function(key) {
    var label = key.split(':').slice(1).join(':');
    lines.push('');
    lines.push('【' + label + '店】');
    byShop[key].forEach(function(it) {
      var codePart = it.product_code ? ' (' + it.product_code + ')' : '';
      var priceLine = it.price > 0
        ? '  ・' + it.product_name + codePart + ' ×' + it.qty + ' @' + it.price.toLocaleString() + '円'
        : '  ・' + it.product_name + codePart + ' ×' + it.qty;
      lines.push(priceLine);
    });
  });

  lines.push('');
  lines.push('─────────────────────────');
  lines.push('合計数量: ' + sup.total_qty + ' 点');
  if (sup.total_amount > 0) {
    lines.push('合計金額（税抜）: ' + sup.total_amount.toLocaleString() + ' 円');
  }
  lines.push('─────────────────────────');
  lines.push('');
  lines.push('納期・納品先・運送条件など、ご不明な点があれば');
  lines.push('ご連絡くださいますようお願いいたします。');
  lines.push('');
  lines.push('以上、よろしくお願いいたします。');
  lines.push('');
  lines.push('────────────────────────');
  lines.push('快活フロンティア 商品部');
  lines.push('────────────────────────');

  return lines.join('\n');
}

function renderDraftMails() {
  var body = document.getElementById('draftMailsBody');
  if (!body) return;

  var suppliers = draftMailsState.suppliers;

  if (!suppliers || suppliers.length === 0) {
    body.innerHTML = '<div class="draft-mails-empty">対象となる「依頼中」の備品発注がありません。<br>発注一覧のフィルタ条件をご確認ください。</div>';
    return;
  }

  // Tabs
  var tabsHtml = '<div class="draft-mails-tabs">';
  suppliers.forEach(function(sup, i) {
    var active = (i === draftMailsState.activeIndex) ? ' active' : '';
    tabsHtml += '<button type="button" class="draft-mails-tab' + active + '" onclick="switchDraftTab(' + i + ')">' +
      escapeHtml(sup.supplier) +
      '<span class="badge-count">' + sup.order_ids.length + '</span>' +
    '</button>';
  });
  tabsHtml += '</div>';

  // Cards
  var cardsHtml = '';
  suppliers.forEach(function(sup, i) {
    var active = (i === draftMailsState.activeIndex) ? ' active' : '';
    var subject = buildMailSubject(sup);
    var bodyText = buildMailBody(sup);
    cardsHtml += '<div class="draft-mail-card' + active + '" id="draftMailCard-' + i + '">';
    cardsHtml +=   '<div class="draft-mail-summary">';
    cardsHtml +=     '<div><span class="draft-mail-summary-label">仕入先:</span><span class="draft-mail-summary-value">' + escapeHtml(sup.supplier) + '</span></div>';
    cardsHtml +=     '<div><span class="draft-mail-summary-label">対象発注数:</span><span class="draft-mail-summary-value">' + sup.order_ids.length + ' 件</span></div>';
    cardsHtml +=     '<div><span class="draft-mail-summary-label">合計数量:</span><span class="draft-mail-summary-value">' + sup.total_qty + ' 点</span></div>';
    cardsHtml +=     '<div><span class="draft-mail-summary-label">合計金額:</span><span class="draft-mail-summary-value">' + formatYen(sup.total_amount) + '</span></div>';
    cardsHtml +=     '<div style="grid-column:1/-1"><span class="draft-mail-summary-label">対象店舗:</span><span class="draft-mail-summary-value">' + escapeHtml(sup.shops.join(' / ')) + '</span></div>';
    cardsHtml +=   '</div>';

    cardsHtml +=   '<div class="draft-mail-field">';
    cardsHtml +=     '<label>To（送信先メールアドレス）</label>';
    cardsHtml +=     '<input type="text" class="draft-mail-input" id="draftMailTo-' + i + '" value="' + escapeHtml(sup.email || '') + '" placeholder="例: contact@supplier.co.jp">';
    cardsHtml +=   '</div>';

    cardsHtml +=   '<div class="draft-mail-field">';
    cardsHtml +=     '<label>件名</label>';
    cardsHtml +=     '<input type="text" class="draft-mail-input" id="draftMailSubject-' + i + '" value="' + escapeHtml(subject) + '">';
    cardsHtml +=   '</div>';

    cardsHtml +=   '<div class="draft-mail-field">';
    cardsHtml +=     '<label>本文（編集可）</label>';
    cardsHtml +=     '<textarea class="draft-mail-textarea" id="draftMailBody-' + i + '">' + escapeHtml(bodyText) + '</textarea>';
    cardsHtml +=   '</div>';

    cardsHtml +=   '<div class="draft-mail-actions">';
    cardsHtml +=     '<button type="button" class="btn-action btn-secondary" onclick="openMailtoForSupplier(' + i + ')">';
    cardsHtml +=       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
    cardsHtml +=       'メーラーで開く</button>';
    cardsHtml +=     '<button type="button" class="btn-action btn-secondary" onclick="copyDraftBody(' + i + ')">';
    cardsHtml +=       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    cardsHtml +=       '本文コピー</button>';
    cardsHtml +=     '<span class="copy-feedback" id="copyFeedback-' + i + '">コピーしました</span>';
    cardsHtml +=     '<div class="spacer"></div>';
    cardsHtml +=     '<button type="button" class="btn-action btn-primary" onclick="markSupplierOrdered(' + i + ')">';
    cardsHtml +=       'この仕入先分を発注済にする</button>';
    cardsHtml +=   '</div>';
    cardsHtml += '</div>';
  });

  body.innerHTML = tabsHtml + cardsHtml;
}

function switchDraftTab(i) {
  draftMailsState.activeIndex = i;
  // クラス切り替えのみ（再描画すると編集中のテキストが消えるため）
  document.querySelectorAll('.draft-mails-tab').forEach(function(el, idx) {
    if (idx === i) el.classList.add('active'); else el.classList.remove('active');
  });
  document.querySelectorAll('.draft-mail-card').forEach(function(el, idx) {
    if (idx === i) el.classList.add('active'); else el.classList.remove('active');
  });
}

function openMailtoForSupplier(i) {
  var toEl   = document.getElementById('draftMailTo-' + i);
  var subEl  = document.getElementById('draftMailSubject-' + i);
  var bodyEl = document.getElementById('draftMailBody-' + i);
  if (!toEl || !subEl || !bodyEl) return;

  var to      = (toEl.value || '').trim();
  var subject = subEl.value || '';
  var body    = bodyEl.value || '';

  var qs = [];
  qs.push('subject=' + encodeURIComponent(subject));
  qs.push('body=' + encodeURIComponent(body));
  var url = 'mailto:' + encodeURIComponent(to) + '?' + qs.join('&');

  // URL 長制限（実装によっては ~2000 文字）の警告
  if (url.length > 2000) {
    if (!confirm('本文が長いため、メーラーで開いた際に途中で切れる可能性があります。\n「本文コピー」の利用を推奨します。\n\nそれでもメーラーを起動しますか？')) {
      return;
    }
  }

  // 一部ブラウザ/メーラーで location.href にすると現画面が遷移してしまうため新規ウィンドウ経由が安全
  window.location.href = url;
}

function copyDraftBody(i) {
  var bodyEl = document.getElementById('draftMailBody-' + i);
  if (!bodyEl) return;
  var text = bodyEl.value || '';

  var fb = document.getElementById('copyFeedback-' + i);

  function showFeedback() {
    if (!fb) return;
    fb.classList.add('show');
    setTimeout(function() { fb.classList.remove('show'); }, 1500);
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(showFeedback).catch(function() {
      // フォールバック
      legacyCopy(text);
      showFeedback();
    });
  } else {
    legacyCopy(text);
    showFeedback();
  }
}

function legacyCopy(text) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
}

function markSupplierOrdered(i) {
  var sup = draftMailsState.suppliers[i];
  if (!sup) return;

  var msg = '【' + sup.supplier + '】に紐付く ' + sup.order_ids.length + ' 件の発注を「発注済」に変更します。\n' +
            'よろしいですか？';
  if (!confirm(msg)) return;

  if (typeof window.showLoading === 'function') window.showLoading('発注済に更新中…');

  apiPost('api/orders/bulk-status.php', {
    order_ids: sup.order_ids,
    action: 'order',
    memo: 'メール下書きから発注: ' + sup.supplier
  })
    .then(function(res) {
      if (!res || !res.success) {
        alert('一括ステータス変更に失敗しました');
        return;
      }
      var processed = (res.processed || []).length;
      var skipped   = (res.skipped || []).length;
      alert('発注済に更新しました（' + processed + ' 件 / スキップ: ' + skipped + ' 件）');

      // この仕入先タブを閉じてリストから除外
      draftMailsState.suppliers.splice(i, 1);
      if (draftMailsState.activeIndex >= draftMailsState.suppliers.length) {
        draftMailsState.activeIndex = Math.max(0, draftMailsState.suppliers.length - 1);
      }
      renderDraftMails();

      // 発注一覧側も再読込
      fetchOrders(function() { renderOrders(); });
    })
    .catch(function(e) {
      console.error(e);
      alert('通信エラーが発生しました');
    })
    .finally(function() {
      if (typeof window.hideLoading === 'function') window.hideLoading();
    });
}
