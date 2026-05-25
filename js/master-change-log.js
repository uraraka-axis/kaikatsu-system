/**
 * 監査ログ閲覧 (system 専用)
 * - タブ切替: マスタ変更履歴 / マスタ予約一覧 / ログイン履歴
 * - 各タブごとに独立したフィルタ + ページネーション (50件/ページ)
 * - 行クリック or 詳細ボタンでモーダル表示
 */

// ===== ラベル定義 =====
var TABLE_LABELS = {
  zones: 'ゾーン',
  areas: 'エリア',
  shops: '店舗',
  suppliers: '仕入先',
  users: 'ユーザー',
  products: '商品',
  budgets: '予算',
  shop_categories: '店舗カテゴリ',
  categories: 'カテゴリ'
};
var OPERATION_LABELS = {
  insert: '登録',
  update: '更新',
  delete: '削除'
};
var STATUS_LABELS = {
  pending: '予約中',
  applied: '反映済',
  cancelled: '取消',
  error: 'エラー'
};
var FAILURE_REASON_LABELS = {
  user_not_found_or_inactive: 'ユーザー未存在/無効',
  invalid_password: 'パスワード不一致'
};

// ===== ステート =====
window.__auditState = {
  'change-log': { page: 1, limit: 50, total: 0, totalPages: 1 },
  'scheduled':  { page: 1, limit: 50, total: 0, totalPages: 1 },
  'login':      { page: 1, limit: 50, total: 0, totalPages: 1 }
};
var currentTab = 'change-log';
var lastResults = {
  'change-log': [],
  'scheduled':  [],
  'login':      []
};

// ===== Utility =====
function escapeHtml(v) {
  if (v === null || v === undefined) return '';
  return String(v)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
function fmtDateTime(s) {
  if (!s) return '';
  // 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DD HH:MM'
  return String(s).replace(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}).*/, '$1 $2');
}
function tryParseJson(s) {
  if (s === null || s === undefined || s === '') return null;
  if (typeof s !== 'string') return s;
  try { return JSON.parse(s); } catch (e) { return null; }
}
function summarizeChangeData(json) {
  // 表示用: 変更点を短くまとめる
  if (!json) return '';
  // master_excel が書き出す形式: { before: {...}, after: {...}, changed_fields: [...] }
  // master_scheduling が書き出す形式: { before: {...}, after: {...}, changed_fields: [...] } (after は予約値)
  if (json.changed_fields && Array.isArray(json.changed_fields)) {
    if (json.changed_fields.length === 0) return '（変更なし）';
    return json.changed_fields.map(function(f) { return f; }).join(', ');
  }
  // operation = insert / delete だと after のみ / before のみのケース
  if (json.after && !json.before) {
    var keys = Object.keys(json.after);
    return keys.slice(0, 5).join(', ') + (keys.length > 5 ? ' ...' : '');
  }
  if (json.before && !json.after) {
    return '削除: ' + Object.keys(json.before).slice(0, 3).join(', ');
  }
  // それ以外はトップレベルキーを列挙
  return Object.keys(json).slice(0, 5).join(', ');
}

// ===== Tab Switch =====
function switchTab(tab) {
  currentTab = tab;
  document.querySelectorAll('.audit-tab').forEach(function(b) {
    b.classList.toggle('active', b.getAttribute('data-tab') === tab);
  });
  document.querySelectorAll('.audit-panel').forEach(function(p) {
    p.classList.toggle('active', p.id === 'panel-' + tab);
  });
  // 初回のみロード
  var st = window.__auditState[tab];
  if (st && st.total === 0 && !st._loaded) {
    st._loaded = true;
    if (tab === 'change-log') loadChangeLog(1);
    else if (tab === 'scheduled') loadScheduled(1);
    else if (tab === 'login') loadLoginHistory(1);
  }
}

