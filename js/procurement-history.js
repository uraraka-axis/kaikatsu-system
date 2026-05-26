document.addEventListener('DOMContentLoaded', function() {
  'use strict';

  var viewMode = 'store';
  var currentUser = null;
  var requests = [];
  var summary = {};
  var shops = [];

  // 2026-05-22 schema migration: カテゴリは categories.code を直接使用（fit→fitness, ig→golf 廃止）
  var categoryLabels = {};
  var statusLabels = { 'approved': '承認', 'pending': '申請中', 'rejected': '却下' };
  var statusClass = { 'approved': 'status-approved', 'pending': 'status-pending', 'rejected': 'status-rejected' };

  // ===== 初期化 =====
  function init() {
    fetch('api/me.php', { credentials: 'same-origin' })
      .then(function(r) {
        if (r.status === 401) { location.href = 'login.html'; return; }
        return r.json();
      })
      .then(function(data) {
        if (!data || !data.success) return;
        currentUser = data.user;
        viewMode = currentUser.role === 'admin' ? 'admin' : 'store';
        setupView();
        // カテゴリ → 年度の順に動的ロード後、データ取得
        loadCategories()
          .then(populateYearFilter)
          .then(function() {
            if (viewMode === 'admin') {
              loadShops().then(function() { loadData(); });
            } else {
              loadData();
            }
          });
      })
      .catch(function() { location.href = 'login.html'; });
  }

  // ===== 画面セットアップ =====
  function setupView() {
    // ヘッダのユーザー名は common-nav.js が設定するため、ここでは設定しない
    // （以前は user.name + '様' を独自に上書きしていたが、admin の name は組織名
    //  「商品部」なので「商品部様」と表示されてしまうため共通実装に統一）

    // 店舗ユーザーのみ申請フォーム表示
    document.getElementById('procurementSection').style.display = viewMode === 'store' ? '' : 'none';

    buildFilters();
    buildTableHeader();
  }

  // ===== 店舗一覧読み込み（管理者用フィルタ） =====
  function loadShops() {
    return fetch('api/master/shops.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) shops = data.data || [];
      });
  }

  // ===== カテゴリ動的ロード（店舗ユーザーは自店所属、admin は全件） =====
  // 申請フォーム(procCategory) と フィルタ(filterCategory) の両方に反映
  function loadCategories() {
    return fetch('api/master/categories.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.success) return;
        var cats = data.data || [];
        cats.forEach(function(c) {
          categoryLabels[c.code] = c.name;
        });
        // 申請フォームのカテゴリプルダウンを動的構築
        appendCategoryOptions(document.getElementById('procCategory'), cats);
        // フィルタのカテゴリプルダウンを動的構築（buildFilters 実行後の場合のみ）
        appendCategoryOptions(document.getElementById('filterCategory'), cats);
      })
      .catch(function(e) { console.error('Failed to fetch categories:', e); });
  }

  // option[0]（「選択してください」「すべてのカテゴリ」など）を残して以降を作り直す
  function appendCategoryOptions(sel, cats) {
    if (!sel) return;
    while (sel.options.length > 1) sel.remove(1);
    cats.forEach(function(c) {
      var opt = document.createElement('option');
      opt.value = c.code;
      opt.textContent = c.name;
      sel.appendChild(opt);
    });
  }

  // ===== 年度プルダウン動的ロード（実際に申請データが存在する年度のみ） =====
  function populateYearFilter() {
    return fetch('api/procurement.php?action=years', { credentials: 'same-origin' })
      .then(function(r) {
        if (r.status === 401) { location.href = 'login.html'; return; }
        return r.json();
      })
      .then(function(data) {
        var sel = document.getElementById('filterYear');
        if (!sel) return;
        var years = (data && data.years) || [];
        if (years.length === 0) {
          sel.innerHTML = '<option value="">（年度データなし）</option>';
          return;
        }
        var html = '';
        years.forEach(function(y, i) {
          html += '<option value="' + y + '"' + (i === 0 ? ' selected' : '') + '>' + y + '年度</option>';
        });
        sel.innerHTML = html;
      })
      .catch(function(e) { console.error('Failed to fetch fiscal years:', e); });
  }

  // ===== フィルタ構築 =====
  // 発注一覧 / 予算管理と同じ共通レイアウト (.admin-filter-bar > .admin-filter-row > .filter-group)
  // 中身（option）は populateYearFilter / loadCategories が後から注入する
  function buildFilters() {
    var groups = '';

    if (viewMode === 'admin') {
      groups +=
        '<div class="filter-group">' +
          '<span class="filter-label">店舗</span>' +
          '<select class="form-select" id="filterShop"><option value="">すべて</option></select>' +
        '</div>';
    }
    groups +=
      '<div class="filter-group">' +
        '<span class="filter-label">年度</span>' +
        '<select class="form-select" id="filterYear"></select>' +
      '</div>' +
      '<div class="filter-group">' +
        '<span class="filter-label">カテゴリ</span>' +
        '<select class="form-select" id="filterCategory">' +
          '<option value="">すべてのカテゴリ</option>' +
        '</select>' +
      '</div>';

    document.getElementById('filterBar').innerHTML =
      '<div class="admin-filter-row">' + groups + '</div>';

    // イベント設定
    document.getElementById('filterYear').addEventListener('change', loadData);
    document.getElementById('filterCategory').addEventListener('change', loadData);
    if (viewMode === 'admin') {
      document.getElementById('filterShop').addEventListener('change', loadData);
    }
  }

  function populateShopFilter() {
    if (viewMode !== 'admin') return;
    var sel = document.getElementById('filterShop');
    if (!sel) return;
    var html = '<option value="">すべての店舗</option>';
    shops.forEach(function(s) {
      html += '<option value="' + s.shop_code + '">' + s.shop_name + '</option>';
    });
    sel.innerHTML = html;
  }

  // ===== テーブルヘッダ =====
  function buildTableHeader() {
    var html = '<tr><th>申請番号</th>';
    if (viewMode === 'admin') html += '<th>店舗</th>';
    html += '<th>カテゴリ</th><th class="right">金額</th><th>理由</th><th>申請日</th><th>ステータス</th></tr>';
    document.getElementById('tableHead').innerHTML = html;
  }

  // ===== データ読み込み =====
  function loadData() {
    var year = document.getElementById('filterYear').value;
    var category = document.getElementById('filterCategory').value;
    var params = 'year=' + encodeURIComponent(year);
    if (category) params += '&category=' + encodeURIComponent(category);
    if (viewMode === 'admin') {
      var shop = document.getElementById('filterShop').value;
      if (shop) params += '&shop=' + encodeURIComponent(shop);
      populateShopFilter();
    }

    if (typeof window.showLoading === 'function') window.showLoading('自店調達申請を読み込み中…');
    fetch('api/procurement.php?' + params, { credentials: 'same-origin' })
      .then(function(r) {
        if (r.status === 401) { location.href = 'login.html'; return; }
        return r.json();
      })
      .then(function(data) {
        if (!data || !data.success) {
          requests = [];
          summary = {};
          renderSummary();
          renderTable();
          return;
        }
        requests = data.data || [];
        summary = data.summary || {};
        renderSummary();
        renderTable();
      })
      .catch(function(err) {
        console.error('データ取得エラー:', err);
      })
      .finally(function() {
        if (typeof window.hideLoading === 'function') window.hideLoading();
      });
  }

  // ===== サマリー表示 =====
  function renderSummary() {
    var s = summary;
    document.getElementById('summaryBar').innerHTML =
      '<div class="summary-item">' +
        '<div class="summary-label">総件数</div>' +
        '<div class="summary-value">' + (s.total_count || 0) + '</div>' +
        '<div class="summary-count">¥' + (s.total_amount || 0).toLocaleString() + '</div>' +
      '</div>' +
      '<div class="summary-item">' +
        '<div class="summary-label">フィットネス</div>' +
        '<div class="summary-value">' + (s.fit_count || 0) + '</div>' +
        '<div class="summary-count">¥' + (s.fit_amount || 0).toLocaleString() + '</div>' +
      '</div>' +
      '<div class="summary-item">' +
        '<div class="summary-label">インドアゴルフ</div>' +
        '<div class="summary-value">' + (s.golf_count || 0) + '</div>' +
        '<div class="summary-count">¥' + (s.golf_amount || 0).toLocaleString() + '</div>' +
      '</div>';
  }

  // ===== テーブル表示 =====
  function renderTable() {
    var tbody = document.getElementById('tableBody');
    var colCount = viewMode === 'admin' ? 7 : 6;

    if (!requests.length) {
      tbody.innerHTML = '<tr><td colspan="' + colCount + '"><div class="empty-state">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
        '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
        '<p>該当する申請がありません</p></div></td></tr>';
      return;
    }

    tbody.innerHTML = requests.map(function(r) {
      var catLabel = categoryLabels[r.category_code] || r.category_code;
      var stLabel = statusLabels[r.status] || r.status;
      var stClass = statusClass[r.status] || 'status-pending';
      var row = '<tr>';
      row += '<td><strong>' + escapeHtml(r.id) + '</strong></td>';
      if (viewMode === 'admin') row += '<td>' + escapeHtml(r.shop_name) + '</td>';
      row += '<td>' + catLabel + '</td>';
      row += '<td class="right">¥' + Number(r.amount).toLocaleString() + '</td>';
      row += '<td>' + escapeHtml(r.reason || '') + '</td>';
      row += '<td>' + r.date + '</td>';
      row += '<td><span class="status-badge ' + stClass + '">' + stLabel + '</span></td>';
      row += '</tr>';
      return row;
    }).join('');
  }

  // ===== 申請送信 =====
  window.submitProcurement = function() {
    var catSelect = document.getElementById('procCategory');
    var amountInput = document.getElementById('procAmount');
    var reasonInput = document.getElementById('procReason');

    // categories.code をそのまま使う（fit/ig 変換は廃止）
    var categoryCode = catSelect.value;
    if (!categoryCode) {
      showNotify('error', '入力エラー', 'カテゴリを選択してください。');
      return;
    }
    // カンマ区切り入力にも対応
    var amount = parseInt(String(amountInput.value).replace(/,/g, ''), 10);
    var reason = reasonInput.value.trim();

    if (!amount || amount <= 0) {
      showNotify('error', '入力エラー', '金額を正しく入力してください。');
      return;
    }
    if (!reason) {
      showNotify('error', '入力エラー', '理由を入力してください。');
      return;
    }

    var submitBtn = document.querySelector('.btn-submit');
    submitBtn.disabled = true;
    submitBtn.textContent = '送信中...';

    fetch('api/procurement.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        category_code: categoryCode,
        amount: amount,
        reason: reason
      })
    })
      .then(function(r) {
        if (r.status === 401) { location.href = 'login.html'; return; }
        return r.json();
      })
      .then(function(data) {
        submitBtn.disabled = false;
        submitBtn.textContent = '申請する';
        if (!data) return;
        if (!data.success) {
          showNotify('error', '申請エラー', data.error || '申請に失敗しました');
          return;
        }
        showNotify('success', '自店調達を申請しました',
          '申請番号: <span class="notify-order-id">' + data.data.id + '</span>');
        amountInput.value = '';
        reasonInput.value = '';
        loadData();
      })
      .catch(function(err) {
        submitBtn.disabled = false;
        submitBtn.textContent = '申請する';
        console.error('申請エラー:', err);
        showNotify('error', '通信エラー', 'サーバーとの通信に失敗しました。<br>ネットワーク接続を確認してください。');
      });
  };

  // ===== ユーティリティ =====
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ===== 起動 =====
  init();
});
