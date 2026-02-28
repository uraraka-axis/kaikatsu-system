    // Fiscal year months (April to March)
    var fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

    // Sample budget data per store
    var budgetData = [
      {
        shop: '10101:札幌',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        detail: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]]
      },
      {
        shop: '10102:函館',
        period: [130000, 1700, 128300, 1.3],
        midterm: [85000, 1700, 83300, 2.0],
        month: [10000, 1700, 8300, 17.0],
        detail: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,20000],[11028,0],[11030,0]]
      },
      {
        shop: '10103:旭川',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        detail: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]]
      },
      {
        shop: '10201:弘前',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        detail: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]]
      },
      {
        shop: '10202:盛岡',
        period: [130000, 0, 130000, 0.0],
        midterm: [85000, 0, 85000, 0.0],
        month: [10000, 0, 10000, 0.0],
        detail: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]]
      }
    ];

    function fmt(n) { return n.toLocaleString(); }

    function getProgressColor(rate) {
      if (rate >= 90) return 'red';
      if (rate >= 60) return 'yellow';
      return 'green';
    }

    function renderSummary() {
      var totPeriod = [0,0,0];
      var totMidterm = [0,0,0];
      var totMonth = [0,0,0];
      budgetData.forEach(function(d) {
        totPeriod[0] += d.period[0]; totPeriod[1] += d.period[1]; totPeriod[2] += d.period[2];
        totMidterm[0] += d.midterm[0]; totMidterm[1] += d.midterm[1]; totMidterm[2] += d.midterm[2];
        totMonth[0] += d.month[0]; totMonth[1] += d.month[1]; totMonth[2] += d.month[2];
      });
      var monthRate = totMonth[0] > 0 ? (totMonth[1] / totMonth[0] * 100) : 0;
      var color = getProgressColor(monthRate);

      document.getElementById('summaryCards').innerHTML =
        '<div class="summary-card">' +
          '<div class="summary-card-label">当期予算（全店合計）</div>' +
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

    function renderTable() {
      var tbody = document.getElementById('budgetTableBody');
      var html = '';

      budgetData.forEach(function(d, idx) {
        // Main row
        html += '<tr class="budget-row" onclick="toggleDetail(' + idx + ')" id="row-' + idx + '">';
        html += '<td><div class="shop-cell"><svg class="expand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>' + d.shop + '</div></td>';
        // Period
        html += numCells(d.period);
        // Midterm
        html += numCells(d.midterm);
        // Monthly
        html += numCells(d.month);
        html += '</tr>';

        // Detail row (hidden)
        html += '<tr class="detail-row" id="detail-' + idx + '"><td colspan="13"><div class="detail-content">';
        html += '<div class="detail-title">2025年度 月別明細 — ' + d.shop + '</div>';
        html += renderMonthlyTable(d.detail);
        html += '</div></td></tr>';
      });

      // Total row
      var totP = [0,0,0,0], totM = [0,0,0,0], totMo = [0,0,0,0];
      budgetData.forEach(function(d) {
        for (var i = 0; i < 3; i++) { totP[i] += d.period[i]; totM[i] += d.midterm[i]; totMo[i] += d.month[i]; }
      });
      totP[3] = totP[0] > 0 ? (totP[1] / totP[0] * 100) : 0;
      totM[3] = totM[0] > 0 ? (totM[1] / totM[0] * 100) : 0;
      totMo[3] = totMo[0] > 0 ? (totMo[1] / totMo[0] * 100) : 0;
      html += '<tr class="total-row"><td>合計</td>';
      html += numCells(totP) + numCells(totM) + numCells(totMo);
      html += '</tr>';

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

      // Budget row
      var budgetTotal = 0;
      h += '<tr><td>予算（円）</td>';
      detail.forEach(function(d) { h += '<td>' + fmt(d[0]) + '</td>'; budgetTotal += d[0]; });
      h += '<td class="col-total">' + fmt(budgetTotal) + '</td></tr>';

      // Actual row
      var actualTotal = 0;
      h += '<tr><td>実績（円）</td>';
      detail.forEach(function(d) { h += '<td>' + fmt(d[1]) + '</td>'; actualTotal += d[1]; });
      h += '<td class="col-total">' + fmt(actualTotal) + '</td></tr>';

      // Balance row
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

      // Rate row
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

    function filterBudget() {
      alert('検索条件で絞り込みます（モックアップ）\n\nゾーン: ' +
        document.getElementById('filterZone').value + '\nエリア: ' +
        document.getElementById('filterArea').value + '\n部門: ' +
        document.getElementById('filterDept').value + '\n年度: ' +
        document.getElementById('filterYear').value);
    }

    // Initial render
    renderSummary();
    renderTable();