// ===== Reset Filters =====
function resetFilters(tab) {
  if (tab === 'change-log') {
    document.getElementById('cl_target_table').value = '';
    document.getElementById('cl_operation').value = '';
    document.getElementById('cl_date_from').value = '';
    document.getElementById('cl_date_to').value = '';
    loadChangeLog(1);
  } else if (tab === 'scheduled') {
    document.getElementById('sc_target_table').value = '';
    document.getElementById('sc_status').value = '';
    document.getElementById('sc_operation').value = '';
    document.getElementById('sc_date_from').value = '';
    document.getElementById('sc_date_to').value = '';
    loadScheduled(1);
  } else if (tab === 'login') {
    document.getElementById('lh_login_id').value = '';
    document.getElementById('lh_success').value = '';
    document.getElementById('lh_date_from').value = '';
    document.getElementById('lh_date_to').value = '';
    loadLoginHistory(1);
  }
}

// ===== Pagination UI =====
function renderPagination(tab, info, prev, next, indicator) {
  var st = window.__auditState[tab];
  var startIdx = st.total === 0 ? 0 : (st.page - 1) * st.limit + 1;
  var endIdx = Math.min(st.page * st.limit, st.total);
  document.getElementById(info).textContent = st.total === 0
    ? '該当なし'
    : (startIdx + '〜' + endIdx + ' / ' + st.total + '件');
  document.getElementById(indicator).textContent = st.total === 0
    ? '-'
    : (st.page + ' / ' + st.totalPages + ' ページ');
  document.getElementById(prev).disabled = st.page <= 1;
  document.getElementById(next).disabled = st.page >= st.totalPages;
}

// ===== Tab 1: マスタ変更履歴 =====
function loadChangeLog(page) {
  if (page < 1) page = 1;
  var st = window.__auditState['change-log'];
  if (page > st.totalPages && st.totalPages >= 1) page = st.totalPages;

  var qs = new URLSearchParams();
  var v;
  if ((v = document.getElementById('cl_target_table').value)) qs.append('target_table', v);
  if ((v = document.getElementById('cl_operation').value))    qs.append('operation', v);
  if ((v = document.getElementById('cl_date_from').value))    qs.append('date_from', v);
  if ((v = document.getElementById('cl_date_to').value))      qs.append('date_to', v);
  qs.append('page', String(page));
  qs.append('limit', '50');

  var tbody = document.getElementById('cl_tbody');
  tbody.innerHTML = '<tr><td colspan="7" class="empty-row">読み込み中…</td></tr>';

  fetch('api/system/master-change-log.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(function(res) {
      if (res.status === 401) { window.location.href = 'login.html'; throw new Error('unauthorized'); }
      if (res.status === 403) { throw new Error('権限がありません'); }
      return res.json();
    })
    .then(function(data) {
      if (!data.success) throw new Error(data.error || '取得失敗');
      st.page = data.pagination.page;
      st.limit = data.pagination.limit;
      st.total = data.pagination.total;
      st.totalPages = data.pagination.total_pages;
      lastResults['change-log'] = data.data;
      renderChangeLogTable(data.data);
      renderPagination('change-log', 'cl_info', 'cl_prev', 'cl_next', 'cl_pageInd');
    })
    .catch(function(err) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-row">読み込みエラー: ' + escapeHtml(err.message) + '</td></tr>';
    });
}

function renderChangeLogTable(rows) {
  var tbody = document.getElementById('cl_tbody');
  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-row">該当データがありません</td></tr>';
    return;
  }
  var html = '';
  rows.forEach(function(r, idx) {
    var json = tryParseJson(r.change_data);
    var summary = summarizeChangeData(json);
    var who = r.changed_by_name
      ? escapeHtml(r.changed_by_name) + '<br><span style="color:#94a3b8;font-size:11px">' + escapeHtml(r.changed_by_login_id || '') + '</span>'
      : '<span style="color:#94a3b8">—</span>';
    html += '<tr>'
      + '<td class="col-datetime">' + escapeHtml(fmtDateTime(r.changed_at)) + '</td>'
      + '<td><span class="badge badge-table">' + escapeHtml(TABLE_LABELS[r.target_table] || r.target_table) + '</span></td>'
      + '<td><span class="badge badge-op-' + escapeHtml(r.operation) + '">' + escapeHtml(OPERATION_LABELS[r.operation] || r.operation) + '</span></td>'
      + '<td class="col-key">' + escapeHtml(r.record_key) + '</td>'
      + '<td class="col-summary">' + escapeHtml(summary) + '</td>'
      + '<td>' + who + '</td>'
      + '<td><button type="button" class="btn-detail" onclick="showChangeLogDetail(' + idx + ')">詳細</button></td>'
      + '</tr>';
  });
  tbody.innerHTML = html;
}

