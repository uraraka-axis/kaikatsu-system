    var orders = [
      { id: 'ORD-2026-001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常', amount: null, status: 0, date: '2026-02-25', equipment: 'ランニングマシン TR-800', issue: 'ベルトが滑る。異音が発生。', repairCost: '', repairDate: '', repairStatus: '' },
      { id: 'ORD-2026-002', type: 'equipment', category: 'fitness', title: 'トレーニングマット × 5', amount: 17500, status: 1, date: '2026-02-24', supplier: 'フィットネスジャパン', items: 'トレーニングマット × 5' },
      { id: 'ORD-2026-003', type: 'parts', category: 'golf', title: 'スイング診断機 センサー交換部品', amount: 12000, status: 0, date: '2026-02-23', partsName: 'センサーユニット SU-100', targetEquip: 'スイング診断機 GST-7', reason: 'センサー応答が遅くなっている' },
      { id: 'ORD-2026-004', type: 'equipment', category: 'golf', title: 'ゴルフボール 1ダース × 10', amount: 42000, status: 2, date: '2026-02-22', supplier: 'ゴルフサプライ', items: 'ゴルフボール 1ダース × 10' },
      { id: 'ORD-2026-005', type: 'repair', category: 'fitness', title: 'エアロバイク 表示パネル故障', amount: null, status: 1, date: '2026-02-21', equipment: 'エアロバイク AB-200', issue: '液晶パネルが表示されない', repairCost: '35000', repairDate: '2026-03-10', repairStatus: '見積もり済' },
      { id: 'ORD-2026-006', type: 'repair', category: 'golf', title: 'パッティングマシン モーター異常', amount: null, status: 3, date: '2026-02-18', equipment: 'パッティングマシン PM-300', issue: 'モーターが回転しない', repairCost: '48000', repairDate: '2026-02-28', repairStatus: '修理完了' },
    ];

    var expandedId = null;

    function renderOrders() {
      var catFilter = document.getElementById('filterCategory').value;
      var typeFilter = document.getElementById('filterType').value;
      var statusFilter = document.getElementById('filterStatus').value;

      var filtered = orders.filter(function(o) {
        if (catFilter && o.category !== catFilter) return false;
        if (typeFilter && o.type !== typeFilter) return false;
        if (statusFilter !== '' && o.status !== parseInt(statusFilter)) return false;
        return true;
      });

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
          '<td><strong>' + o.id + '</strong></td>' +
          '<td><span class="type-badge ' + typeClass + '">' + typeLabel + '</span></td>' +
          '<td>' + catLabel + '</td>' +
          '<td>' + o.title + '</td>' +
          '<td>' + amountStr + '</td>' +
          '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
          '<td>' + o.date + '</td>' +
          '<td><button class="btn-sm" onclick="toggleDetail(\'' + o.id + '\')">' + (isOpen ? '閉じる' : '詳細') + '</button></td>' +
        '</tr>';

        // Detail row
        html += '<tr class="detail-panel' + (isOpen ? ' open' : '') + '" id="detail-' + o.id + '"><td colspan="9"><div class="detail-content">';
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
      var order = orders.find(function(o) { return o.id === id; });
      if (order) { order.status = 3; order.repairStatus = '修理完了'; }
      renderOrders();
      alert('修理完了に変更しました');
    }
    function deleteOrder(id) {
      if (confirm('この発注を削除しますか？')) {
        orders = orders.filter(function(o) { return o.id !== id; });
        expandedId = null;
        renderOrders();
      }
    }

    renderOrders();
