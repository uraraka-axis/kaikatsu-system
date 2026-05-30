    // ===== Role Detection (from API via userLoaded event) =====
    var viewMode = 'store';       // default until userLoaded
    var storeShopCode = '';
    var currentUser = null;       // bootBudget で代入

    var fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];

    // 部門定義: 'all' は全体（バックエンドが SUM 計算）、他は categories.code = budgets.department
    // 2026-05-23 自店所属カテゴリのみ表示するため API から動的構築
    var departments = [
      { key: 'all', label: '全体' }
    ];

    // ===== Excel出力対象選択（admin only） =====
    var selectedShops = {}; // shop_code -> true

    window.onBudgetCheckChange = function(checkbox) {
      var code = checkbox.getAttribute('data-shop-code');
      if (checkbox.checked) selectedShops[code] = true;
      else delete selectedShops[code];
      updateBudgetSelectAllState();
    };

    window.toggleAllBudget = function(checkbox) {
      document.querySelectorAll('.budget-row-check').forEach(function(cb) {
        cb.checked = checkbox.checked;
        var code = cb.getAttribute('data-shop-code');
        if (checkbox.checked) selectedShops[code] = true;
        else delete selectedShops[code];
      });
    };

    function updateBudgetSelectAllState() {
      var selectAll = document.getElementById('budgetSelectAll');
      if (!selectAll) return;
      var all = document.querySelectorAll('.budget-row-check');
      var checked = document.querySelectorAll('.budget-row-check:checked');
      selectAll.checked = all.length > 0 && all.length === checked.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
    }

    // ===== Pagination state =====
    var BUDGET_PAGE_SIZE_KEY = 'pagesize:budget-management';
    var defaultBudgetPageSize = 'all'; // 予算管理は店舗数が限定的なため初期値は全件
    var budgetPageSize = defaultBudgetPageSize;
    var budgetDisplayLimit = Number.MAX_SAFE_INTEGER;

    function loadBudgetPageSize() {
      try {
        var stored = sessionStorage.getItem(BUDGET_PAGE_SIZE_KEY);
        if (stored === null) return;
        if (stored === 'all') { budgetPageSize = 'all'; return; }
        var n = parseInt(stored, 10);
        if (n > 0) budgetPageSize = n;
      } catch (e) {}
    }
    function saveBudgetPageSize() {
      try { sessionStorage.setItem(BUDGET_PAGE_SIZE_KEY, String(budgetPageSize)); } catch (e) {}
    }

    // ===== Zone / Area / Shop master (populated from API) =====
    var areasByZone = {};
    var shopsByArea = {};
    var shopCatMap = {}; // shop_code → 取扱カテゴリ配列（店舗ドロップダウンのカテゴリ絞り込み用）
    var currentRows = []; // 現在テーブルに描画中の行データ（折りたたみ詳細の遅延生成用）

    // ===== Helpers =====
    function fmt(n) { return n.toLocaleString(); }
    function getProgressColor(rate) {
      if (rate >= 90) return 'red';
      if (rate >= 60) return 'yellow';
      return 'green';
    }

    // Fiscal month index (0=Apr .. 11=Mar), -1 if not in that FY
    // 年度ラベル: 2026年度 = 2026年4月〜2027年3月
    function getFiscalMonthIndex(fy) {
      var now = new Date(), y = now.getFullYear(), m = now.getMonth() + 1;
      if (m >= 4 && y == fy) return m - 4;
      if (m < 4 && y == fy + 1) return m + 8;
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

    // ===== Budget Data (populated from API) =====
    var budgetData = [];

    // ===== API: Fetch master data =====
    // ===== Fiscal Year dropdown (dynamic from API) =====
    function fetchFiscalYears(callback) {
      fetch('api/budgets.php?action=years', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          var years = (data.success && data.data) ? data.data : [];
          populateYearSelects(years);
          if (callback) callback();
        })
        .catch(function(e) {
          console.error('Failed to fetch fiscal years:', e);
          if (callback) callback();
        });
    }

    function populateYearSelects(years) {
      var now = new Date();
      var currentFY = now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
      var selects = [
        document.getElementById('filterYear'),
        document.getElementById('storeYear')
      ];
      selects.forEach(function(sel) {
        if (!sel) return;
        sel.innerHTML = '';
        years.forEach(function(y) {
          var opt = document.createElement('option');
          opt.value = y;
          opt.textContent = y + '年度';
          if (y === currentFY) opt.selected = true;
          sel.appendChild(opt);
        });
      });
    }

    function fetchMasterData(callback) {
      // 年度ドロップダウン + カテゴリは全ユーザーで必要
      var done = 0;
      var total = viewMode === 'admin' ? 5 : 2;
      var zones = [], areas = [], shops = [], categories = [];

      function checkDone() {
        done++;
        if (done < total) return;
        // ロール別の管轄スコープを zones/areas/shops に適用
        if (currentUser && currentUser.role === 'zone' && currentUser.zone_code) {
          zones = zones.filter(function(z) { return z.zone_code === currentUser.zone_code; });
          areas = areas.filter(function(a) { return a.zone_code === currentUser.zone_code; });
          var validAreaCodes = areas.map(function(a) { return a.area_code; });
          shops = shops.filter(function(s) { return validAreaCodes.indexOf(s.area_code) !== -1; });
        } else if (currentUser && currentUser.role === 'area' && currentUser.area_code) {
          var myArea = null;
          for (var ia = 0; ia < areas.length; ia++) {
            if (areas[ia].area_code === currentUser.area_code) { myArea = areas[ia]; break; }
          }
          var myZoneCode = myArea ? myArea.zone_code : null;
          zones = zones.filter(function(z) { return z.zone_code === myZoneCode; });
          areas = areas.filter(function(a) { return a.area_code === currentUser.area_code; });
          shops = shops.filter(function(s) { return s.area_code === currentUser.area_code; });
        }
        // Build areasByZone
        areasByZone = {};
        areas.forEach(function(a) {
          if (!areasByZone[a.zone_code]) areasByZone[a.zone_code] = [];
          areasByZone[a.zone_code].push([a.area_code, a.area_code + ':' + a.area_name]);
        });
        // Build shopsByArea
        shopsByArea = {};
        shopCatMap = {};
        shops.forEach(function(s) {
          if (!shopsByArea[s.area_code]) shopsByArea[s.area_code] = [];
          shopsByArea[s.area_code].push(s.shop_code + ':' + s.shop_name);
          shopCatMap[s.shop_code] = s.categories ? String(s.categories).split(',') : [];
        });
        // Populate zone select
        var isManagerScope = currentUser && (currentUser.role === 'zone' || currentUser.role === 'area');
        var isAreaScope = currentUser && currentUser.role === 'area';
        var zoneSelect = document.getElementById('filterZone');
        if (zoneSelect) {
          if (isManagerScope) {
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
          // 初期表示時に全エリア/全店舗を populate（zone未選択時の状態）
          var areaSel = document.getElementById('filterArea');
          if (areaSel) {
            if (isAreaScope) {
              areaSel.innerHTML = '';
              areas.forEach(function(a) {
                areaSel.innerHTML += '<option value="' + a.area_code + '">' + a.area_code + ':' + a.area_name + '</option>';
              });
              areaSel.disabled = true;
              if (areas[0]) areaSel.value = areas[0].area_code;
            } else {
              areaSel.innerHTML = '<option value="">すべて</option>';
              Object.keys(areasByZone).forEach(function(k) {
                areasByZone[k].forEach(function(a) {
                  areaSel.innerHTML += '<option value="' + a[0] + '">' + a[1] + '</option>';
                });
              });
            }
          }
          var shopSel = document.getElementById('filterShop');
          if (shopSel) {
            shopSel.innerHTML = '<option value="">すべて</option>';
            Object.keys(shopsByArea).forEach(function(k) {
              shopsByArea[k].forEach(function(s) {
                var code = s.split(':')[0];
                shopSel.innerHTML += '<option value="' + code + '">' + s + '</option>';
              });
            });
          }
        }
        // Populate dept(category) selects (admin / store 両方対応)
        ['filterDept', 'storeDept'].forEach(function(id) {
          var sel = document.getElementById(id);
          if (!sel) return;
          while (sel.options.length > 1) sel.remove(1);
          categories.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            sel.appendChild(opt);
          });
        });
        // departments を「全体 + 取得済みカテゴリ」で再構築
        // 店舗ユーザーは自店所属カテゴリのみ、admin は全カテゴリ
        departments = [{ key: 'all', label: '全体' }];
        categories.forEach(function(c) {
          departments.push({ key: c.code, label: c.name });
        });
        callback();
      }

      fetchFiscalYears(checkDone);

      fetch('api/master/categories.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) { categories = data.data || []; checkDone(); })
        .catch(function(e) { console.error('Failed to fetch categories:', e); categories = []; checkDone(); });

      if (viewMode === 'admin') {
        fetch('api/master/zones.php', { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) { zones = data.data || []; checkDone(); })
          .catch(function(e) { console.error('Failed to fetch zones:', e); zones = []; checkDone(); });

        fetch('api/master/areas.php', { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) { areas = data.data || []; checkDone(); })
          .catch(function(e) { console.error('Failed to fetch areas:', e); areas = []; checkDone(); });

        fetch('api/master/shops.php', { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) { shops = data.data || []; checkDone(); })
          .catch(function(e) { console.error('Failed to fetch shops:', e); shops = []; checkDone(); });
      }
    }

    // ===== API: Fetch budget data =====
    function fetchBudgetData(callback) {
      var year = getSelectedYear();
      // departments は initView 前に fetchMasterData で動的構築済み
      var depts = departments.map(function(d) { return d.key; });
      var results = {};
      var done = 0;
      var total = depts.length;
      if (typeof window.showLoading === 'function') window.showLoading('予算データを読み込み中…');

      function finishedAll() {
        if (typeof window.hideLoading === 'function') window.hideLoading();
        buildBudgetData(results, year, callback);
      }

      depts.forEach(function(dept) {
        var params = 'year=' + encodeURIComponent(year) + '&dept=' + encodeURIComponent(dept);
        if (viewMode === 'admin') {
          var zone = document.getElementById('filterZone').value;
          var area = document.getElementById('filterArea').value;
          var shop = document.getElementById('filterShop').value;
          if (zone) params += '&zone=' + encodeURIComponent(zone);
          if (area) params += '&area=' + encodeURIComponent(area);
          if (shop) params += '&shop=' + encodeURIComponent(shop);
        } else {
          params += '&shop=' + encodeURIComponent(storeShopCode);
        }

        fetch('api/budgets.php?' + params, { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            results[dept] = data.data || [];
            done++;
            if (done === total) finishedAll();
          })
          .catch(function(e) {
            console.error('Failed to fetch budgets (dept=' + dept + '):', e);
            results[dept] = [];
            done++;
            if (done === total) finishedAll();
          });
      });
    }

    function buildBudgetData(results, year, callback) {
      // Use 'all' dept list as the base shop list
      var shopMap = {};

      (results['all'] || []).forEach(function(d) {
        var details = {};
        departments.forEach(function(dp) {
          details[dp.key] = dp.key === 'all' ? monthlyToDetail(d.monthly) : emptyDetail();
        });
        shopMap[d.shop_code] = {
          year: String(year),
          shop: d.shop_code + ':' + d.shop_name,
          zone: d.zone_code,
          area: d.area_code,
          shopCode: d.shop_code,
          categories: d.categories || [], // 店舗の取扱カテゴリ（タブ絞り込み用）
          details: details
        };
      });

      // Merge per-category details for all departments except 'all'
      departments.forEach(function(dp) {
        if (dp.key === 'all') return;
        (results[dp.key] || []).forEach(function(d) {
          if (shopMap[d.shop_code]) {
            shopMap[d.shop_code].details[dp.key] = monthlyToDetail(d.monthly);
          }
        });
      });

      budgetData = [];
      Object.keys(shopMap).forEach(function(code) {
        budgetData.push(shopMap[code]);
      });

      callback();
    }

    function monthlyToDetail(monthly) {
      var detail = [];
      fiscalMonths.forEach(function(m) {
        var found = null;
        if (monthly) {
          for (var i = 0; i < monthly.length; i++) {
            if (monthly[i].month === m) { found = monthly[i]; break; }
          }
        }
        detail.push(found ? [found.budget, found.actual] : [0, 0]);
      });
      return detail;
    }

    function emptyDetail() {
      var d = [];
      for (var i = 0; i < 12; i++) d.push([0, 0]);
      return d;
    }

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
      var dept = getSelectedDept();
      var year = getSelectedYear();
      var fmIdx = getFiscalMonthIndex(parseInt(year));

      // 特定カテゴリ選択時は、そのカテゴリを取扱う店舗のみ表示
      // （取扱っていない店舗を 0 行として並べると視認性が落ちるため）
      var rows = budgetData;
      if (dept !== 'all') {
        rows = rows.filter(function(d) {
          return (d.categories || []).indexOf(dept) !== -1;
        });
      }

      return rows.map(function(d) {
        var s = computeSummary(d.details[dept], fmIdx);
        return {
          shop: d.shop, zone: d.zone, area: d.area, shopCode: d.shopCode,
          period: s.period, midterm: s.midterm, month: s.month,
          details: d.details,
          categories: d.categories || []
        };
      });
    }

    // ===== Cascade Filters =====
    function onZoneChange() {
      var zone = document.getElementById('filterZone').value;
      var areaSelect = document.getElementById('filterArea');
      areaSelect.innerHTML = '<option value="">すべて</option>';
      // zone 未選択時は全エリアを表示（発注一覧と同じ仕様）
      var list = [];
      if (zone && areasByZone[zone]) {
        list = areasByZone[zone];
      } else {
        Object.keys(areasByZone).forEach(function(k) {
          areasByZone[k].forEach(function(a) { list.push(a); });
        });
      }
      list.forEach(function(a) {
        areaSelect.innerHTML += '<option value="' + a[0] + '">' + a[1] + '</option>';
      });
      updateShopOptions();
      filterBudget();
    }

    function updateShopOptions() {
      var shopSelect = document.getElementById('filterShop');
      if (!shopSelect) return; // 店舗ユーザー(store)ビューには店舗ドロップダウンが無い
      var area = document.getElementById('filterArea').value;
      var zone = document.getElementById('filterZone').value;
      var prev = shopSelect.value; // 選択中の店舗（絞り込み後も残っていれば復元）
      shopSelect.innerHTML = '<option value="">すべて</option>';
      // area選択時=その area の店舗 / zoneのみ=その zone 配下全店舗 / 両方未選択=全店舗
      var list = [];
      if (area && shopsByArea[area]) {
        list = shopsByArea[area];
      } else if (zone && areasByZone[zone]) {
        areasByZone[zone].forEach(function(a) {
          var ac = a[0];
          if (shopsByArea[ac]) shopsByArea[ac].forEach(function(s) { list.push(s); });
        });
      } else {
        Object.keys(shopsByArea).forEach(function(k) {
          shopsByArea[k].forEach(function(s) { list.push(s); });
        });
      }
      // 選択中カテゴリでの絞り込み（例: フィットネス選択時はゴルフ専用店を除外）
      var dept = getSelectedDept();
      list.forEach(function(s) {
        var code = s.split(':')[0];
        if (dept && dept !== 'all') {
          var cats = shopCatMap[code] || [];
          if (cats.indexOf(dept) === -1) return; // 当該カテゴリを扱わない店舗は出さない
        }
        shopSelect.innerHTML += '<option value="' + code + '">' + s + '</option>';
      });
      // 絞り込み後も同じ店舗が残っていれば選択を維持、無ければ「すべて」
      if (prev) {
        var stillThere = Array.prototype.some.call(shopSelect.options, function(o) { return o.value === prev; });
        if (stillThere) shopSelect.value = prev;
      }
    }

    function onAreaChange() {
      updateShopOptions();
      filterBudget();
    }

    // カテゴリ変更時は店舗ドロップダウンを当該カテゴリで絞り直す
    function onDeptChange() {
      updateShopOptions();
      filterBudget();
    }

    // ===== 検索条件保存/復元（同タブ内） =====
    var BUDGET_FILTER_KEY = 'filters:budget-management';
    var BUDGET_FILTER_IDS_ADMIN = ['filterZone', 'filterArea', 'filterShop', 'filterDept', 'filterYear'];
    var BUDGET_FILTER_IDS_STORE = ['storeDept', 'storeYear'];
    var isBudgetInitializing = true; // 初期化中は saveBudgetFilters を抑止

    function saveBudgetFilters() {
      if (isBudgetInitializing) return;
      var ids = viewMode === 'admin' ? BUDGET_FILTER_IDS_ADMIN : BUDGET_FILTER_IDS_STORE;
      var state = {};
      ids.forEach(function(id) {
        var el = document.getElementById(id);
        if (el && !el.disabled) state[id] = el.value;
      });
      try { sessionStorage.setItem(BUDGET_FILTER_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function restoreBudgetFilters() {
      var raw;
      try { raw = sessionStorage.getItem(BUDGET_FILTER_KEY); } catch (e) { return; }
      if (!raw) return;
      var state;
      try { state = JSON.parse(raw); } catch (e) { return; }
      Object.keys(state).forEach(function(id) {
        var el = document.getElementById(id);
        if (el && !el.disabled) el.value = state[id];
      });
    }

    function filterBudget() {
      saveBudgetFilters();
      // フィルタ変更時は表示件数をリセット
      budgetDisplayLimit = (budgetPageSize === 'all') ? Number.MAX_SAFE_INTEGER : budgetPageSize;
      fetchBudgetData(function() {
        renderSummary();
        renderTable();
      });
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
      var totalCount = data.length;

      // 表示件数のスライス
      var sliced;
      if (budgetPageSize === 'all') {
        sliced = data;
        budgetDisplayLimit = totalCount;
      } else {
        budgetDisplayLimit = Math.min(budgetDisplayLimit, totalCount);
        sliced = data.slice(0, budgetDisplayLimit);
      }

      var tbody = document.getElementById('budgetTableBody');
      var html = '';

      var detailColspan = viewMode === 'admin' ? 14 : 13;

      currentRows = sliced; // 折りたたみ詳細の遅延生成で参照する

      sliced.forEach(function(d, idx) {
        html += '<tr class="budget-row" onclick="toggleDetail(' + idx + ')" id="row-' + idx + '">';
        if (viewMode === 'admin') {
          var checked = selectedShops[d.shopCode] ? ' checked' : '';
          html += '<td class="budget-check-cell" onclick="event.stopPropagation()">' +
            '<input type="checkbox" class="budget-row-check" data-shop-code="' + d.shopCode + '" onchange="onBudgetCheckChange(this)"' + checked + '>' +
          '</td>';
        }
        html += '<td><div class="shop-cell"><svg class="expand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>' + d.shop + '</div></td>';
        html += numCells(d.period);
        html += numCells(d.midterm);
        html += numCells(d.month);
        html += '</tr>';

        // Detail row（遅延描画: 中身は初回展開時に toggleDetail で構築する）
        html += '<tr class="detail-row" id="detail-' + idx + '"><td colspan="' + detailColspan + '"><div class="detail-content" id="detailContent-' + idx + '"></div></td></tr>';
      });

      // Total row（フィルタ後の全件で合計を算出）
      if (data.length > 1) {
        var totP = [0,0,0,0], totM = [0,0,0,0], totMo = [0,0,0,0];
        data.forEach(function(d) {
          for (var i = 0; i < 3; i++) { totP[i] += d.period[i]; totM[i] += d.midterm[i]; totMo[i] += d.month[i]; }
        });
        totP[3] = totP[0] > 0 ? (totP[1] / totP[0] * 100) : 0;
        totM[3] = totM[0] > 0 ? (totM[1] / totM[0] * 100) : 0;
        totMo[3] = totMo[0] > 0 ? (totMo[1] / totMo[0] * 100) : 0;
        html += '<tr class="total-row">';
        if (viewMode === 'admin') html += '<td></td>';
        html += '<td>合計</td>';
        html += numCells(totP) + numCells(totM) + numCells(totMo);
        html += '</tr>';
      }

      tbody.innerHTML = html;
      renderBudgetPagination(totalCount);
    }

    // ===== Pagination UI =====
    function renderBudgetPagination(totalCount) {
      var info = document.getElementById('budgetPaginationInfo');
      var btn  = document.getElementById('budgetShowMoreBtn');
      var sel  = document.getElementById('budgetPageSizeSelect');
      if (!info || !btn || !sel) return;

      sel.value = budgetPageSize === 'all' ? 'all' : String(budgetPageSize);

      if (totalCount === 0) {
        info.textContent = '';
        btn.style.display = 'none';
        return;
      }

      var shown = budgetPageSize === 'all' ? totalCount : Math.min(budgetDisplayLimit, totalCount);
      info.textContent = '全 ' + totalCount + ' 店舗中 ' + shown + ' 店舗表示中';

      if (budgetPageSize === 'all' || shown >= totalCount) {
        btn.style.display = 'none';
      } else {
        btn.style.display = '';
        var nextCount = Math.min(totalCount - shown, budgetPageSize);
        btn.textContent = '次を表示（' + nextCount + '店舗）';
      }
    }

    window.showMoreBudgetRows = function() {
      if (budgetPageSize === 'all') return;
      budgetDisplayLimit += budgetPageSize;
      renderTable();
    };

    window.onBudgetPageSizeChange = function() {
      var sel = document.getElementById('budgetPageSizeSelect');
      if (!sel) return;
      if (sel.value === 'all') {
        budgetPageSize = 'all';
      } else {
        budgetPageSize = parseInt(sel.value, 10) || 20;
        budgetDisplayLimit = budgetPageSize;
      }
      saveBudgetPageSize();
      renderTable();
    };

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

    // 折りたたみ詳細（月別明細）の HTML を生成する。
    // 初期描画では作らず、初回展開時にのみ構築することで大量店舗時の描画コストを抑える。
    function buildDetailContent(d, idx) {
      var year = getSelectedYear();
      var dept = getSelectedDept();
      // 店舗の取扱カテゴリだけタブ表示。'all'（全体）は常に表示。
      var shopCats = d.categories || [];
      var shopDepartments = departments.filter(function(dp) {
        return dp.key === 'all' || shopCats.indexOf(dp.key) !== -1;
      });
      // 現在選択中の dept がその店舗のタブにない場合は 'all' にフォールバック
      var effectiveDept = shopDepartments.some(function(dp) { return dp.key === dept; }) ? dept : 'all';

      var html = '';
      html += '<div class="detail-header"><div class="detail-title">' + year + '年度 月別明細 — ' + d.shop + '</div>';
      html += '<div class="dept-toggles">';
      shopDepartments.forEach(function(dp) {
        var activeClass = dp.key === effectiveDept ? ' active' : '';
        html += '<button class="dept-toggle' + activeClass + '" onclick="event.stopPropagation(); toggleDept(' + idx + ',\'' + dp.key + '\', this)">' + dp.label + '</button>';
      });
      html += '</div></div>';
      shopDepartments.forEach(function(dp) {
        var hideStyle = dp.key === effectiveDept ? '' : ' style="display:none"';
        html += '<div class="dept-section" id="dept-' + idx + '-' + dp.key + '"' + hideStyle + '>';
        html += '<div class="dept-section-title">' + dp.label + '</div>';
        html += renderMonthlyTable(d.details[dp.key]);
        html += '</div>';
      });
      return html;
    }

    function toggleDetail(idx) {
      var row = document.getElementById('row-' + idx);
      var detail = document.getElementById('detail-' + idx);
      if (row.classList.contains('expanded')) {
        row.classList.remove('expanded');
        detail.classList.remove('visible');
      } else {
        // 遅延生成: 初回展開時に詳細（月別明細）を構築
        var content = document.getElementById('detailContent-' + idx);
        if (content && !content.dataset.built && currentRows[idx]) {
          content.innerHTML = buildDetailContent(currentRows[idx], idx);
          content.dataset.built = '1';
        }
        row.classList.add('expanded');
        detail.classList.add('visible');
      }
    }

    // ===== Export (server-side CSV) =====
    function exportBudgetExcel() {
      var year = getSelectedYear();
      var dept = getSelectedDept();
      var params = 'year=' + encodeURIComponent(year) + '&dept=' + encodeURIComponent(dept);
      if (viewMode === 'admin') {
        var selectedCodes = Object.keys(selectedShops);
        if (selectedCodes.length > 0) {
          // チェックボックスで個別選択された店舗を優先（フィルタは無視）
          selectedCodes.forEach(function(code) {
            params += '&shops[]=' + encodeURIComponent(code);
          });
        } else {
          // 未選択時はフィルタ条件で出力
          var zone = document.getElementById('filterZone').value;
          var area = document.getElementById('filterArea').value;
          var shop = document.getElementById('filterShop').value;
          if (zone) params += '&zone=' + encodeURIComponent(zone);
          if (area) params += '&area=' + encodeURIComponent(area);
          if (shop) params += '&shop=' + encodeURIComponent(shop);
        }
      }
      window.location.href = 'api/export/budgets.php?' + params;
    }

    // ===== Init =====
    function initView() {
      document.getElementById('adminToolbar').style.display = viewMode === 'admin' ? '' : 'none';
      document.getElementById('storeToolbar').style.display = viewMode === 'store' ? '' : 'none';

      // Excel出力対象選択チェックボックス列はadminのみ
      var checkCol = document.querySelector('.budget-check-col');
      if (checkCol) checkCol.style.display = viewMode === 'admin' ? '' : 'none';

      // ページネーションバーは admin のみ表示（店舗ユーザーは自店1件のみのため不要）
      var pagBar = document.getElementById('budgetPaginationBar');
      if (pagBar) pagBar.style.display = viewMode === 'admin' ? '' : 'none';

      loadBudgetPageSize();
      budgetDisplayLimit = (budgetPageSize === 'all') ? Number.MAX_SAFE_INTEGER : budgetPageSize;
      restoreBudgetFilters();
      updateShopOptions(); // 復元したカテゴリで店舗ドロップダウンを絞り込む（admin のみ実効）
      restoreSummaryCollapse();
      isBudgetInitializing = false; // 初期化完了
      filterBudget();
    }

    // ===== サマリー折りたたみ =====
    var SUMMARY_COLLAPSE_KEY = 'budget:summary-collapsed';
    window.toggleSummary = function() {
      var section = document.querySelector('.summary-section');
      var label = document.getElementById('summaryToggleLabel');
      var btn = document.getElementById('summaryToggleBtn');
      if (!section) return;
      var nowCollapsed = !section.classList.contains('collapsed');
      section.classList.toggle('collapsed', nowCollapsed);
      if (label) label.textContent = nowCollapsed ? 'サマリーを表示' : 'サマリーを隠す';
      if (btn) btn.setAttribute('aria-expanded', String(!nowCollapsed));
      try { sessionStorage.setItem(SUMMARY_COLLAPSE_KEY, nowCollapsed ? '1' : '0'); } catch (e) {}
    };

    function restoreSummaryCollapse() {
      var collapsed;
      try { collapsed = sessionStorage.getItem(SUMMARY_COLLAPSE_KEY) === '1'; } catch (e) { collapsed = false; }
      if (!collapsed) return;
      var section = document.querySelector('.summary-section');
      var label = document.getElementById('summaryToggleLabel');
      var btn = document.getElementById('summaryToggleBtn');
      if (section) section.classList.add('collapsed');
      if (label) label.textContent = 'サマリーを表示';
      if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    // ===== Boot: wait for userLoaded event from common-nav.js =====
    function bootBudget(user) {
      if (viewMode !== 'store' || storeShopCode) return; // 二重起動防止
      // admin/system/zone/area は admin ビュー（複数店舗横断）。shop は store ビュー（自店のみ）
      var managerRoles = ['admin', 'system', 'zone', 'area'];
      viewMode = managerRoles.indexOf(user.role) !== -1 ? 'admin' : 'store';
      // fetchMasterData / restoreBudgetFilters でロール別フィルタを適用するため
      // ユーザー情報をモジュールスコープに保持
      currentUser = user;
      if (viewMode === 'store') {
        storeShopCode = user.shop_code;
      }

      fetchMasterData(function() {
        initView();
      });
    }

    window.addEventListener('userLoaded', function(e) {
      bootBudget(e.detail);
    });

    // レース条件対策: common-nav.jsが先に完了していた場合
    if (window.__currentUser) {
      bootBudget(window.__currentUser);
    }