function showChangeLogDetail(idx) {
  var r = lastResults['change-log'][idx];
  if (!r) return;
  var json = tryParseJson(r.change_data);
  var html = '';
  html += '<div class="detail-section">'
    + '<div class="detail-section-title">基本情報</div>'
    + '<div class="detail-grid">'
    + '<div class="key">変更日時</div><div class="val">' + escapeHtml(r.changed_at || '') + '</div>'
    + '<div class="key">対象テーブル</div><div class="val">' + escapeHtml(TABLE_LABELS[r.target_table] || r.target_table) + ' (' + escapeHtml(r.target_table) + ')</div>'
    + '<div class="key">操作</div><div class="val">' + escapeHtml(OPERATION_LABELS[r.operation] || r.operation) + '</div>'
    + '<div class="key">レコードキー</div><div class="val">' + escapeHtml(r.record_key) + '</div>'
    + '<div class="key">変更者</div><div class="val">' + (r.changed_by_name ? escapeHtml(r.changed_by_name) + ' (' + escapeHtml(r.changed_by_login_id || '') + ')' : '<span style="color:#94a3b8">—</span>') + '</div>'
    + '<div class="key">アップロード元</div><div class="val">' + escapeHtml(r.upload_filename || '—') + '</div>'
    + '<div class="key">バッチID</div><div class="val" style="font-family:monospace;font-size:11px">' + escapeHtml(r.upload_batch_id || '—') + '</div>'
    + '</div></div>';

  html += renderChangeDataDetail(json, r.operation);

  openAuditModal('変更履歴 #' + r.id, html);
}

// ===== Tab 2: マスタ予約一覧 =====
function loadScheduled(page) {
  if (page < 1) page = 1;
  var st = window.__auditState['scheduled'];
  if (page > st.totalPages && st.totalPages >= 1) page = st.totalPages;

  var qs = new URLSearchParams();
  var v;
  if ((v = document.getElementById('sc_target_table').value)) qs.append('target_table', v);
  if ((v = document.getElementById('sc_status').value))       qs.append('status', v);
  if ((v = document.getElementById('sc_operation').value))    qs.append('operation', v);
  if ((v = document.getElementById('sc_date_from').value))    qs.append('scheduled_from', v);
  if ((v = document.getElementById('sc_date_to').value))      qs.append('scheduled_to', v);
  qs.append('page', String(page));
  qs.append('limit', '50');

  var tbody = document.getElementById('sc_tbody');
  tbody.innerHTML = '<tr><td colspan="8" class="empty-row">読み込み中…</td></tr>';

  fetch('api/system/master-scheduled-changes.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(function(res) {
      if (res.status === 401) { window.location.href = 'login.html'; throw new Error('unauthorized'); }
      if (res.status === 403) { throw new Error('権限がありません'); }
      return res.json();
    })
    .then(function(data) {
      if (!data.success) throw new Error(data.error || '取得失敗');
      st.page = data.pagination.page;
      st.limit = data.pagination.limit;
      st.total = data.pagination.total;
      st.totalPages = data.pagination.total_pages;
      lastResults['scheduled'] = data.data;
      renderScheduledTable(data.data);
      renderPagination('scheduled', 'sc_info', 'sc_prev', 'sc_next', 'sc_pageInd');
    })
    .catch(function(err) {
      tbody.innerHTML = '<tr><td colspan="8" class="empty-row">読み込みエラー: ' + escapeHtml(err.message) + '</td></tr>';
    });
}

