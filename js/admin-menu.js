    function uploadFile(type) {
      var labels = {
        zone: 'ゾーン', area: 'エリア', shop: '店舗',
        user: 'ユーザー', supplier: '仕入先', product: '商品', budget: '予算'
      };
      var label = labels[type] || type;
      alert('マスタアップロード（モックアップ）\n\n「' + label + '」のExcelファイルをアップロードします。\n予約→バッチ処理→反映の安全な仕組みで更新されます。');
    }

    function exportOrderData() {
      var period = document.getElementById('exportPeriod').value;
      var type = document.getElementById('exportType').value;
      var status = document.getElementById('exportStatus').value;
      alert('発注データ出力（モックアップ）\n\n期間: ' + period + '\n種別: ' + type + '\nステータス: ' + status + '\n\nExcelファイルをダウンロードします。');
    }

    function exportBudgetData() {
      var year = document.getElementById('exportBudgetYear').value;
      var zone = document.getElementById('exportBudgetZone').value;
      alert('予算管理データ出力（モックアップ）\n\n年度: ' + year + '\nゾーン: ' + zone + '\n\nExcelファイルをダウンロードします。');
    }
