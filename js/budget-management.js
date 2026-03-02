    // ===== Role Detection =====
    var params = new URLSearchParams(window.location.search);
    var viewMode = params.get('role') === 'admin' ? 'admin' : 'store';

    var fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

    var departments = [
      { key: 'all', label: '全体' },
      { key: 'fit', label: 'フィットネス' },
      { key: 'ig',  label: 'インドアゴルフ' }
    ];

    // ===== Store View: single store data =====
    var storeBudgetData = [
      {
        shop: '新宿東口', zone: '100', area: '103', shopCode: '10301',
        period: [260000, 45800, 214200, 17.6],
        midterm: [170000, 45800, 124200, 26.9],
        month: [20000, 12500, 7500, 62.5],
        details: {
          all: [[21600,0],[21700,0],[21800,3200],[21900,5400],[22000,8500],[22100,12000],[22200,7300],[22300,4800],[22400,2100],[22500,12500],[22600,0],[22700,0]],
          fit: [[13000,0],[13000,0],[13000,2000],[13000,3400],[13000,5500],[13000,8000],[13000,4300],[13000,2800],[13000,1100],[13000,8500],[13000,0],[13000,0]],
          ig:  [[8600,0],[8700,0],[8800,1200],[8900,2000],[9000,3000],[9100,4000],[9200,3000],[9300,2000],[9400,1000],[9500,4000],[9600,0],[9700,0]]
        }
      }
    ];

    // ===== Admin View: all stores data =====
    var adminBudgetData = [
      {
        shop: '10101:札幌', zone: '100', area: '101', shopCode: '10101',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10102:函館', zone: '100', area: '101', shopCode: '10102',
        period: [130000, 1700, 128300, 1.3],
        midterm: [85000, 1700, 83300, 2.0],
        month: [10000, 1700, 8300, 17.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,20000],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,15000],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,5000],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10103:旭川', zone: '100', area: '101', shopCode: '10103',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10201:弘前', zone: '100', area: '102', shopCode: '10201',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10202:盛岡', zone: '100', area: '102', shopCode: '10202',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      }
    ];

    // ===== Shop list for cascade filter =====
    var shopsByArea = {
      '101': ['10101:札幌', '10102:函館', '10103:旭川'],
      '102': ['10201:弘前', '10202:盛岡']
    };

    function getFilteredData() {
      var data = viewMode === 'admin' ? adminBudgetData : storeBudgetData;
      if (viewMode !== 'admin') return data;
      var zone = document.getElementById('filterZone').value;
      var area = document.getElementById('filterArea').value;
      var shop = document.getElementById('filterShop').value;
      return data.filter(function(d) {
        if (zone && d.zone !== zone) return false;
        if (area && d.area !== area) return false;
        if (shop && d.shopCode !== shop) return false;
        return true;
      });
    }

    function fmt(n) { return n.toLocaleString(); }

    function getProgressColor(rate) {
      if (rate >= 90) return 'red';
      if (rate >= 60) return 'yellow';
      return 'green';
    }

    // ===== Cascade: Area -> Shop =====
    function updateShopOptions() {
      var area = document.getElementById('filterArea').value;
      var shopSelect = document.getElementById('filterShop');
      shopSelect.innerHTML = '<option value="">店舗</option>';
      if (area && shopsByArea[area]) {
        shopsByArea[area].forEach(function(s) {
          var code = s.split(':')[0];
          shopSelect.innerHTML += '<option value="' + code + '">' + s + '</option>';
        });
      }
    }

    // ===== Filter changed (no alert, instant update) =====
    function filterBudget() {
      renderSummary();
      renderTable();
    }

    function onAreaChange() {
      updateShopOptions();
      filterBudget();
    }

    // ===== Init =====
    function initView() {
      document.getElementById('adminToolbar').style.display = viewMode === 'admin' ? '' : 'none';
      document.getElementById('storeToolbar').style.display = viewMode === 'store' ? '' : 'none';

      var header = document.querySelector('.header-user');
      if (viewMode === 'admin') {
        header.textContent = '本部管理者：鈴木一郎様';
      } else {
        header.textContent = '新宿東口店：田中太郎様';
      }

      var desc = document.getElementById('pageDesc');
      if (viewMode === 'store') {
        desc.textContent = '自店の予算・実績・消化状況を確認できます。行をクリックすると月別明細を表示します。';
      }

      if (viewMode === 'admin') {
        updateShopOptions();
      }

      renderSummary();
      renderTable();
    }

    // ===== Summary: reflects filtered data =====
    function renderSummary() {
      var data = getFilteredData();
      var totPeriod = [0,0,0];
      var totMonth = [0,0,0];
      data.forEach(function(d) {
        totPeriod[0] += d.period[0]; totPeriod[1] += d.period[1]; totPeriod[2] += d.period[2];
        totMonth[0] += d.month[0]; totMonth[1] += d.month[1]; totMonth[2] += d.month[2];
      });
      var monthRate = totMonth[0] > 0 ? (totMonth[1] / totMonth[0] * 100) : 0;
      var color = getProgressColor(monthRate);

      var storeCount = data.length;
      var budgetLabel = viewMode === 'admin'
        ? '当期予算（' + storeCount + '店舗）'
        : '当期予算';

      document.getElementById('summaryCards').innerHTML =
        '<div class="summary-card">' +
          '<div class="summary-card-label">' + budgetLabel + '</div>' +
          '<div class="summary-card-value">¥' + fmt(totPeriod[0]) + '</div>' +
        '</div>' +
        '<div class="summary-card">' +
          '<div class="summary-card-label">当期実績</div>' +
          '<div class="summary-card-value">¥' + fmt(totPeriod[1]) + '</div>' +
        '</div>' +
        '<div class="summary-card">' +
          '<div class="summary-card-label">当期残高</div>' +
          '<div class="summary-card-value positive">¥' + fmt(totPeriod[2]) + '</div>' +
        '</div>' +
        '<div class="summary-card">' +
          '<div class="summary-card-label">当月実績</div>' +
          '<div class="summary-card-value">¥' + fmt(totMonth[1]) + '</div>' +
          '<div class="progress-bar"><div class="progress-fill ' + color + '" style="width:' + Math.min(monthRate, 100) + '%"></div></div>' +
          '<div class="summary-card-sub">当月消化率 ' + monthRate.toFixed(1) + '%</div>' +
        '</div>' +
        '<div class="summary-card">' +
          '<div class="summary-card-label">当月残高</div>' +
          '<div class="summary-card-value positive">¥' + fmt(totMonth[2]) + '</div>' +
        '</div>';
    }

    // ===== Table =====
    function renderTable() {
      var data = getFilteredData();
      var tbody = document.getElementById('budgetTableBody');
      var html = '';

      data.forEach(function(d, idx) {
        html += '<tr class="budget-row" onclick="toggleDetail(' + idx + ')" id="row-' + idx + '">';
        html += '<td><div class="shop-cell"><svg class="expand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>' + d.shop + '</div></td>';
        html += numCells(d.period);
        html += numCells(d.midterm);
        html += numCells(d.month);
        html += '</tr>';

        // Detail: all departments stacked vertically
        html += '<tr class="detail-row" id="detail-' + idx + '"><td colspan="13"><div class="detail-content">';
        html += '<div class="detail-header"><div class="detail-title">2025年度 月別明細 — ' + d.shop + '</div></div>';
        departments.forEach(function(dept) {
          html += '<div class="dept-section">';
          html += '<div class="dept-section-title">' + dept.label + '</div>';
          html += renderMonthlyTable(d.details[dept.key]);
          html += '</div>';
        });
        html += '</div></td></tr>';
      });

      // Total row (admin, multiple stores)
      if (viewMode === 'admin' && data.length > 1) {
        var totP = [0,0,0,0], totM = [0,0,0,0], totMo = [0,0,0,0];
        data.forEach(function(d) {
          for (var i = 0; i < 3; i++) { totP[i] += d.period[i]; totM[i] += d.midterm[i]; totMo[i] += d.month[i]; }
        });
        totP[3] = totP[0] > 0 ? (totP[1] / totP[0] * 100) : 0;
        totM[3] = totM[0] > 0 ? (totM[1] / totM[0] * 100) : 0;
        totMo[3] = totMo[0] > 0 ? (totMo[1] / totMo[0] * 100) : 0;
        html += '<tr class="total-row"><td>合計</td>';
        html += numCells(totP) + numCells(totM) + numCells(totMo);
        html += '</tr>';
      }

      tbody.innerHTML = html;
    }

    function numCells(arr) {
      var budget = arr[0], actual = arr[1], balance = arr[2], rate = arr[3];
      var balClass = balance < 0 ? ' class="amount-negative"' : '';
      var rateColor = getProgressColor(rate);
      return '<td>' + fmt(budget) + '</td>' +
             '<td>' + fmt(actual) + '</td>' +
             '<td' + balClass + '>' + fmt(balance) + '</td>' +
             '<td><div class="rate-cell"><div class="progress-bar-inline"><div class="progress-fill ' + rateColor + '" style="width:' + Math.min(rate, 100) + '%"></div></div>' + rate.toFixed(1) + '</div></td>';
    }

    function renderMonthlyTable(detail) {
      var h = '<table class="monthly-table"><thead><tr><th>項目</th>';
      fiscalMonths.forEach(function(m) { h += '<th>' + m + '月</th>'; });
      h += '<th class="col-total">合計</th></tr></thead><tbody>';

      var budgetTotal = 0;
      h += '<tr><td>予算（円）</td>';
      detail.forEach(function(d) { h += '<td>' + fmt(d[0]) + '</td>'; budgetTotal += d[0]; });
      h += '<td class="col-total">' + fmt(budgetTotal) + '</td></tr>';

      var actualTotal = 0;
      h += '<tr><td>実績（円）</td>';
      detail.forEach(function(d) { h += '<td>' + fmt(d[1]) + '</td>'; actualTotal += d[1]; });
      h += '<td class="col-total">' + fmt(actualTotal) + '</td></tr>';

      var balanceTotal = 0;
      h += '<tr><td>残高（円）</td>';
      detail.forEach(function(d) {
        var bal = d[0] - d[1];
        balanceTotal += bal;
        var cls = bal < 0 ? ' class="negative"' : '';
        h += '<td' + cls + '>' + fmt(bal) + '</td>';
      });
      var balTotalCls = balanceTotal < 0 ? ' negative' : '';
      h += '<td class="col-total' + balTotalCls + '">' + fmt(balanceTotal) + '</td></tr>';

      var overallRate = budgetTotal > 0 ? (actualTotal / budgetTotal * 100) : 0;
      h += '<tr><td>消化（%）</td>';
      detail.forEach(function(d) {
        var r = d[0] > 0 ? (d[1] / d[0] * 100) : 0;
        var cls = r > 100 ? ' class="over-budget"' : '';
        h += '<td' + cls + '>' + r.toFixed(1) + '</td>';
      });
      h += '<td class="col-total">' + overallRate.toFixed(1) + '</td></tr>';

      h += '</tbody></table>';
      return h;
    }

    function toggleDetail(idx) {
      var row = document.getElementById('row-' + idx);
      var detail = document.getElementById('detail-' + idx);
      if (row.classList.contains('expanded')) {
        row.classList.remove('expanded');
        detail.classList.remove('visible');
      } else {
        row.classList.add('expanded');
        detail.classList.add('visible');
      }
    }

    // ===== Init =====
    initView();