function renderScheduledTable(rows) {
  var tbody = document.getElementById('sc_tbody');
  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-row">該当データがありません</td></tr>';
    return;
  }
  var html = '';
  rows.forEach(function(r, idx) {
    var json = tryParseJson(r.change_data);
    var summary = summarizeChangeData(json);
    var who = r.created_by_name
      ? escapeHtml(r.created_by_name) + '<br><span style="color:#94a3b8;font-size:11px">' + escapeHtml(r.created_by_login_id || '') + '</span>'
      : '<span style="color:#94a3b8">—</span>';
    html += '<tr>'
      + '<td class="col-datetime">' + escapeHtml(fmtDateTime(r.scheduled_at)) + '</td>'
      + '<td><span class="badge badge-status-' + escapeHtml(r.status) + '">' + escapeHtml(STATUS_LABELS[r.status] || r.status) + '</span></td>'
      + '<td><span class="badge badge-table">' + escapeHtml(TABLE_LABELS[r.target_table] || r.target_table) + '</span></td>'
      + '<td><span class="badge badge-op-' + escapeHtml(r.operation) + '">' + escapeHtml(OPERATION_LABELS[r.operation] || r.operation) + '</span></td>'
      + '<td class="col-key">' + escapeHtml(r.record_key) + '</td>'
      + '<td class="col-summary">' + escapeHtml(summary) + '</td>'
      + '<td>' + who + '</td>'
      + '<td><button type="button" class="btn-detail" onclick="showScheduledDetail(' + idx + ')">詳細</button></td>'
      + '</tr>';
  });
  tbody.innerHTML = html;
}

function showScheduledDetail(idx) {
  var r = lastResults['scheduled'][idx];
  if (!r) return;
  var json = tryParseJson(r.change_data);
  var html = '';
  html += '<div class="detail-section">'
    + '<div class="detail-section-title">基本情報</div>'
    + '<div class="detail-grid">'
    + '<div class="key">予定日時</div><div class="val">' + escapeHtml(r.scheduled_at || '') + '</div>'
    + '<div class="key">状態</div><div class="val"><span class="badge badge-status-' + escapeHtml(r.status) + '">' + escapeHtml(STATUS_LABELS[r.status] || r.status) + '</span></div>'
    + '<div class="key">反映実行日時</div><div class="val">' + escapeHtml(r.applied_at || '—') + '</div>'
    + '<div class="key">対象テーブル</div><div class="val">' + escapeHtml(TABLE_LABELS[r.target_table] || r.target_table) + ' (' + escapeHtml(r.target_table) + ')</div>'
    + '<div class="key">操作</div><div class="val">' + escapeHtml(OPERATION_LABELS[r.operation] || r.operation) + '</div>'
    + '<div class="key">レコードキー</div><div class="val">' + escapeHtml(r.record_key) + '</div>'
    + '<div class="key">登録者</div><div class="val">' + (r.created_by_name ? escapeHtml(r.created_by_name) + ' (' + escapeHtml(r.created_by_login_id || '') + ')' : '<span style="color:#94a3b8">—</span>') + '</div>'
    + '<div class="key">登録日時</div><div class="val">' + escapeHtml(r.created_at || '') + '</div>'
    + '</div></div>';

  if (r.error_message) {
    html += '<div class="detail-section">'
      + '<div class="detail-section-title">エラー情報</div>'
      + '<div class="raw-json" style="color:#991b1b;background:#fef2f2;border-color:#fecaca">' + escapeHtml(r.error_message) + '</div>'
      + '</div>';
  }

  html += renderChangeDataDetail(json, r.operation);

  openAuditModal('予約変更 #' + r.id, html);
}

