    // ===== Role Detection =====
    var params = new URLSearchParams(window.location.search);
    var viewMode = params.get('role') === 'admin' ? 'admin' : 'store';
    var storeShopCode = '10301'; // store view user's shop

    var fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

    var departments = [
      { key: 'all', label: '全体' },
      { key: 'fit', label: 'フィットネス' },
      { key: 'ig',  label: 'インドアゴルフ' }
    ];

    // ===== Zone / Area / Shop master =====
    var areasByZone = {
      '100': [['101','101:北海道'],['102','102:東北'],['103','103:関東']],
      '200': [['201','201:関西'],['202','202:中国']]
    };
    var shopsByArea = {
      '101': ['10101:札幌','10102:函館','10103:旭川'],
      '102': ['10201:仙台','10202:盛岡'],
      '103': ['10301:新宿東口','10302:池袋西口','10303:横浜'],
      '201': ['20101:梅田','20102:難波'],
      '202': ['20201:広島']
    };

    // ===== Helpers =====
    function fmt(n) { return n.toLocaleString(); }
    function getProgressColor(rate) {
      if (rate >= 90) return 'red';
      if (rate >= 60) return 'yellow';
      return 'green';
    }

    // Fiscal month index (0=Apr .. 11=Mar), -1 if not in that FY
    // 年度ラベル: 2026年度 = 2025年4月〜2026年3月
    function getFiscalMonthIndex(fy) {
      var now = new Date(), y = now.getFullYear(), m = now.getMonth() + 1;
      if (m >= 4 && y == fy - 1) return m - 4;
      if (m < 4 && y == fy) return m + 8;
      return -1;
    }

    // Quarter range: Q1=Apr-Jun(0-2), Q2=Jul-Sep(3-5), Q3=Oct-Dec(6-8), Q4=Jan-Mar(9-11)
    function getQuarterRange(fmIdx) {
      if (fmIdx < 0) return [-1, -1];
      if (fmIdx <= 2) return [0, 2];
      if (fmIdx <= 5) return [3, 5];
      if (fmIdx <= 8) return [6, 8];
      return [9, 11];
    }

    function getQuarterLabel(fmIdx) {
      if (fmIdx <= 2) return 'Q1：4〜6月';
      if (fmIdx <= 5) return 'Q2：7〜9月';
      if (fmIdx <= 8) return 'Q3：10〜12月';
      return 'Q4：1〜3月';
    }

    // Compute period/midterm(=quarter)/month from monthly detail
    function computeSummary(detail, fmIdx) {
      var pB=0,pA=0,mB=0,mA=0,moB=0,moA=0;
      var qr = getQuarterRange(fmIdx);
      for (var i = 0; i < 12; i++) {
        pB += detail[i][0]; pA += detail[i][1];
        if (qr[0] >= 0 && i >= qr[0] && i <= qr[1]) { mB += detail[i][0]; mA += detail[i][1]; }
      }
      if (fmIdx >= 0) { moB = detail[fmIdx][0]; moA = detail[fmIdx][1]; }
      return {
        period: [pB, pA, pB-pA, pB>0?pA/pB*100:0],
        midterm: [mB, mA, mB-mA, mB>0?mA/mB*100:0],
        month: [moB, moA, moB-moA, moB>0?moA/moB*100:0]
      };
    }

    // Monthly detail builders
    function md(b, aa) { return aa.map(function(a) { return [b, Math.round(b * a)]; }); }
    function mdz(b) { var d=[]; for(var i=0;i<12;i++) d.push([b,0]); return d; }

    // ===== Budget Data =====
    var budgetData = [];
    function addStore(year, shop, zone, area, code, fit, ig) {
      var all = [];
      for (var i = 0; i < 12; i++) all.push([fit[i][0]+ig[i][0], fit[i][1]+ig[i][1]]);
      budgetData.push({ year:year, shop:shop, zone:zone, area:area, shopCode:code,
        details: { all:all, fit:fit, ig:ig } });
    }

    // Actual ratio patterns for 2025 (Apr..Mar)
    // ~90% consumption (high performing, on-budget)
    var rH  = [0.92,0.97,0.88,1.02,0.95,0.91,1.05,0.98,0.93,0.87,1.01,0.72];
    var rH2 = [0.90,0.95,0.85,0.98,0.92,0.88,1.02,0.96,0.91,0.84,0.99,0.70];
    // ~50% consumption (low)
    var rL  = [0.42,0.48,0.55,0.50,0.45,0.52,0.47,0.53,0.49,0.44,0.51,0.35];
    var rL2 = [0.40,0.45,0.50,0.48,0.43,0.46,0.52,0.44,0.47,0.41,0.49,0.30];
    // ~105% over-budget (全期間で100%超)
    var rO  = [1.05,1.08,0.98,1.12,1.03,1.10,0.95,1.06,1.09,1.08,1.12,1.04];
    var rO2 = [1.02,1.06,0.96,1.10,1.01,1.08,0.93,1.04,1.07,1.06,1.10,1.02];
    // ~80% medium
    var rM  = [0.85,0.78,0.82,0.90,0.75,0.88,0.80,0.86,0.83,0.77,0.84,0.65];
    var rM2 = [0.82,0.75,0.79,0.86,0.72,0.84,0.77,0.83,0.80,0.74,0.81,0.60];

    // ----- 2025年度 stores (前年度：予算のみ) -----
    addStore('2025','10101:札幌','100','101','10101', mdz(15000), mdz(10000));
    addStore('2025','10102:函館','100','101','10102', mdz(12000), mdz(8000));
    addStore('2025','10103:旭川','100','101','10103', mdz(12000), mdz(8000));
    addStore('2025','10201:仙台','100','102','10201', mdz(18000), mdz(12000));
    addStore('2025','10202:盛岡','100','102','10202', mdz(12000), mdz(8000));
    addStore('2025','10301:新宿東口','100','103','10301', mdz(28000), mdz(20000));
    addStore('2025','10302:池袋西口','100','103','10302', mdz(22000), mdz(15000));
    addStore('2025','10303:横浜','100','103','10303', mdz(20000), mdz(14000));
    addStore('2025','20101:梅田','200','201','20101', mdz(25000), mdz(18000));
    addStore('2025','20102:難波','200','201','20102', mdz(20000), mdz(14000));
    addStore('2025','20201:広島','200','202','20201', mdz(15000), mdz(10000));

    // ----- 2026年度 stores (当年度：実績あり) -----
    // 札幌: ~50% consumption (budget余りがち)
    addStore('2026','10101:札幌','100','101','10101', md(16000,rL), md(11000,rL2));
    // 函館: ~80% medium
    addStore('2026','10102:函館','100','101','10102', md(13000,rM), md(9000,rM2));
    // 旭川: ~50% low
    addStore('2026','10103:旭川','100','101','10103', md(13000,rL), md(9000,rL2));
    // 仙台: ~90% high (on-budget)
    addStore('2026','10201:仙台','100','102','10201', md(19000,rH), md(13000,rH2));
    // 盛岡: ~80% medium
    addStore('2026','10202:盛岡','100','102','10202', md(13000,rM), md(9000,rM2));
    // 新宿東口: ~90% high (正常・順調)
    addStore('2026','10301:新宿東口','100','103','10301', md(30000,rH), md(22000,rH2));
    // 池袋西口: ~90% high
    addStore('2026','10302:池袋西口','100','103','10302', md(24000,rH), md(16000,rH2));
    // 横浜: ~80% medium
    addStore('2026','10303:横浜','100','103','10303', md(22000,rM), md(15000,rM2));
    // 梅田: 101%+ over-budget!
    addStore('2026','20101:梅田','200','201','20101', md(27000,rO), md(19000,rO2));
    // 難波: ~90% high
    addStore('2026','20102:難波','200','201','20102', md(22000,rH), md(15000,rH2));
    // 広島: ~50% low
    addStore('2026','20201:広島','200','202','20201', md(16000,rL), md(11000,rL2));

    // ===== Filter helpers =====
    function getSelectedYear() {
      return viewMode === 'admin'
        ? document.getElementById('filterYear').value
        : document.getElementById('storeYear').value;
    }
    function getSelectedDept() {
      var v = viewMode === 'admin'
        ? document.getElementById('filterDept').value
        : document.getElementById('storeDept').value;
      return v || 'all';
    }

    function getFilteredRows() {
      var year = getSelectedYear();
      var dept = getSelectedDept();
      var fmIdx = getFiscalMonthIndex(parseInt(year));

      var filtered = budgetData.filter(function(d) {
        if (d.year !== year) return false;
        if (viewMode === 'store') return d.shopCode === storeShopCode;
        var zone = document.getElementById('filterZone').value;
        var area = document.getElementById('filterArea').value;
        var shop = document.getElementById('filterShop').value;
        if (zone && d.zone !== zone) return false;
        if (area && d.area !== area) return false;
        if (shop && d.shopCode !== shop) return false;
        return true;
      });

      return filtered.map(function(d) {
        var s = computeSummary(d.details[dept], fmIdx);
        return {
          shop: d.shop, zone: d.zone, area: d.area, shopCode: d.shopCode,
          period: s.period, midterm: s.midterm, month: s.month,
          details: d.details
        };
      });
    }

    // ===== Cascade Filters =====
    function onZoneChange() {
      var zone = document.getElementById('filterZone').value;
      var areaSelect = document.getElementById('filterArea');
      areaSelect.innerHTML = '<option value="">エリア</option>';
      if (zone && areasByZone[zone]) {
        areasByZone[zone].forEach(function(a) {
          areaSelect.innerHTML += '<option value="' + a[0] + '">' + a[1] + '</option>';
        });
      }
      updateShopOptions();
      filterBudget();
    }

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

    function onAreaChange() {
      updateShopOptions();
      filterBudget();
    }

    function filterBudget() {
      renderSummary();
      renderTable();
    }

    // ===== Summary =====
    function renderSummary() {
      var data = getFilteredRows();
      var totP = [0,0,0], totM = [0,0,0], totMo = [0,0,0];
      data.forEach(function(d) {
        for (var i = 0; i < 3; i++) { totP[i] += d.period[i]; totM[i] += d.midterm[i]; totMo[i] += d.month[i]; }
      });

      var storeCount = data.length;
      var year = getSelectedYear();
      var fmIdx = getFiscalMonthIndex(parseInt(year));

      // Store count label (admin only)
      var countEl = document.getElementById('summaryCount');
      if (countEl) {
        countEl.textContent = viewMode === 'admin' ? '対象：' + storeCount + '店舗' : '';
      }

      function balanceClass(v) { return v < 0 ? 'negative' : 'positive'; }

      function rateBar(budget, actual) {
        var rate = budget > 0 ? (actual / budget * 100) : 0;
        var color = getProgressColor(rate);
        return '<div class="progress-bar"><div class="progress-fill ' + color + '" style="width:' + Math.min(rate, 100) + '%"></div></div>' +
          '<div class="summary-card-sub">消化率 ' + rate.toFixed(1) + '%</div>';
      }

      function periodCard(title, sub, tot) {
        return '<div class="summary-card summary-card-period">' +
          '<div class="summary-card-label">' + title + '</div>' +
          '<div class="summary-card-sub-label">' + sub + '</div>' +
          '<div class="summary-row"><span class="summary-row-label">予算</span><span class="summary-row-value">¥' + fmt(tot[0]) + '</span></div>' +
          '<div class="summary-row"><span class="summary-row-label">実績</span><span class="summary-row-value">¥' + fmt(tot[1]) + '</span></div>' +
          '<div class="summary-row"><span class="summary-row-label">残高</span><span class="summary-row-value ' + balanceClass(tot[2]) + '">¥' + fmt(tot[2]) + '</span></div>' +
          rateBar(tot[0], tot[1]) +
        '</div>';
      }

      var qLabel = fmIdx >= 0 ? getQuarterLabel(fmIdx) : '';
      var currentMonth = fmIdx >= 0 ? fiscalMonths[fmIdx] + '月' : '';

      document.getElementById('summaryCards').innerHTML =
        periodCard('当期', year + '年度', totP) +
        periodCard('期中', qLabel, totM) +
        periodCard('当月', currentMonth, totMo);

      document.getElementById('midtermHeader').textContent = '期中';
    }

    // ===== Table =====
    function renderTable() {
      var data = getFilteredRows();
      var year = getSelectedYear();
      var dept = getSelectedDept();
      var tbody = document.getElementById('budgetTableBody');
      var html = '';

      data.forEach(function(d, idx) {
        html += '<tr class="budget-row" onclick="toggleDetail(' + idx + ')" id="row-' + idx + '">';
        html += '<td><div class="shop-cell"><svg class="expand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>' + d.shop + '</div></td>';
        html += numCells(d.period);
        html += numCells(d.midterm);
        html += numCells(d.month);
        html += '</tr>';

        // Detail row
        html += '<tr class="detail-row" id="detail-' + idx + '"><td colspan="13"><div class="detail-content">';
        html += '<div class="detail-header"><div class="detail-title">' + year + '年度 月別明細 — ' + d.shop + '</div>';
        html += '<div class="dept-toggles">';
        departments.forEach(function(dp) {
          var activeClass = dp.key === dept ? ' active' : '';
          html += '<button class="dept-toggle' + activeClass + '" onclick="event.stopPropagation(); toggleDept(' + idx + ',\'' + dp.key + '\', this)">' + dp.label + '</button>';
        });
        html += '</div></div>';
        departments.forEach(function(dp) {
          var hideStyle = dp.key === dept ? '' : ' style="display:none"';
          html += '<div class="dept-section" id="dept-' + idx + '-' + dp.key + '"' + hideStyle + '>';
          html += '<div class="dept-section-title">' + dp.label + '</div>';
          html += renderMonthlyTable(d.details[dp.key]);
          html += '</div>';
        });
        html += '</div></td></tr>';
      });

      // Total row
      if (data.length > 1) {
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

    // ===== Toggle handlers =====
    function toggleDept(idx, key, btn) {
      var section = document.getElementById('dept-' + idx + '-' + key);
      if (btn.classList.contains('active')) {
        btn.classList.remove('active');
        section.style.display = 'none';
      } else {
        btn.classList.add('active');
        section.style.display = '';
      }
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

    // ===== Export =====
    function exportBudgetExcel() {
      var data = getFilteredRows();
      if (data.length === 0) {
        alert('出力対象のデータがありません。');
        return;
      }

      var dept = getSelectedDept();
      var year = getSelectedYear();
      var deptObj = departments.filter(function(d) { return d.key === dept; })[0];
      var deptLabel = deptObj ? deptObj.label : 'すべての部門';

      var filterRows = [];
      if (viewMode === 'admin') {
        var zoneEl = document.getElementById('filterZone');
        var areaEl = document.getElementById('filterArea');
        var shopEl = document.getElementById('filterShop');
        filterRows.push(['ゾーン', zoneEl.options[zoneEl.selectedIndex].text]);
        filterRows.push(['エリア', areaEl.options[areaEl.selectedIndex].text]);
        filterRows.push(['店舗', shopEl.options[shopEl.selectedIndex].text]);
      }
      filterRows.push(['部門', deptLabel]);
      filterRows.push(['年度', year + '年度']);

      var rows = [];
      rows.push(['予算管理データ']);
      rows.push([]);
      filterRows.forEach(function(f) { rows.push(['【' + f[0] + '】', f[1]]); });
      rows.push([]);

      var fmIdx = getFiscalMonthIndex(parseInt(year));
      var qLabel = fmIdx >= 0 ? '期中(' + getQuarterLabel(fmIdx) + ')' : '期中';

      rows.push([
        '店舗',
        '当期予算', '当期実績', '当期残高', '当期消化率(%)',
        qLabel + '予算', qLabel + '実績', qLabel + '残高', qLabel + '消化率(%)',
        '当月予算', '当月実績', '当月残高', '当月消化率(%)'
      ]);

      data.forEach(function(d) {
        rows.push([
          d.shop,
          d.period[0], d.period[1], d.period[2], d.period[3].toFixed(1),
          d.midterm[0], d.midterm[1], d.midterm[2], d.midterm[3].toFixed(1),
          d.month[0], d.month[1], d.month[2], d.month[3].toFixed(1)
        ]);
      });

      if (data.length > 1) {
        var totP = [0,0,0], totM = [0,0,0], totMo = [0,0,0];
        data.forEach(function(d) {
          for (var i = 0; i < 3; i++) { totP[i] += d.period[i]; totM[i] += d.midterm[i]; totMo[i] += d.month[i]; }
        });
        rows.push([
          '合計',
          totP[0], totP[1], totP[2], totP[0] > 0 ? (totP[1] / totP[0] * 100).toFixed(1) : '0.0',
          totM[0], totM[1], totM[2], totM[0] > 0 ? (totM[1] / totM[0] * 100).toFixed(1) : '0.0',
          totMo[0], totMo[1], totMo[2], totMo[0] > 0 ? (totMo[1] / totMo[0] * 100).toFixed(1) : '0.0'
        ]);
      }

      // Monthly detail
      rows.push([]);
      rows.push(['===== 月別明細 =====']);
      var monthHeaders = ['項目'];
      fiscalMonths.forEach(function(m) { monthHeaders.push(m + '月'); });
      monthHeaders.push('合計');

      var deptList = dept === 'all' ? departments : departments.filter(function(dp) { return dp.key === dept; });

      data.forEach(function(d) {
        rows.push([]);
        rows.push(['■ ' + d.shop]);
        deptList.forEach(function(dp) {
          rows.push(['【' + dp.label + '】']);
          rows.push(monthHeaders);
          var detail = d.details[dp.key];
          var bRow = ['予算'], aRow = ['実績'], balRow = ['残高'], rRow = ['消化率(%)'];
          var bTot = 0, aTot = 0;
          detail.forEach(function(cell) {
            bRow.push(cell[0]); aRow.push(cell[1]);
            balRow.push(cell[0] - cell[1]);
            rRow.push(cell[0] > 0 ? (cell[1] / cell[0] * 100).toFixed(1) : '0.0');
            bTot += cell[0]; aTot += cell[1];
          });
          bRow.push(bTot); aRow.push(aTot); balRow.push(bTot - aTot);
          rRow.push(bTot > 0 ? (aTot / bTot * 100).toFixed(1) : '0.0');
          rows.push(bRow); rows.push(aRow); rows.push(balRow); rows.push(rRow);
        });
      });

      // CSV
      var csv = '\uFEFF';
      rows.forEach(function(row) {
        csv += row.map(function(cell) {
          var s = String(cell == null ? '' : cell);
          if (s.indexOf(',') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\n') >= 0) {
            return '"' + s.replace(/"/g, '""') + '"';
          }
          return s;
        }).join(',') + '\r\n';
      });

      var fileName = '予算管理_' + year + '年度.csv';
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = fileName;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    // ===== Init =====
    function initView() {
      document.getElementById('adminToolbar').style.display = viewMode === 'admin' ? '' : 'none';
      document.getElementById('storeToolbar').style.display = viewMode === 'store' ? '' : 'none';

      var desc = document.getElementById('pageDesc');
      if (viewMode === 'store') {
        desc.textContent = '自店の予算・実績・消化状況を確認できます。行をクリックすると月別明細を表示します。';
      }

      renderSummary();
      renderTable();
    }

    initView();
