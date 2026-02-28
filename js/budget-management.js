    var currentYear = 2026;
    var currentMonth = 2;

    function changeMonth(delta) {
      currentMonth += delta;
      if (currentMonth > 12) { currentMonth = 1; currentYear++; }
      if (currentMonth < 1) { currentMonth = 12; currentYear--; }
      document.getElementById('monthLabel').textContent = currentYear + '年' + currentMonth + '月';
    }

    function switchCategory(btn, cat) {
      document.querySelectorAll('.cat-tab').forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      // In real app, filter the table data by category
    }

    function submitProcurement() {
      var data = {
        category: document.getElementById('procCategory').value,
        amount: document.getElementById('procAmount').value,
        reason: document.getElementById('procReason').value
      };
      if (!data.amount || !data.reason) { alert('金額と理由を入力してください'); return; }
      alert('自店調達を申請しました（モックアップ）\n\n' + JSON.stringify(data, null, 2));
    }
