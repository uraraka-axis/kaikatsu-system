    var requests = [
      { id: 'REQ-2026-001', category: 'fitness', amount: 15000, reason: '緊急でマットを購入', date: '2026-02-26', status: 'pending', approver: '' },
      { id: 'REQ-2026-002', category: 'golf', amount: 12000, reason: 'ティーが不足しているため', date: '2026-02-24', status: 'approved', approver: '鈴木一郎（ZM）', approvedDate: '2026-02-25' },
      { id: 'REQ-2026-003', category: 'fitness', amount: 20000, reason: 'ダンベルの追加購入', date: '2026-02-22', status: 'approved', approver: '鈴木一郎（ZM）', approvedDate: '2026-02-23' },
      { id: 'REQ-2026-004', category: 'golf', amount: 8000, reason: 'グローブの補充', date: '2026-02-20', status: 'rejected', approver: '鈴木一郎（ZM）', approvedDate: '2026-02-21' },
      { id: 'REQ-2026-005', category: 'fitness', amount: 10000, reason: '消毒スプレーの大量購入', date: '2026-02-18', status: 'approved', approver: '鈴木一郎（ZM）', approvedDate: '2026-02-19' },
    ];

    function renderTable() {
      var statusFilter = document.getElementById('filterStatus').value;
      var catFilter = document.getElementById('filterCategory').value;

      var filtered = requests.filter(function(r) {
        if (statusFilter && r.status !== statusFilter) return false;
        if (catFilter && r.category !== catFilter) return false;
        return true;
      });

      var tbody = document.getElementById('tableBody');
      if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><p>該当する申請がありません</p></div></td></tr>';
        return;
      }

      tbody.innerHTML = filtered.map(function(r) {
        var statusClass = r.status === 'pending' ? 'status-pending' : r.status === 'approved' ? 'status-approved' : 'status-rejected';
        var statusLabel = r.status === 'pending' ? '未承認' : r.status === 'approved' ? '承認' : '否決';
        var catLabel = r.category === 'fitness' ? 'フィットネス' : 'ゴルフ';
        var canCancel = r.status === 'pending';

        return '<tr>' +
          '<td><strong>' + r.id + '</strong></td>' +
          '<td>' + catLabel + '</td>' +
          '<td class="right">¥' + r.amount.toLocaleString() + '</td>' +
          '<td>' + r.reason + '</td>' +
          '<td>' + r.date + '</td>' +
          '<td><span class="status-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
          '<td>' + (r.approver || '—') + '</td>' +
          '<td>' + (canCancel ? '<button class="btn-cancel" onclick="cancelRequest(\'' + r.id + '\')">取消</button>' : '—') + '</td>' +
        '</tr>';
      }).join('');
    }

    function cancelRequest(id) {
      if (!confirm('申請「' + id + '」を取り消しますか？')) return;
      requests = requests.filter(function(r) { return r.id !== id; });
      renderTable();
      alert('申請を取り消しました');
    }

    renderTable();