// ===== Tab 3: ログイン履歴 =====
function loadLoginHistory(page) {
  if (page < 1) page = 1;
  var st = window.__auditState['login'];
  if (page > st.totalPages && st.totalPages >= 1) page = st.totalPages;

  var qs = new URLSearchParams();
  var v;
  if ((v = document.getElementById('lh_login_id').value)) qs.append('login_id', v);
  if ((v = document.getElementById('lh_success').value))  qs.append('success', v);
  if ((v = document.getElementById('lh_date_from').value)) qs.append('date_from', v);
  if ((v = document.getElementById('lh_date_to').value))   qs.append('date_to', v);
  qs.append('page', String(page));
  qs.append('limit', '50');

  var tbody = document.getElementById('lh_tbody');
  tbody.innerHTML = '<tr><td colspan="7" class="empty-row">読み込み中…</td></tr>';

  fetch('api/system/login-history.php?' + qs.toString(), { credentials: 'same-origin' })
    .then(function(res) {
      if (res.status === 401) { window.location.href = 'login.html'; throw new Error('unauthorized'); }
      if (res.status === 403) { throw new Error('権限がありません'); }
      return res.json();
    })
    .then(function(data) {
      if (!data.success) throw new Error(data.error || '取得失敗');
      st.page = data.pagination.page;
      st.limit = data.pagination.limit;
      st.total = data.pagination.total;
      st.totalPages = data.pagination.total_pages;
      lastResults['login'] = data.data;
      renderLoginHistoryTable(data.data);
      renderPagination('login', 'lh_info', 'lh_prev', 'lh_next', 'lh_pageInd');
    })
    .catch(function(err) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-row">読み込みエラー: ' + escapeHtml(err.message) + '</td></tr>';
    });
}

function renderLoginHistoryTable(rows) {
  var tbody = document.getElementById('lh_tbody');
  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty-row">該当データがありません</td></tr>';
    return;
  }
  var html = '';
  rows.forEach(function(r, idx) {
    var ok = String(r.success) === '1' || r.success === 1;
    var reasonOrUa = ok
      ? (r.user_agent ? '<span style="color:#94a3b8;font-size:11px">' + escapeHtml(String(r.user_agent).substring(0, 80)) + (String(r.user_agent).length > 80 ? '...' : '') + '</span>' : '')
      : '<span style="color:#991b1b">' + escapeHtml(FAILURE_REASON_LABELS[r.failure_reason] || r.failure_reason || '—') + '</span>';
    html += '<tr>'
      + '<td class="col-datetime">' + escapeHtml(fmtDateTime(r.attempted_at)) + '</td>'
      + '<td>' + (ok ? '<span class="badge badge-success">成功</span>' : '<span class="badge badge-fail">失敗</span>') + '</td>'
      + '<td class="col-key">' + escapeHtml(r.login_id) + '</td>'
      + '<td>' + (r.user_name ? escapeHtml(r.user_name) : '<span style="color:#94a3b8">—</span>') + '</td>'
      + '<td class="col-key">' + escapeHtml(r.ip_address || '—') + '</td>'
      + '<td class="col-summary">' + reasonOrUa + '</td>'
      + '<td><button type="button" class="btn-detail" onclick="showLoginDetail(' + idx + ')">詳細</button></td>'
      + '</tr>';
  });
  tbody.innerHTML = html;
}

function showLoginDetail(idx) {
  var r = lastResults['login'][idx];
  if (!r) return;
  var ok = String(r.success) === '1' || r.success === 1;
  var html = '';
  html += '<div class="detail-section">'
    + '<div class="detail-section-title">基本情報</div>'
    + '<div class="detail-grid">'
    + '<div class="key">試行日時</div><div class="val">' + escapeHtml(r.attempted_at || '') + '</div>'
    + '<div class="key">結果</div><div class="val">' + (ok ? '<span class="badge badge-success">成功</span>' : '<span class="badge badge-fail">失敗</span>') + '</div>'
    + '<div class="key">ログインID</div><div class="val">' + escapeHtml(r.login_id) + '</div>'
    + '<div class="key">ユーザー名</div><div class="val">' + (r.user_name ? escapeHtml(r.user_name) : '<span style="color:#94a3b8">—</span>') + '</div>'
    + '<div class="key">ユーザーID</div><div class="val">' + (r.user_id != null ? escapeHtml(r.user_id) : '<span style="color:#94a3b8">—</span>') + '</div>'
    + '<div class="key">IPアドレス</div><div class="val">' + escapeHtml(r.ip_address || '—') + '</div>'
    + (ok ? '' : '<div class="key">失敗理由</div><div class="val" style="color:#991b1b">' + escapeHtml(FAILURE_REASON_LABELS[r.failure_reason] || r.failure_reason || '—') + '</div>')
    + '</div></div>';

  html += '<div class="detail-section">'
    + '<div class="detail-section-title">User-Agent</div>'
    + '<div class="raw-json">' + escapeHtml(r.user_agent || '(なし)') + '</div>'
    + '</div>';

  openAuditModal('ログイン履歴 #' + r.id, html);
}

