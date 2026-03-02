    var params = new URLSearchParams(window.location.search);
    var viewMode = params.get('role') === 'admin' ? 'admin' : 'store';

    // Store view: own store only
    var storeRequests = [
      { id: 'REQ-2026-001', shop: '新宿東口', category: 'fitness', amount: 15000, reason: '緊急でマットを購入', date: '2026-02-26' },
      { id: 'REQ-2026-002', shop: '新宿東口', category: 'golf', amount: 12000, reason: 'ティーが不足しているため', date: '2026-02-24' },
      { id: 'REQ-2026-003', shop: '新宿東口', category: 'fitness', amount: 20000, reason: 'ダンベルの追加購入', date: '2026-02-22' },
      { id: 'REQ-2026-004', shop: '新宿東口', category: 'golf', amount: 8000, reason: 'グローブの補充', date: '2026-02-20' },
      { id: 'REQ-2026-005', shop: '新宿東口', category: 'fitness', amount: 10000, reason: '消毒スプレーの大量購入', date: '2026-02-18' },
    ];

    // Admin view: all stores
    var allRequests = [
      { id: 'REQ-2026-001', shop: '新宿東口', category: 'fitness', amount: 15000, reason: '緊急でマットを購入', date: '2026-02-26' },
      { id: 'REQ-2026-002', shop: '新宿東口', category: 'golf', amount: 12000, reason: 'ティーが不足しているため', date: '2026-02-24' },
      { id: 'REQ-2026-003', shop: '新宿東口', category: 'fitness', amount: 20000, reason: 'ダンベルの追加購入', date: '2026-02-22' },
      { id: 'REQ-2026-004', shop: '新宿東口', category: 'golf', amount: 8000, reason: 'グローブの補充', date: '2026-02-20' },
      { id: 'REQ-2026-005', shop: '新宿東口', category: 'fitness', amount: 10000, reason: '消毒スプレーの大量購入', date: '2026-02-18' },
      { id: 'REQ-2026-006', shop: '10101:札幌', category: 'fitness', amount: 18000, reason: 'ヨガマット追加', date: '2026-02-25' },
      { id: 'REQ-2026-007', shop: '10101:札幌', category: 'golf', amount: 5000, reason: 'ゴルフボール補充', date: '2026-02-20' },
      { id: 'REQ-2026-008', shop: '10102:函館', category: 'fitness', amount: 22000, reason: 'ストレッチポール購入', date: '2026-02-23' },
      { id: 'REQ-2026-009', shop: '10201:弘前', category: 'golf', amount: 9500, reason: 'パター用グリップ交換', date: '2026-02-19' },
    ];

    function getRequests() {
      return viewMode === 'admin' ? allRequests : storeRequests;
    }

    function initView() {
      var header = document.querySelector('.header-user');
      if (viewMode === 'admin') {
        header.textContent = '本部管理者：鈴木一郎様';
        document.getElementById('pageDesc').textContent = '全店舗の自店調達実績を管理します';
      }

      // Build filters
      var filterHtml = '';
      if (viewMode === 'admin') {
        filterHtml += '<select class="form-select" id="filterShop" onchange="renderAll()">' +
          '<option value="">すべての店舗</option>';
        var shops = [];
        allRequests.forEach(function(r) { if (shops.indexOf(r.shop) === -1) shops.push(r.shop); });
        shops.forEach(function(s) { filterHtml += '<option value="' + s + '">' + s + '</option>'; });
        filterHtml += '</select>';
      }
      filterHtml += '<select class="form-select" id="filterCategory" onchange="renderAll()">' +
        '<option value="">すべてのカテゴリ</option>' +
        '<option value="fitness">フィットネス</option>' +
        '<option value="golf">インドアゴルフ</option>' +
        '</select>';
      filterHtml += '<select class="form-select" id="filterYear" onchange="renderAll()">' +
        '<option value="2025" selected>2025年度</option>' +
        '<option value="2024">2024年度</option>' +
        '</select>';
      document.getElementById('filterBar').innerHTML = filterHtml;

      // Build table header
      var thHtml = '<tr>';
      thHtml += '<th>申請番号</th>';
      if (viewMode === 'admin') thHtml += '<th>店舗</th>';
      thHtml += '<th>カテゴリ</th><th class="right">金額</th><th>理由</th><th>申請日</th><th>ステータス</th>';
      thHtml += '</tr>';
      document.getElementById('tableHead').innerHTML = thHtml;

      renderAll();
    }

    function renderAll() {
      renderSummary();
      renderTable();
    }

    function getFiltered() {
      var data = getRequests();
      var catFilter = document.getElementById('filterCategory').value;
      var shopFilter = viewMode === 'admin' ? document.getElementById('filterShop').value : '';
      return data.filter(function(r) {
        if (catFilter && r.category !== catFilter) return false;
        if (shopFilter && r.shop !== shopFilter) return false;
        return true;
      });
    }

    function renderSummary() {
      var filtered = getFiltered();
      var totalCount = filtered.length;
      var totalAmount = 0;
      var fitCount = 0, fitAmount = 0, golfCount = 0, golfAmount = 0;
      filtered.forEach(function(r) {
        totalAmount += r.amount;
        if (r.category === 'fitness') { fitCount++; fitAmount += r.amount; }
        else { golfCount++; golfAmount += r.amount; }
      });

      document.getElementById('summaryBar').innerHTML =
        '<div class="summary-item">' +
          '<div class="summary-label">総件数</div>' +
          '<div class="summary-value">' + totalCount + '</div>' +
          '<div class="summary-count">¥' + totalAmount.toLocaleString() + '</div>' +
        '</div>' +
        '<div class="summary-item">' +
          '<div class="summary-label">フィットネス</div>' +
          '<div class="summary-value">' + fitCount + '</div>' +
          '<div class="summary-count">¥' + fitAmount.toLocaleString() + '</div>' +
        '</div>' +
        '<div class="summary-item">' +
          '<div class="summary-label">インドアゴルフ</div>' +
          '<div class="summary-value">' + golfCount + '</div>' +
          '<div class="summary-count">¥' + golfAmount.toLocaleString() + '</div>' +
        '</div>';
    }

    function renderTable() {
      var filtered = getFiltered();
      var tbody = document.getElementById('tableBody');
      var colCount = viewMode === 'admin' ? 7 : 6;

      if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="' + colCount + '"><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><p>該当する申請がありません</p></div></td></tr>';
        return;
      }

      tbody.innerHTML = filtered.map(function(r) {
        var catLabel = r.category === 'fitness' ? 'フィットネス' : 'インドアゴルフ';
        var row = '<tr>';
        row += '<td><strong>' + r.id + '</strong></td>';
        if (viewMode === 'admin') row += '<td>' + r.shop + '</td>';
        row += '<td>' + catLabel + '</td>';
        row += '<td class="right">¥' + r.amount.toLocaleString() + '</td>';
        row += '<td>' + r.reason + '</td>';
        row += '<td>' + r.date + '</td>';
        row += '<td><span class="status-badge status-approved">承認</span></td>';
        row += '</tr>';
        return row;
      }).join('');
    }

    initView();
