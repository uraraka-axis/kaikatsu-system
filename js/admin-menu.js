    // ===== Master Data (shared with order-list) =====
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
    var shopList = [
      { code: '10301', name: '新宿東口', shortCode: 'S01', area: '103' },
      { code: '10302', name: '池袋西口', shortCode: 'S02', area: '103' },
      { code: '10303', name: '横浜', shortCode: 'S03', area: '103' },
      { code: '10101', name: '札幌', shortCode: 'S04', area: '101' },
      { code: '10102', name: '函館', shortCode: 'S05', area: '101' },
      { code: '10201', name: '仙台', shortCode: 'S06', area: '102' },
      { code: '20101', name: '梅田', shortCode: 'S07', area: '201' },
      { code: '20102', name: '難波', shortCode: 'S08', area: '201' },
      { code: '20201', name: '広島', shortCode: 'S09', area: '202' }
    ];

    function getShopName(code) {
      var s = shopList.find(function(s) { return s.code === code; });
      return s ? s.name : code;
    }

    function getShopArea(code) {
      var s = shopList.find(function(s) { return s.code === code; });
      return s ? s.area : '';
    }

    function getAreaZone(areaCode) {
      var a = areas.find(function(a) { return a.code === areaCode; });
      return a ? a.zone : '';
    }

    // ===== Sample Order Data =====
    var TYPE_LABELS = { repair: '修理', equipment: '備品', parts: '部品' };
    var STATUS_LABELS = {
      repair:    { 0: '依頼中', 1: '発注済', 2: '修理待ち', 3: '修理済', 4: '完了' },
      equipment: { 0: '依頼中', 1: '発注済', 2: '配達中', 3: '納品済', 4: '完了' },
      parts:     { 0: '依頼中', 1: '発注済', 2: '配達中', 3: '納品済', 4: '完了' }
    };

    var orderData = [
      { id: 'REP-S01-20260301-0001', type: 'repair', category: 'fitness', title: 'ランニングマシン ベルト異常', amount: null, status: 0, date: '2026-03-01', shop: '10301' },
      { id: 'EQU-S01-20260228-0001', type: 'equipment', category: 'fitness', title: 'トレーニングベンチ × 2', amount: 48000, status: 1, date: '2026-02-28', shop: '10301' },
      { id: 'PTS-S01-20260227-0001', type: 'parts', category: 'fitness', title: 'エアロバイク チェーン交換', amount: null, status: 0, date: '2026-02-27', shop: '10301' },
      { id: 'REP-S01-20260225-0001', type: 'repair', category: 'golf', title: 'スイングセンサー 反応不良', amount: null, status: 1, date: '2026-02-25', shop: '10301' },
      { id: 'EQU-S01-20260224-0002', type: 'equipment', category: 'fitness', title: 'エアロバイク他 2商品', amount: 324000, status: 1, date: '2026-02-24', shop: '10301' },
      { id: 'EQU-S01-20260224-0001', type: 'equipment', category: 'fitness', title: 'プロテイン 5kg × 3', amount: 18000, status: 2, date: '2026-02-24', shop: '10301' },
      { id: 'PTS-S01-20260222-0001', type: 'parts', category: 'golf', title: 'パター グリップ交換', amount: null, status: 2, date: '2026-02-22', shop: '10301' },
      { id: 'REP-S01-20260221-0001', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ', amount: null, status: 2, date: '2026-02-21', shop: '10301' },
      { id: 'EQU-S01-20260220-0001', type: 'equipment', category: 'fitness', title: 'ダンベルセット 10kg × 5', amount: 42000, status: 3, date: '2026-02-20', shop: '10301' },
      { id: 'PTS-S01-20260218-0001', type: 'parts', category: 'fitness', title: 'ランニングマシン モーターベルト', amount: null, status: 3, date: '2026-02-18', shop: '10301' },
      { id: 'REP-S01-20260217-0001', type: 'repair', category: 'fitness', title: 'チェストプレス ケーブル交換', amount: null, status: 3, date: '2026-02-17', shop: '10301' },
      { id: 'EQU-S01-20260215-0001', type: 'equipment', category: 'golf', title: 'ゴルフグローブ L × 20', amount: 30000, status: 4, date: '2026-02-15', shop: '10301' },
      { id: 'PTS-S01-20260213-0001', type: 'parts', category: 'golf', title: 'シミュレーター ランプ交換', amount: null, status: 4, date: '2026-02-13', shop: '10301' },
      { id: 'REP-S01-20260210-0001', type: 'repair', category: 'fitness', title: 'エリプティカル 異音修理', amount: null, status: 4, date: '2026-02-10', shop: '10301' },
      { id: 'EQU-S01-20260208-0001', type: 'equipment', category: 'fitness', title: 'バランスボール × 10', amount: 12000, status: 4, date: '2026-02-08', shop: '10301' },
      { id: 'REP-S04-20260223-0001', type: 'repair', category: 'fitness', title: 'トレッドミル 異音発生', amount: null, status: 0, date: '2026-02-23', shop: '10101' },
      { id: 'EQU-S04-20260224-0001', type: 'equipment', category: 'fitness', title: 'ダンベルセット 10kg × 3', amount: 25200, status: 1, date: '2026-02-24', shop: '10101' },
      { id: 'PTS-S05-20260222-0001', type: 'parts', category: 'fitness', title: 'エアロバイク ペダル交換部品', amount: null, status: 2, date: '2026-02-22', shop: '10102' },
      { id: 'EQU-S02-20260225-0001', type: 'equipment', category: 'golf', title: 'グローブ Lサイズ 他1商品', amount: 38000, status: 1, date: '2026-02-25', shop: '10302' },
      { id: 'REP-S02-20260224-0001', type: 'repair', category: 'golf', title: 'シミュレーター プロジェクター不具合', amount: null, status: 0, date: '2026-02-24', shop: '10302' },
      { id: 'EQU-S03-20260220-0001', type: 'equipment', category: 'fitness', title: 'タオル（大）10枚セット × 3', amount: 16800, status: 3, date: '2026-02-20', shop: '10303' },
      { id: 'REP-S06-20260219-0001', type: 'repair', category: 'fitness', title: 'レッグプレスマシン 油圧漏れ', amount: null, status: 2, date: '2026-02-19', shop: '10201' },
      { id: 'EQU-S07-20260217-0001', type: 'equipment', category: 'golf', title: 'スコアカード 100枚 × 5', amount: 6000, status: 4, date: '2026-02-17', shop: '20101' },
      { id: 'PTS-S07-20260226-0001', type: 'parts', category: 'golf', title: 'スイングカメラ レンズユニット', amount: null, status: 0, date: '2026-02-26', shop: '20101' },
      { id: 'REP-S08-20260226-0001', type: 'repair', category: 'fitness', title: 'ランニングマシン 速度制御不良', amount: null, status: 0, date: '2026-02-26', shop: '20102' },
      { id: 'EQU-S09-20260225-0001', type: 'equipment', category: 'fitness', title: 'ヨガマット × 10', amount: 15000, status: 1, date: '2026-02-25', shop: '20201' }
    ];

    // ===== Budget Data =====
    var fiscalMonths = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
    var departments = [
      { key: 'all', label: '全体' },
      { key: 'fit', label: 'フィットネス' },
      { key: 'ig',  label: 'インドアゴルフ' }
    ];

    var budgetData = [
      {
        shop: '10101:札幌', zone: '100', area: '101', shopCode: '10101',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10102:函館', zone: '100', area: '101', shopCode: '10102',
        period: [130000, 1700, 128300, 1.3], midterm: [85000, 1700, 83300, 2.0], month: [10000, 1700, 8300, 17.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,20000],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,15000],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,5000],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10103:旭川', zone: '100', area: '101', shopCode: '10103',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10201:弘前', zone: '100', area: '102', shopCode: '10201',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10202:盛岡', zone: '100', area: '102', shopCode: '10202',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fit: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          ig:  [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      }
    ];

    // ===== Master Upload / Download =====
    // 実装済みマスタ（バックエンドAPI存在）
    var IMPLEMENTED_MASTERS = ['zone', 'area', 'shop', 'supplier', 'user']; // 実装が進むごとに追記

    // 各マスタの API パス
    function masterApiPath(type, kind) {
      // type: zone/area/shop/user/supplier/product
      // kind: 'upload' or 'download'
      var apiTypeMap = {
        zone: 'zones', area: 'areas', shop: 'shops',
        user: 'users', supplier: 'suppliers', product: 'products'
      };
      var apiType = apiTypeMap[type] || type;
      if (kind === 'upload')   return 'api/admin/master/'  + apiType + '.php';
      if (kind === 'download') return 'api/export/master/' + apiType + '.php';
      return null;
    }

    // 確定送信用に File を保持
    var pendingUploadFile = null;
    var pendingUploadType = null;

    function getMasterLabel(type) {
      var card = document.querySelector('.master-card[data-master-type="' + type + '"]');
      return card ? (card.getAttribute('data-master-label') || type) : type;
    }

    function triggerMasterUpload(type) {
      if (IMPLEMENTED_MASTERS.indexOf(type) < 0) {
        alert('「' + getMasterLabel(type) + '」は未実装です。');
        return;
      }
      var input = document.querySelector('input[data-master-input="' + type + '"]');
      if (input) input.click();
    }
    window.triggerMasterUpload = triggerMasterUpload;

    function handleMasterFileSelected(type, files) {
      if (!files || files.length === 0) return;
      var file = files[0];
      // バリデーション
      if (!file.name.toLowerCase().endsWith('.xlsx')) {
        alert('.xlsx形式のファイルを選択してください');
        clearMasterInput(type);
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        alert('ファイルサイズが上限(10MB)を超えています');
        clearMasterInput(type);
        return;
      }
      // dry_run実行 → プレビューモーダル表示
      pendingUploadFile = file;
      pendingUploadType = type;
      runMasterDryRun(type, file);
    }
    window.handleMasterFileSelected = handleMasterFileSelected;

    function clearMasterInput(type) {
      var input = document.querySelector('input[data-master-input="' + type + '"]');
      if (input) input.value = '';
    }

    function runMasterDryRun(type, file) {
      var url = masterApiPath(type, 'upload') + '?dry_run=1';
      var fd = new FormData();
      fd.append('file', file);
      showMasterModal({ loading: true, type: type });
      fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(res) {
          return res.json().then(function(json) { return { ok: res.ok, status: res.status, json: json }; });
        })
        .then(function(r) {
          // 成功 or 警告のみ(削除ブロック等)はプレビュー表示。バリデーションエラーはエラー表示。
          var hasDiff = r.json && r.json.data && r.json.data.diff;
          var hasErrors = r.json && r.json.data && Array.isArray(r.json.data.errors) && r.json.data.errors.length > 0;
          if (hasDiff && !hasErrors) {
            renderMasterPreview(type, r.json.data, r.json.error || null);
          } else {
            renderMasterErrors(type, r.json);
          }
        })
        .catch(function(e) {
          console.error('master dry-run error:', e);
          renderMasterErrors(type, { error: '通信エラーが発生しました' });
        });
    }

    function showMasterModal(opts) {
      var overlay = document.getElementById('masterModal');
      var titleEl = document.getElementById('masterModalTitle');
      var bodyEl = document.getElementById('masterModalBody');
      var footerEl = document.getElementById('masterModalFooter');
      if (!overlay) return;

      titleEl.textContent = getMasterLabel(opts.type) + ' 変更プレビュー';
      if (opts.loading) {
        bodyEl.innerHTML = '<div class="master-modal-loading">Excelを解析中…</div>';
        footerEl.innerHTML = '<button type="button" class="btn-secondary" onclick="closeMasterModal()">キャンセル</button>';
      }
      overlay.classList.add('visible');
    }

    function renderMasterPreview(type, data, topMessage) {
      var bodyEl = document.getElementById('masterModalBody');
      var footerEl = document.getElementById('masterModalFooter');
      var summary = data.summary || { insert: 0, update: 0, delete: 0, total: 0 };
      var warnings = data.warnings || [];
      var diff = data.diff || { insert: [], update: [], delete: [] };

      var html = '';
      if (topMessage) {
        html += '<div class="master-error-msg">' + escapeHtml(topMessage) + '</div>';
      }
      html += '<div class="master-summary">';
      html += '<span class="master-sum-item master-sum-insert">追加 ' + summary.insert + '件</span>';
      html += '<span class="master-sum-item master-sum-update">変更 ' + summary.update + '件</span>';
      html += '<span class="master-sum-item master-sum-delete">削除 ' + summary.delete + '件</span>';
      if (warnings.length > 0) {
        html += '<span class="master-sum-item master-sum-warn">警告 ' + warnings.length + '件</span>';
      }
      html += '</div>';

      if (summary.total === 0 && warnings.length === 0) {
        html += '<div class="master-empty-msg">変更内容はありません（現在のDBと一致しています）</div>';
      }

      // 警告
      if (warnings.length > 0) {
        html += '<div class="master-section master-section-warn">';
        html += '<div class="master-section-title">⚠ 削除できないレコード</div>';
        html += '<ul class="master-warning-list">';
        warnings.forEach(function(w) {
          html += '<li>' + escapeHtml(w.message) + '</li>';
        });
        html += '</ul></div>';
      }

      // 追加
      if (diff.insert && diff.insert.length > 0) {
        html += renderDiffSection('追加', diff.insert.map(function(r) {
          return { label: makeRowLabel(type, r), detail: '' };
        }), 'insert');
      }
      // 変更
      if (diff.update && diff.update.length > 0) {
        html += renderDiffSection('変更', diff.update.map(function(u) {
          var changedSummary = u.changed_fields.map(function(f) {
            var b = u.before[f], a = u.after[f];
            return f + ': ' + escapeHtml(String(b)) + ' → ' + escapeHtml(String(a));
          }).join(' / ');
          return { label: makeRowLabel(type, u.after) + ' (key=' + u.key + ')', detail: changedSummary };
        }), 'update');
      }
      // 削除
      if (diff.delete && diff.delete.length > 0) {
        html += renderDiffSection('削除', diff.delete.map(function(r) {
          return { label: makeRowLabel(type, r), detail: '' };
        }), 'delete');
      }

      bodyEl.innerHTML = html;

      // フッター: 確定可否
      var canApply = summary.total > 0 && warnings.length === 0;
      footerEl.innerHTML =
        '<button type="button" class="btn-secondary" onclick="closeMasterModal()">キャンセル</button>' +
        '<button type="button" class="btn-primary" id="btnApplyMaster"' + (canApply ? '' : ' disabled') + ' onclick="confirmMasterApply()">この内容で確定</button>';
    }

    function makeRowLabel(type, row) {
      // 各マスタごとに簡易ラベル
      if (type === 'zone')     return (row.code || '') + ' ' + (row.name || '');
      if (type === 'area')     return (row.code || '') + ' ' + (row.name || '');
      if (type === 'shop')     return (row.code || '') + ' ' + (row.name || '');
      if (type === 'supplier') return (row.code || '') + ' ' + (row.name || '');
      if (type === 'user')     return (row.login_id || '') + ' ' + (row.name || '');
      if (type === 'product')  return (row.code || '') + ' ' + (row.name || '');
      return JSON.stringify(row);
    }

    function renderDiffSection(title, items, kind) {
      var html = '<div class="master-section master-section-' + kind + '">';
      html += '<div class="master-section-title">' + title + ' (' + items.length + '件)</div>';
      html += '<ul class="master-diff-list">';
      items.forEach(function(it) {
        html += '<li><div class="master-diff-label">' + escapeHtml(it.label) + '</div>';
        if (it.detail) html += '<div class="master-diff-detail">' + it.detail + '</div>';
        html += '</li>';
      });
      html += '</ul></div>';
      return html;
    }

    function renderMasterErrors(type, json) {
      var bodyEl = document.getElementById('masterModalBody');
      var footerEl = document.getElementById('masterModalFooter');
      var errors = (json && json.data && json.data.errors) ? json.data.errors : [];
      var msg = (json && json.error) ? json.error : 'エラーが発生しました';
      var html = '<div class="master-error-msg">' + escapeHtml(msg) + '</div>';
      if (errors.length > 0) {
        html += '<ul class="master-error-list">';
        errors.forEach(function(e) {
          html += '<li>行 ' + e.row + ' / ' + escapeHtml(e.column || '') + ' = "' + escapeHtml(String(e.value || '')) + '"<br><span class="master-error-detail">' + escapeHtml(e.message) + '</span></li>';
        });
        html += '</ul>';
      }
      bodyEl.innerHTML = html;
      footerEl.innerHTML = '<button type="button" class="btn-secondary" onclick="closeMasterModal()">閉じる</button>';
    }

    function confirmMasterApply() {
      if (!pendingUploadFile || !pendingUploadType) return;
      var type = pendingUploadType;
      var file = pendingUploadFile;
      var url = masterApiPath(type, 'upload');
      var fd = new FormData();
      fd.append('file', file);

      var btn = document.getElementById('btnApplyMaster');
      if (btn) { btn.disabled = true; btn.textContent = '反映中…'; }

      fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(res) { return res.json().then(function(json) { return { ok: res.ok, json: json }; }); })
        .then(function(r) {
          if (r.json && r.json.success) {
            var s = r.json.data.summary || {};
            alert(getMasterLabel(type) + 'を更新しました\n追加: ' + s.insert + '件 / 変更: ' + s.update + '件 / 削除: ' + s.delete + '件');
            closeMasterModal();
            clearMasterInput(type);
          } else {
            renderMasterErrors(type, r.json);
          }
        })
        .catch(function(e) {
          console.error('master apply error:', e);
          alert('通信エラーが発生しました');
        });
    }
    window.confirmMasterApply = confirmMasterApply;

    function closeMasterModal() {
      var overlay = document.getElementById('masterModal');
      if (overlay) overlay.classList.remove('visible');
      if (pendingUploadType) clearMasterInput(pendingUploadType);
      pendingUploadFile = null;
      pendingUploadType = null;
    }
    window.closeMasterModal = closeMasterModal;

    function downloadMasterFile(type) {
      if (IMPLEMENTED_MASTERS.indexOf(type) < 0) {
        alert('「' + getMasterLabel(type) + '」は未実装です。');
        return;
      }
      window.location.href = masterApiPath(type, 'download');
    }
    window.downloadMasterFile = downloadMasterFile;

    function escapeHtml(s) {
      if (s === null || s === undefined) return '';
      return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ===== D&D 対応（upload-area へのドロップ） =====
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.master-card .upload-area').forEach(function(area) {
        var type = area.parentElement.getAttribute('data-master-type');
        if (!type) return;
        ['dragenter', 'dragover'].forEach(function(ev) {
          area.addEventListener(ev, function(e) {
            e.preventDefault(); e.stopPropagation();
            area.classList.add('dragover');
          });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
          area.addEventListener(ev, function(e) {
            e.preventDefault(); e.stopPropagation();
            area.classList.remove('dragover');
          });
        });
        area.addEventListener('drop', function(e) {
          if (IMPLEMENTED_MASTERS.indexOf(type) < 0) {
            alert('「' + getMasterLabel(type) + '」は未実装です。');
            return;
          }
          var files = e.dataTransfer && e.dataTransfer.files;
          if (files && files.length > 0) {
            handleMasterFileSelected(type, files);
          }
        });
      });
    });

    // ===== Cascade Filters: Order =====
    function getFilteredShops(zoneVal, areaVal) {
      var filtered = shopList;
      if (areaVal) {
        filtered = shopList.filter(function(s) { return s.area === areaVal; });
      } else if (zoneVal) {
        var areaCodes = areas.filter(function(a) { return a.zone === zoneVal; }).map(function(a) { return a.code; });
        filtered = shopList.filter(function(s) { return areaCodes.indexOf(s.area) >= 0; });
      }
      return filtered;
    }

    function buildShopOptions(filtered) {
      return '<option value="">すべて</option>' +
        filtered.map(function(s) { return '<option value="' + s.code + '">' + s.code + ':' + s.name + '</option>'; }).join('');
    }

    function buildAreaOptions(zoneVal) {
      var filtered = zoneVal ? areas.filter(function(a) { return a.zone === zoneVal; }) : areas;
      return '<option value="">すべて</option>' +
        filtered.map(function(a) { return '<option value="' + a.code + '">' + a.code + ':' + a.name + '</option>'; }).join('');
    }

    // Order filters
    function onOrderZoneChange() {
      var zoneVal = document.getElementById('exportOrderZone').value;
      document.getElementById('exportOrderArea').innerHTML = buildAreaOptions(zoneVal);
      onOrderAreaChange();
    }
    function onOrderAreaChange() {
      var zoneVal = document.getElementById('exportOrderZone').value;
      var areaVal = document.getElementById('exportOrderArea').value;
      document.getElementById('exportOrderShop').innerHTML = buildShopOptions(getFilteredShops(zoneVal, areaVal));
    }

    // Budget filters
    function onBudgetZoneChange() {
      var zoneVal = document.getElementById('exportBudgetZone').value;
      document.getElementById('exportBudgetArea').innerHTML = buildAreaOptions(zoneVal);
      onBudgetAreaChange();
    }
    function onBudgetAreaChange() {
      var zoneVal = document.getElementById('exportBudgetZone').value;
      var areaVal = document.getElementById('exportBudgetArea').value;
      document.getElementById('exportBudgetShop').innerHTML = buildShopOptions(getFilteredShops(zoneVal, areaVal));
    }

    // ===== CSV Helper =====
    function downloadCsv(rows, fileName) {
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

    function fmt(n) { return n != null ? n.toLocaleString() : ''; }

    function getSelectedText(id) {
      var el = document.getElementById(id);
      return el.options[el.selectedIndex].text;
    }

    // ===== Export: Order Data =====
    function getFilteredOrders() {
      var zoneVal = document.getElementById('exportOrderZone').value;
      var areaVal = document.getElementById('exportOrderArea').value;
      var shopVal = document.getElementById('exportOrderShop').value;
      var dateFrom = document.getElementById('exportDateFrom').value;
      var dateTo = document.getElementById('exportDateTo').value;
      var typeVal = document.getElementById('exportType').value;
      var statusVal = document.getElementById('exportStatus').value;

      return orderData.filter(function(o) {
        if (shopVal && o.shop !== shopVal) return false;
        if (!shopVal && areaVal) {
          if (getShopArea(o.shop) !== areaVal) return false;
        }
        if (!shopVal && !areaVal && zoneVal) {
          var shopArea = getShopArea(o.shop);
          if (getAreaZone(shopArea) !== zoneVal) return false;
        }
        if (dateFrom && o.date < dateFrom) return false;
        if (dateTo && o.date > dateTo) return false;
        if (typeVal && o.type !== typeVal) return false;
        if (statusVal !== '' && String(o.status) !== statusVal) return false;
        return true;
      });
    }

    function exportOrderData() {
      var filtered = getFilteredOrders();
      if (filtered.length === 0) {
        alert('出力対象のデータがありません。');
        return;
      }

      var rows = [];
      rows.push(['発注データ']);
      rows.push([]);

      // Filter conditions
      rows.push(['【ゾーン】', getSelectedText('exportOrderZone')]);
      rows.push(['【エリア】', getSelectedText('exportOrderArea')]);
      rows.push(['【店舗】', getSelectedText('exportOrderShop')]);
      var dateFrom = document.getElementById('exportDateFrom').value;
      var dateTo = document.getElementById('exportDateTo').value;
      var dateLabel = (dateFrom || '指定なし') + ' 〜 ' + (dateTo || '指定なし');
      rows.push(['【発注日】', dateLabel]);
      rows.push(['【種別】', getSelectedText('exportType')]);
      rows.push(['【ステータス】', getSelectedText('exportStatus')]);
      rows.push([]);

      // Header
      rows.push(['発注番号', '種別', '店舗', 'カテゴリ', '内容', '金額', 'ステータス', '発注日']);

      // Data
      filtered.sort(function(a, b) { return b.date.localeCompare(a.date); });
      filtered.forEach(function(o) {
        var typeLabel = TYPE_LABELS[o.type] || o.type;
        var statusLabel = (STATUS_LABELS[o.type] || STATUS_LABELS.equipment)[o.status] || '';
        var catLabel = o.category === 'fitness' ? 'フィットネス' : 'インドアゴルフ';
        rows.push([
          o.id, typeLabel, getShopName(o.shop), catLabel, o.title,
          o.amount != null ? o.amount : '', statusLabel, o.date
        ]);
      });

      // Summary
      rows.push([]);
      rows.push(['合計件数', filtered.length + '件']);
      var totalAmount = 0;
      var amountCount = 0;
      filtered.forEach(function(o) { if (o.amount != null) { totalAmount += o.amount; amountCount++; } });
      if (amountCount > 0) {
        rows.push(['金額合計', totalAmount]);
      }

      downloadCsv(rows, '発注データ.csv');
    }

    // ===== Export: Budget Data =====
    function getFilteredBudget() {
      var zoneVal = document.getElementById('exportBudgetZone').value;
      var areaVal = document.getElementById('exportBudgetArea').value;
      var shopVal = document.getElementById('exportBudgetShop').value;

      return budgetData.filter(function(d) {
        if (zoneVal && d.zone !== zoneVal) return false;
        if (areaVal && d.area !== areaVal) return false;
        if (shopVal && d.shopCode !== shopVal) return false;
        return true;
      });
    }

    function exportBudgetData() {
      var data = getFilteredBudget();
      if (data.length === 0) {
        alert('出力対象のデータがありません。');
        return;
      }

      var yearLabel = getSelectedText('exportBudgetYear');
      var deptVal = document.getElementById('exportBudgetDept').value;

      var rows = [];
      rows.push(['予算管理データ']);
      rows.push([]);
      rows.push(['【ゾーン】', getSelectedText('exportBudgetZone')]);
      rows.push(['【エリア】', getSelectedText('exportBudgetArea')]);
      rows.push(['【店舗】', getSelectedText('exportBudgetShop')]);
      rows.push(['【カテゴリ】', getSelectedText('exportBudgetDept')]);
      rows.push(['【年度】', yearLabel]);
      rows.push([]);

      // Summary table
      rows.push([
        '店舗',
        '当期予算', '当期実績', '当期残高', '当期消化率(%)',
        '期中予算', '期中実績', '期中残高', '期中消化率(%)',
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
      var deptKeys = deptVal ? [departments.find(function(d) { return d.key === deptVal; }) || departments[0]] : departments;
      rows.push([]);
      rows.push(['===== 月別明細 =====']);
      var monthHeaders = ['項目'];
      fiscalMonths.forEach(function(m) { monthHeaders.push(m + '月'); });
      monthHeaders.push('合計');

      data.forEach(function(d) {
        rows.push([]);
        rows.push(['■ ' + d.shop]);
        deptKeys.forEach(function(dept) {
          rows.push(['【' + dept.label + '】']);
          rows.push(monthHeaders);
          var detail = d.details[dept.key];
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

      var year = document.getElementById('exportBudgetYear').value;
      downloadCsv(rows, '予算管理_' + year + '年度.csv');
    }
