    // ===== View Mode =====
    var viewMode = 'store'; // 'store' or 'admin'

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
      { code: '10101', name: '札幌', area: '101' },
      { code: '10102', name: '函館', area: '101' },
      { code: '10201', name: '仙台', area: '102' },
      { code: '10301', name: '新宿東口', area: '103' },
      { code: '10302', name: '池袋西口', area: '103' },
      { code: '10303', name: '横浜', area: '103' },
      { code: '20101', name: '梅田', area: '201' },
      { code: '20102', name: '難波', area: '201' },
      { code: '20201', name: '広島', area: '202' }
    ];

    // ===== Store-view orders (current store only) =====
    var storeOrders = [
      { id: 'ORD-2026-001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常', amount: null, status: 0, date: '2026-02-25', shop: '10301', equipment: 'ランニングマシン TR-800', issue: 'ベルトが滑る。異音が発生。', repairCost: '', repairDate: '', repairStatus: '' },
      { id: 'ORD-2026-002', type: 'equipment', category: 'fitness', title: 'トレーニングマット × 5', amount: 17500, status: 1, date: '2026-02-24', shop: '10301', supplier: 'フィットネスジャパン', items: 'トレーニングマット × 5' },
      { id: 'ORD-2026-003', type: 'parts', category: 'golf', title: 'スイング診断機 センサー交換部品', amount: 12000, status: 0, date: '2026-02-23', shop: '10301', partsName: 'センサーユニット SU-100', targetEquip: 'スイング診断機 GST-7', reason: 'センサー応答が遅くなっている' },
      { id: 'ORD-2026-004', type: 'equipment', category: 'golf', title: 'ゴルフボール 1ダース × 10', amount: 42000, status: 2, date: '2026-02-22', shop: '10301', supplier: 'ゴルフサプライ', items: 'ゴルフボール 1ダース × 10' },
      { id: 'ORD-2026-005', type: 'repair', category: 'fitness', title: 'エアロバイク 表示パネル故障', amount: null, status: 1, date: '2026-02-21', shop: '10301', equipment: 'エアロバイク AB-200', issue: '液晶パネルが表示されない', repairCost: '35000', repairDate: '2026-03-10', repairStatus: '見積もり済' },
      { id: 'ORD-2026-006', type: 'repair', category: 'golf', title: 'パッティングマシン モーター異常', amount: null, status: 3, date: '2026-02-18', shop: '10301', equipment: 'パッティングマシン PM-300', issue: 'モーターが回転しない', repairCost: '48000', repairDate: '2026-02-28', repairStatus: '修理完了' }
    ];

    // ===== Admin-view orders (all stores) =====
    var adminOrders = [
      { id: 'ORD-2026-001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常', amount: null, status: 0, date: '2026-02-25', shop: '10301', equipment: 'ランニングマシン TR-800', issue: 'ベルトが滑る。異音が発生。', repairCost: '', repairDate: '', repairStatus: '' },
      { id: 'ORD-2026-002', type: 'equipment', category: 'fitness', title: 'トレーニングマット × 5', amount: 17500, status: 1, date: '2026-02-24', shop: '10301', supplier: 'フィットネスジャパン', items: 'トレーニングマット × 5' },
      { id: 'ORD-2026-003', type: 'parts', category: 'golf', title: 'スイング診断機 センサー交換部品', amount: 12000, status: 0, date: '2026-02-23', shop: '10301', partsName: 'センサーユニット SU-100', targetEquip: 'スイング診断機 GST-7', reason: 'センサー応答が遅くなっている' },
      { id: 'ORD-2026-004', type: 'equipment', category: 'golf', title: 'ゴルフボール 1ダース × 10', amount: 42000, status: 2, date: '2026-02-22', shop: '10301', supplier: 'ゴルフサプライ', items: 'ゴルフボール 1ダース × 10' },
      { id: 'ORD-2026-005', type: 'repair', category: 'fitness', title: 'エアロバイク 表示パネル故障', amount: null, status: 1, date: '2026-02-21', shop: '10301', equipment: 'エアロバイク AB-200', issue: '液晶パネルが表示されない', repairCost: '35000', repairDate: '2026-03-10', repairStatus: '見積もり済' },
      { id: 'ORD-2026-006', type: 'repair', category: 'golf', title: 'パッティングマシン モーター異常', amount: null, status: 3, date: '2026-02-18', shop: '10301', equipment: 'パッティングマシン PM-300', issue: 'モーターが回転しない', repairCost: '48000', repairDate: '2026-02-28', repairStatus: '修理完了' },
      // 札幌店
      { id: 'ORD-2026-007', type: 'equipment', category: 'fitness', title: 'ダンベルセット 10kg × 3', amount: 25200, status: 1, date: '2026-02-24', shop: '10101', supplier: 'フィットネスジャパン', items: 'ダンベルセット 10kg × 3' },
      { id: 'ORD-2026-008', type: 'repair', category: 'fitness', title: 'トレッドミル 異音発生', amount: null, status: 0, date: '2026-02-23', shop: '10101', equipment: 'トレッドミル TM-500', issue: '動作時に異音が発生', repairCost: '', repairDate: '', repairStatus: '' },
      // 函館店
      { id: 'ORD-2026-009', type: 'parts', category: 'fitness', title: 'エアロバイク ペダル交換部品', amount: 8500, status: 2, date: '2026-02-22', shop: '10102', partsName: 'ペダルユニット PD-200', targetEquip: 'エアロバイク AB-150', reason: 'ペダル軸の摩耗' },
      // 池袋西口店
      { id: 'ORD-2026-010', type: 'equipment', category: 'golf', title: 'ゴルフグローブ L × 20', amount: 30000, status: 1, date: '2026-02-25', shop: '10302', supplier: 'ゴルフサプライ', items: 'ゴルフグローブ L × 20' },
      { id: 'ORD-2026-011', type: 'repair', category: 'golf', title: 'シミュレーター プロジェクター不具合', amount: null, status: 0, date: '2026-02-24', shop: '10302', equipment: 'ゴルフシミュレーター GS-Pro', issue: 'プロジェクターの映像がちらつく', repairCost: '', repairDate: '', repairStatus: '' },
      // 横浜店
      { id: 'ORD-2026-012', type: 'equipment', category: 'fitness', title: 'タオル（大）10枚セット × 3', amount: 16800, status: 2, date: '2026-02-20', shop: '10303', supplier: 'リネンサービス', items: 'タオル（大）10枚セット × 3' },
      // 仙台店
      { id: 'ORD-2026-013', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ', amount: null, status: 1, date: '2026-02-19', shop: '10201', equipment: 'レッグプレス LP-400', issue: '油圧シリンダーから微量の漏れ', repairCost: '65000', repairDate: '2026-03-15', repairStatus: '見積もり済' },
      // 梅田店
      { id: 'ORD-2026-014', type: 'equipment', category: 'golf', title: 'スコアカード 100枚 × 5', amount: 6000, status: 3, date: '2026-02-17', shop: '20101', supplier: 'ゴルフサプライ', items: 'スコアカード 100枚 × 5' },
      { id: 'ORD-2026-015', type: 'parts', category: 'golf', title: 'スイングカメラ レンズユニット', amount: 45000, status: 0, date: '2026-02-26', shop: '20101', partsName: 'レンズユニット LC-300', targetEquip: 'スイングカメラ SC-200', reason: 'レンズに傷。映像にノイズ' },
      // 難波店
      { id: 'ORD-2026-016', type: 'repair', category: 'fitness', title: 'ランニングマシン 速度制御不良', amount: null, status: 0, date: '2026-02-26', shop: '20102', equipment: 'ランニングマシン TR-900', issue: '速度が安定しない', repairCost: '', repairDate: '', repairStatus: '' },
      // 広島店
      { id: 'ORD-2026-017', type: 'equipment', category: 'fitness', title: 'ヨガマット × 10', amount: 15000, status: 1, date: '2026-02-25', shop: '20201', supplier: 'フィットネスジャパン', items: 'ヨガマット × 10' }
    ];

    var expandedId = null;

    // ===== View Mode Switch =====
    function switchView(mode) {
      viewMode = mode;
      expandedId = null;
      document.getElementById('btnStoreView').classList.toggle('active', mode === 'store');
      document.getElementById('btnAdminView').classList.toggle('active', mode === 'admin');
      document.getElementById('storeFilterBar').style.display = mode === 'store' ? '' : 'none';
      document.getElementById('adminFilterBar').style.display = mode === 'admin' ? 'block' : 'none';
      document.getElementById('adminActionBar').style.display = mode === 'admin' ? 'flex' : 'none';

      // Update header
      var header = document.querySelector('.header-user');
      if (mode === 'admin') {
        header.textContent = '本部管理者：鈴木一郎様';
      } else {
        header.textContent = '新宿東口店：田中太郎様';
      }

      renderTableHeader();
      renderOrders();
    }

    // ===== Dynamic Table Header =====
    function renderTableHeader() {
      var thead = document.getElementById('orderTableHead');
      if (viewMode === 'admin') {
        thead.innerHTML = '<tr>' +
          '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
          '<th>発注番号</th>' +
          '<th>店舗</th>' +
          '<th>種別</th>' +
          '<th>カテゴリ</th>' +
          '<th>内容</th>' +
          '<th>金額</th>' +
          '<th>ステータス</th>' +
          '<th>発注日</th>' +
          '<th style="width:60px">詳細</th>' +
        '</tr>';
      } else {
        thead.innerHTML = '<tr>' +
          '<th style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>' +
          '<th>発注番号</th>' +
          '<th>種別</th>' +
          '<th>カテゴリ</th>' +
          '<th>内容</th>' +
          '<th>金額</th>' +
          '<th>ステータス</th>' +
          '<th>発注日</th>' +
          '<th style="width:60px">詳細</th>' +
        '</tr>';
      }
    }

    // ===== Cascading Zone > Area > Shop =====
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

    // ===== Shop name lookup =====
    function getShopName(code) {
      var shop = shops.find(function(s) { return s.code === code; });
      return shop ? shop.name : code;
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
          // Shop / Area / Zone cascading filter
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

      var colSpan = viewMode === 'admin' ? 10 : 9;
      var tbody = document.getElementById('orderTableBody');
      var html = '';

      filtered.forEach(function(o) {
        var typeClass = 'type-' + o.type;
        var typeLabel = o.type === 'repair' ? '修理' : o.type === 'equipment' ? '備品' : '部品';
        var statusClass = o.status === 0 ? 'status-draft' : o.status === 1 ? 'status-confirmed' : o.status === 2 ? 'status-finalized' : 'status-completed';
        var statusLabel = o.status === 0 ? '仮登録' : o.status === 1 ? '本登録' : o.status === 2 ? '確定済' : '完了';
        var catLabel = o.category === 'fitness' ? 'フィットネス' : 'ゴルフ';
        var amountStr = o.amount ? '¥' + o.amount.toLocaleString() : (o.repairCost ? '¥' + parseInt(o.repairCost).toLocaleString() : '—');
        var isOpen = expandedId === o.id;

        html += '<tr class="order-row ' + o.type + '">' +
          '<td><input type="checkbox" class="order-check" data-id="' + o.id + '"></td>' +
          '<td><strong>' + o.id + '</strong></td>';

        if (viewMode === 'admin') {
          html += '<td>' + getShopName(o.shop) + '</td>';
        }

        html += '<td><span class="type-badge ' + typeClass + '">' + typeLabel + '</span></td>' +
          '<td>' + catLabel + '</td>' +
          '<td>' + o.title + '</td>' +
          '<td>' + amountStr + '</td>' +
          '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
          '<td>' + o.date + '</td>' +
          '<td><button class="btn-sm" onclick="toggleDetail(\'' + o.id + '\')">' + (isOpen ? '閉じる' : '詳細') + '</button></td>' +
        '</tr>';

        // Detail row
        html += '<tr class="detail-panel' + (isOpen ? ' open' : '') + '" id="detail-' + o.id + '"><td colspan="' + colSpan + '"><div class="detail-content">';
        if (viewMode === 'admin') {
          html += '<div class="detail-grid"><div class="detail-item"><div class="detail-label">店舗</div><div class="detail-value">' + o.shop + ':' + getShopName(o.shop) + '</div></div></div>';
        }
        if (o.type === 'repair') {
          html += '<div class="detail-grid">' +
            '<div class="detail-item"><div class="detail-label">故障機材</div><div class="detail-value">' + (o.equipment || '') + '</div></div>' +
            '<div class="detail-item"><div class="detail-label">不具合内容</div><div class="detail-value">' + (o.issue || '') + '</div></div>' +
          '</div>' +
          '<div class="repair-fields"><div class="repair-fields-title">修理情報（編集可能）</div>' +
            '<div class="repair-field-row">' +
              '<span class="repair-field-label">修理金額</span>' +
              '<input type="number" class="repair-field-input" value="' + (o.repairCost || '') + '" placeholder="金額を入力" style="width:150px">' +
            '</div>' +
            '<div class="repair-field-row">' +
              '<span class="repair-field-label">修理完了予定日</span>' +
              '<input type="date" class="repair-field-input" value="' + (o.repairDate || '') + '">' +
            '</div>' +
            '<div class="repair-field-row">' +
              '<span class="repair-field-label">修理状況</span>' +
              '<select class="repair-field-select"><option value="">選択</option><option' + (o.repairStatus === '見積もり済' ? ' selected' : '') + '>見積もり済</option><option' + (o.repairStatus === '修理中' ? ' selected' : '') + '>修理中</option><option' + (o.repairStatus === '修理完了' ? ' selected' : '') + '>修理完了</option></select>' +
            '</div>' +
          '</div>';
        } else if (o.type === 'equipment') {
          html += '<div class="detail-grid">' +
            '<div class="detail-item"><div class="detail-label">発注内容</div><div class="detail-value">' + (o.items || '') + '</div></div>' +
            '<div class="detail-item"><div class="detail-label">仕入先</div><div class="detail-value">' + (o.supplier || '') + '</div></div>' +
          '</div>';
        } else {
          html += '<div class="detail-grid">' +
            '<div class="detail-item"><div class="detail-label">部品名</div><div class="detail-value">' + (o.partsName || '') + '</div></div>' +
            '<div class="detail-item"><div class="detail-label">対象機材</div><div class="detail-value">' + (o.targetEquip || '') + '</div></div>' +
            '<div class="detail-item"><div class="detail-label">発注理由</div><div class="detail-value">' + (o.reason || '') + '</div></div>' +
          '</div>';
        }
        html += '<div class="detail-actions">';
        if (o.type === 'repair') {
          html += '<button class="btn-sm" onclick="saveRepairDetail(\'' + o.id + '\')">変更を保存</button>';
          if (o.status !== 3) {
            html += '<button class="btn-sm complete" onclick="completeRepair(\'' + o.id + '\')">修理完了</button>';
          }
        }
        html += '<button class="btn-sm danger" onclick="deleteOrder(\'' + o.id + '\')">削除</button>';
        html += '</div></div></td></tr>';
      });

      if (!filtered.length) {
        html = '<tr><td colspan="' + colSpan + '" style="text-align:center;padding:40px;color:#94a3b8;">該当する発注がありません</td></tr>';
      }

      tbody.innerHTML = html;
    }

    function toggleDetail(id) {
      expandedId = expandedId === id ? null : id;
      renderOrders();
    }

    function toggleAll(checkbox) {
      document.querySelectorAll('.order-check').forEach(function(cb) { cb.checked = checkbox.checked; });
    }

    function confirmSelected() {
      var selected = [];
      document.querySelectorAll('.order-check:checked').forEach(function(cb) { selected.push(cb.getAttribute('data-id')); });
      if (!selected.length) { alert('発注を選択してください'); return; }
      var orders = viewMode === 'admin' ? adminOrders : storeOrders;
      alert('正式発注に変更しました：' + selected.join(', '));
      selected.forEach(function(id) {
        var order = orders.find(function(o) { return o.id === id; });
        if (order && order.status === 0) order.status = 1;
      });
      renderOrders();
    }

    function exportExcel() { alert('Excel出力（モックアップ）\n\n選択された発注をExcelファイルとしてダウンロードします。\nダウンロード時にステータスが「確定済」に変更されます。'); }
    function saveRepairDetail(id) { alert('修理情報を保存しました（モックアップ）'); }
    function completeRepair(id) {
      var orders = viewMode === 'admin' ? adminOrders : storeOrders;
      var order = orders.find(function(o) { return o.id === id; });
      if (order) { order.status = 3; order.repairStatus = '修理完了'; }
      renderOrders();
      alert('修理完了に変更しました');
    }
    function deleteOrder(id) {
      if (confirm('この発注を削除しますか？')) {
        if (viewMode === 'admin') {
          adminOrders = adminOrders.filter(function(o) { return o.id !== id; });
        } else {
          storeOrders = storeOrders.filter(function(o) { return o.id !== id; });
        }
        expandedId = null;
        renderOrders();
      }
    }

    // ===== Init =====
    renderOrders();