// ===== change_data → 差分テーブル =====
function renderChangeDataDetail(json, operation) {
  if (!json) {
    return '<div class="detail-section"><div class="detail-section-title">変更内容</div><div style="color:#94a3b8">（データなし）</div></div>';
  }
  var html = '<div class="detail-section"><div class="detail-section-title">変更内容</div>';

  // 差分テーブル化できる場合
  if (json.before && json.after) {
    var fields = json.changed_fields && json.changed_fields.length > 0
      ? json.changed_fields
      : Array.from(new Set(Object.keys(json.before).concat(Object.keys(json.after))));

    if (fields.length === 0) {
      html += '<div style="color:#94a3b8">（変更フィールドなし）</div>';
    } else {
      html += '<table class="diff-table"><thead><tr><th>項目</th><th>変更前</th><th>変更後</th></tr></thead><tbody>';
      fields.forEach(function(f) {
        var bv = json.before[f];
        var av = json.after[f];
        html += '<tr>'
          + '<td class="col-field">' + escapeHtml(f) + '</td>'
          + '<td class="val-before">' + renderValue(bv) + '</td>'
          + '<td class="val-after">' + renderValue(av) + '</td>'
          + '</tr>';
      });
      html += '</tbody></table>';
    }
  } else if (json.after) {
    // insert
    html += '<table class="diff-table"><thead><tr><th>項目</th><th>値</th></tr></thead><tbody>';
    Object.keys(json.after).forEach(function(f) {
      html += '<tr>'
        + '<td class="col-field">' + escapeHtml(f) + '</td>'
        + '<td class="val-after">' + renderValue(json.after[f]) + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
  } else if (json.before) {
    // delete
    html += '<table class="diff-table"><thead><tr><th>項目</th><th>値（削除前）</th></tr></thead><tbody>';
    Object.keys(json.before).forEach(function(f) {
      html += '<tr>'
        + '<td class="col-field">' + escapeHtml(f) + '</td>'
        + '<td class="val-before">' + renderValue(json.before[f]) + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
  }
  html += '</div>';

  // raw JSON
  html += '<div class="detail-section">'
    + '<div class="detail-section-title">Raw JSON</div>'
    + '<div class="raw-json">' + escapeHtml(JSON.stringify(json, null, 2)) + '</div>'
    + '</div>';

  return html;
}

function renderValue(v) {
  if (v === null || v === undefined || v === '') {
    return '<span class="val-empty">(空)</span>';
  }
  if (typeof v === 'object') {
    return '<span style="font-family:monospace;font-size:11px">' + escapeHtml(JSON.stringify(v)) + '</span>';
  }
  return escapeHtml(String(v));
}

// ===== Modal =====
function openAuditModal(title, bodyHtml) {
  document.getElementById('auditModalTitle').textContent = title;
  document.getElementById('auditModalBody').innerHTML = bodyHtml;
  document.getElementById('auditModal').classList.add('visible');
}
function closeAuditModal() {
  // ダイアログ内クリックは event.stopPropagation で届かないので、ここに来た時点で閉じる
  document.getElementById('auditModal').classList.remove('visible');
}

// ===== 初期化 =====
document.addEventListener('DOMContentLoaded', function() {
  // common-nav.js の userLoaded イベントを待ち、system 以外は弾く
  window.addEventListener('userLoaded', function(e) {
    var user = e.detail;
    if (user.role !== 'system') {
      alert('このページはシステム管理者専用です');
      window.location.href = 'menu.html';
      return;
    }
    // 初回タブ (change-log) をロード
    window.__auditState['change-log']._loaded = true;
    loadChangeLog(1);
  });

  // ESC でモーダル閉じる
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('auditModal').classList.contains('visible')) {
      closeAuditModal();
    }
  });
});
