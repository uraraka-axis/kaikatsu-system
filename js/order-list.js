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
  var total = 3;

  function checkDone() {
    done++;
    if (done < total) return;
    // Populate zone select
    var zoneSelect = document.getElementById('filterZone');
    if (zoneSelect) {
      zoneSelect.innerHTML = '<option value="">すべて</option>';
      zones.forEach(function(z) {
        zoneSelect.innerHTML += '<option value="' + z.zone_code + '">' + z.zone_code + ':' + z.zone_name + '</option>';
      });
    }
    // Initialize area/shop selects
    onZoneChange();
    callback();
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
}

// ===== Fetch Orders =====
function fetchOrders(callback) {
  var params = [];

  if (viewMode === 'admin') {
    var typeFilter = document.getElementById('adminFilterType').value;
    var statusFilter = document.getElementById('adminFilterStatus').value;
    var shopFilter = document.getElementById('filterShop').value;
    var zoneFilter = document.getElementById('filterZone').value;
    var areaFilter = document.getElementById('filterArea').value;
    var dateFrom = document.getElementById('filterDateFrom').value;
    var dateTo = document.getElementById('filterDateTo').value;

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

  apiGet(url)
    .then(function(data) {
      currentOrders = data.data || [];
      if (callback) callback();
    })
    .catch(function(e) {
      console.error('Failed to fetch orders:', e);
      currentOrders = [];
      if (callback) callback();
    });
}

// ===== Init =====
function initView() {
  document.getElementById('storeFilterBar').style.display = viewMode === 'store' ? '' : 'none';
  document.getElementById('storeActionBar').style.display = viewMode === 'store' ? 'flex' : 'none';
  document.getElementById('adminFilterBar').style.display = viewMode === 'admin' ? 'block' : 'none';
  document.getElementById('adminActionBar').style.display = viewMode === 'admin' ? 'flex' : 'none';
  renderTableHeader();
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
  var filtered = zoneVal ? areas.filter(function(a) { return a.zone_code === zoneVal; }) : areas;
  areaSelect.innerHTML = '<option value="">すべて</option>' +
    filtered.map(function(a) { return '<option value="' + a.area_code + '">' + a.area_code + ':' + a.area_name + '</option>'; }).join('');
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
  return arr.map(function(d) { return d.day_of_week; });
}

// ===== Render Orders =====
function renderOrders() {
  var filtered = currentOrders.slice();

  // APIの返却順（created_at DESC）を維持 — 再ソート不要

  var colSpan = viewMode === 'admin' ? 11 : 10;
  var tbody = document.getElementById('orderTableBody');
  var html = '';

  filtered.forEach(function(o) {
    var typeClass = 'type-' + o.type;
    var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
    var statusClass = getStatusClass(o.status, o.type);
    var statusLabel = getStatusLabel(o.status, o.type);
    var catLabel = o.category_code === 'fitness' ? 'フィットネス' : 'ゴルフ';
    var displayAmount = getDisplayAmount(o);
    var isOpen = !!expandedIds[o.id];

    var orderCount = 1;
    var contentLabel = o.content_label || '';
    if (o.type === 'equipment' && o.equip_items) {
      orderCount = o.equip_items.length;
    }

    html += '<tr class="order-row ' + o.type + '" onclick="onRowClick(event, \'' + o.id + '\')">' +
      '<td class="td-checkbox"><input type="checkbox" class="order-check" data-id="' + o.id + '"></td>' +
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
      '<td>' + o.date + '</td>' +
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
    html += '<div class="photo-thumb"><a href="' + p.url + '" target="_blank"><img src="' + p.url + '" alt="' + (p.filename || ('写真' + (i + 1))) + '"></a></div>';
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
    html += '<div class="timeline-meta">' + h.changed_at + ' — ' + h.changed_by + '</div>';
    if (h.memo) {
      html += '<div class="timeline-memo">' + h.memo + '</div>';
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
        document.getElementById('modalDate').value = getEquipmentDeliveryDate();
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

// ===== Status Change Actions (via API) =====

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
  var finalAmount = parseInt(finalInput.value);

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

  fields.forEach(function(f) {
    var el = document.getElementById('editField_' + f.key);
    if (!el) return;
    if (f.type === 'number') {
      var val = parseInt(el.value);
      if (!isNaN(val) && val > 0) {
        body[f.key] = val;
      }
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
  document.querySelectorAll('.order-check').forEach(function(cb) { cb.checked = checkbox.checked; });
}

function exportExcel() {
  alert('Excel出力機能は現在準備中です。');
}

// ===== Bulk Status Change =====
function getCheckedOrderIds() {
  var ids = [];
  document.querySelectorAll('.order-check:checked').forEach(function(cb) { ids.push(cb.dataset.id); });
  return ids;
}

function getCheckedOrders() {
  var ids = getCheckedOrderIds();
  return currentOrders.filter(function(o) { return ids.indexOf(o.id) >= 0; });
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
  viewMode = currentUser.role === 'admin' ? 'admin' : 'store';

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
