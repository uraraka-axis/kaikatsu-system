    // ===== Master Data (zones/areas/shops are loaded from API on DOMContentLoaded) =====
    // 旧来の参照箇所と互換を保つため field 名は { code, name, zone, area } に正規化する
    var zones = [];
    var areas = [];
    var shopList = [];

    // ゾーン/エリア/店舗マスタを API から取得して各プルダウンを構築する
    function loadExportFilterMasters() {
      var p1 = fetch('api/master/zones.php', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(json) {
          if (!json || !json.success || !Array.isArray(json.data)) return;
          zones = json.data.map(function(z) {
            return { code: z.zone_code, name: z.zone_name };
          });
        })
        .catch(function(e) { console.error('zones fetch error:', e); });

      var p2 = fetch('api/master/areas.php', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(json) {
          if (!json || !json.success || !Array.isArray(json.data)) return;
          areas = json.data.map(function(a) {
            return { code: a.area_code, name: a.area_name, zone: a.zone_code };
          });
        })
        .catch(function(e) { console.error('areas fetch error:', e); });

      var p3 = fetch('api/master/shops.php', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(json) {
          if (!json || !json.success || !Array.isArray(json.data)) return;
          shopList = json.data.map(function(s) {
            return { code: s.shop_code, name: s.shop_name, area: s.area_code };
          });
        })
        .catch(function(e) { console.error('shops fetch error:', e); });

      // カテゴリも動的取得 (admin なので全カテゴリが返る)
      var p4 = fetch('api/master/categories.php', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(json) {
          if (!json || !json.success || !Array.isArray(json.data)) return [];
          return json.data;
        })
        .catch(function(e) { console.error('categories fetch error:', e); return []; });

      Promise.all([p1, p2, p3, p4]).then(function(results) {
        // Order/Budget 両方のゾーンプルダウンを初期化
        var zoneHtml = '<option value="">すべて</option>' +
          zones.map(function(z) {
            return '<option value="' + z.code + '">' + z.code + ':' + z.name + '</option>';
          }).join('');
        var orderZone = document.getElementById('exportOrderZone');
        if (orderZone) orderZone.innerHTML = zoneHtml;
        var budgetZone = document.getElementById('exportBudgetZone');
        if (budgetZone) budgetZone.innerHTML = zoneHtml;

        // エリア/店舗は「すべて」初期状態でフル一覧を出す
        var orderArea = document.getElementById('exportOrderArea');
        if (orderArea) orderArea.innerHTML = buildAreaOptions('');
        var orderShop = document.getElementById('exportOrderShop');
        if (orderShop) orderShop.innerHTML = buildShopOptions(getFilteredShops('', ''));
        var budgetArea = document.getElementById('exportBudgetArea');
        if (budgetArea) budgetArea.innerHTML = buildAreaOptions('');
        var budgetShop = document.getElementById('exportBudgetShop');
        if (budgetShop) budgetShop.innerHTML = buildShopOptions(getFilteredShops('', ''));

        // カテゴリプルダウン
        var cats = results[3] || [];
        var budgetDept = document.getElementById('exportBudgetDept');
        if (budgetDept) {
          while (budgetDept.options.length > 1) budgetDept.remove(1);
          cats.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            budgetDept.appendChild(opt);
          });
        }
      });
    }

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
    // 2026-05-22 schema migration: ENUM 'fit'/'ig' → VARCHAR 'fitness'/'golf'（categories.code 直接保存）
    var departments = [
      { key: 'all',     label: '全体' },
      { key: 'fitness', label: 'フィットネス' },
      { key: 'golf',    label: 'インドアゴルフ' }
    ];

    var budgetData = [
      {
        shop: '10101:札幌', zone: '100', area: '101', shopCode: '10101',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fitness: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          golf:    [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10102:函館', zone: '100', area: '101', shopCode: '10102',
        period: [130000, 1700, 128300, 1.3], midterm: [85000, 1700, 83300, 2.0], month: [10000, 1700, 8300, 17.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,20000],[11028,0],[11030,0]],
          fitness: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,15000],[6500,0],[6500,0]],
          golf:    [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,5000],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10103:旭川', zone: '100', area: '101', shopCode: '10103',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fitness: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          golf:    [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10201:弘前', zone: '100', area: '102', shopCode: '10201',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fitness: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          golf:    [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      },
      {
        shop: '10202:盛岡', zone: '100', area: '102', shopCode: '10202',
        period: [130000, 0, 130000, 0.0], midterm: [85000, 0, 85000, 0.0], month: [10000, 0, 10000, 0.0],
        details: {
          all: [[11008,0],[11010,0],[11012,0],[11014,0],[11016,0],[11018,0],[11020,0],[11022,0],[11024,0],[11026,0],[11028,0],[11030,0]],
          fitness: [[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0],[6500,0]],
          golf:    [[4508,0],[4510,0],[4512,0],[4514,0],[4516,0],[4518,0],[4520,0],[4522,0],[4524,0],[4526,0],[4528,0],[4530,0]]
        }
      }
    ];

    // ===== Master Upload / Download =====
    // 実装済みマスタ（バックエンドAPI存在）
    var IMPLEMENTED_MASTERS = ['zone', 'area', 'shop', 'supplier', 'user', 'budget', 'product']; // 実装が進むごとに追記

    // 各マスタの API パス
    function masterApiPath(type, kind) {
      // type: zone/area/shop/user/supplier/product
      // kind: 'upload' or 'download'
      var apiTypeMap = {
        zone: 'zones', area: 'areas', shop: 'shops',
        user: 'users', supplier: 'suppliers', product: 'products',
        budget: 'budgets'
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
      var extraDiff = data.extra_diff || null;
      var extraInsert = summary.extra_insert || 0;
      var extraDelete = summary.extra_delete || 0;
      var scheduled = data.scheduled || [];          // 予約反映行（apply_date 翌日以降）
      var scheduledCount = summary.scheduled || scheduled.length || 0;
      var conflicting = data.conflicting || [];      // 上書きされる既存pending予約
      var overwritesCount = summary.scheduled_overwrites || conflicting.length || 0;

      var html = '';
      if (topMessage) {
        html += '<div class="master-error-msg">' + escapeHtml(topMessage) + '</div>';
      }
      html += '<div class="master-summary">';
      html += '<span class="master-sum-item master-sum-insert">追加 ' + summary.insert + '件</span>';
      html += '<span class="master-sum-item master-sum-update">変更 ' + summary.update + '件</span>';
      html += '<span class="master-sum-item master-sum-delete">削除 ' + summary.delete + '件</span>';
      if (extraInsert > 0 || extraDelete > 0) {
        html += '<span class="master-sum-item master-sum-insert">カテゴリ追加 ' + extraInsert + '件</span>';
        html += '<span class="master-sum-item master-sum-delete">カテゴリ削除 ' + extraDelete + '件</span>';
      }
      if (scheduledCount > 0) {
        html += '<span class="master-sum-item master-sum-scheduled">予約 ' + scheduledCount + '件</span>';
      }
      if (overwritesCount > 0) {
        html += '<span class="master-sum-item master-sum-overwrite">上書き ' + overwritesCount + '件</span>';
      }
      if (warnings.length > 0) {
        html += '<span class="master-sum-item master-sum-warn">警告 ' + warnings.length + '件</span>';
      }
      html += '</div>';

      if (summary.total === 0 && scheduledCount === 0 && warnings.length === 0) {
        html += '<div class="master-empty-msg">変更内容はありません（現在のDBと一致しています）</div>';
      }

      // 中間テーブル（shop_categories 等）の差分一覧
      if (extraDiff && (extraInsert > 0 || extraDelete > 0)) {
        html += '<div class="master-section master-section-insert">';
        html += '<div class="master-section-title">店舗カテゴリの変更</div>';
        html += '<ul class="master-warning-list">';
        (extraDiff.insert || []).forEach(function(e) {
          html += '<li>追加: ' + escapeHtml(e.shop_code) + ' → ' + escapeHtml(e.category_code) + '</li>';
        });
        (extraDiff.delete || []).forEach(function(e) {
          html += '<li>削除: ' + escapeHtml(e.shop_code) + ' → ' + escapeHtml(e.category_code) + '</li>';
        });
        html += '</ul></div>';
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

      // 既存予約の上書き警告（同一レコードに対する pending が既にある場合）
      if (conflicting.length > 0) {
        html += '<div class="master-section master-section-overwrite">';
        html += '<div class="master-section-title">⚠ 既存の予約 ' + conflicting.length + '件を上書きします（既存予約は cancelled になります）</div>';
        html += '<ul class="master-warning-list">';
        conflicting.forEach(function(c) {
          var dateOnly = (c.scheduled_at || '').substring(0, 10);
          var changeSummary = '';
          if (c.operation === 'update' && Array.isArray(c.changed_fields) && c.before && c.after) {
            changeSummary = c.changed_fields.map(function(f) {
              return f + ': ' + escapeHtml(String(c.before[f])) + ' → ' + escapeHtml(String(c.after[f]));
            }).join(' / ');
          } else if (c.operation === 'insert' && c.after) {
            changeSummary = '新規追加: ' + escapeHtml(makeRowLabel(type, c.after));
          }
          html += '<li>';
          html += '<div class="master-diff-label">' +
                  '<span class="master-scheduled-date">' + escapeHtml(dateOnly) + '</span> ' +
                  '[既存予約] ' + escapeHtml(String(c.record_key)) +
                  '</div>';
          if (changeSummary) html += '<div class="master-diff-detail">' + changeSummary + '</div>';
          html += '</li>';
        });
        html += '</ul></div>';
      }

      // 予約反映分（apply_date 翌日以降）
      if (scheduled.length > 0) {
        html += '<div class="master-section master-section-scheduled">';
        html += '<div class="master-section-title">📅 予約反映 (' + scheduled.length + '件)</div>';
        html += '<ul class="master-diff-list">';
        scheduled.forEach(function(s) {
          var label = makeRowLabel(type, s.after);
          var opLabel = s.operation === 'insert' ? '追加' : (s.operation === 'update' ? '変更' : s.operation);
          var detail = '';
          if (s.operation === 'update' && s.before && Array.isArray(s.changed_fields)) {
            detail = s.changed_fields.map(function(f) {
              return f + ': ' + escapeHtml(String(s.before[f])) + ' → ' + escapeHtml(String(s.after[f]));
            }).join(' / ');
          }
          html += '<li>';
          html += '<div class="master-diff-label">' +
                  '<span class="master-scheduled-date">' + escapeHtml(s.apply_date) + '</span> ' +
                  '[' + opLabel + '] ' + escapeHtml(label) +
                  '</div>';
          if (detail) html += '<div class="master-diff-detail">' + detail + '</div>';
          html += '</li>';
        });
        html += '</ul></div>';
      }

      bodyEl.innerHTML = html;

      // フッター: 確定可否
      //   即時反映 or 予約反映どちらかが1件でもあって警告なしなら確定可能
      var canApply = (summary.total > 0 || scheduledCount > 0) && warnings.length === 0;
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
      if (type === 'budget') {
        // 予算: 年度 / 店舗コード / 部門 / 月 = 額
        return (row.fiscal_year || '') + '年度 / 店舗' + (row.shop_code || '') + ' / ' + (row.department || '') + ' / ' + (row.month || '') + '月 = ' + (row.budget_amount || 0).toLocaleString() + '円';
      }
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
            var scheduledInserted = r.json.data.scheduled_inserted || 0;
            var scheduledCancelled = r.json.data.scheduled_cancelled || 0;
            var msg = getMasterLabel(type) + 'を更新しました\n';
            msg += '即時反映 — 追加: ' + s.insert + '件 / 変更: ' + s.update + '件 / 削除: ' + s.delete + '件';
            if (scheduledInserted > 0) {
              msg += '\n予約登録: ' + scheduledInserted + '件';
              if (scheduledCancelled > 0) {
                msg += '（既存予約 ' + scheduledCancelled + '件を上書き）';
              }
            }
            alert(msg);
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

    // ===== 年度ドロップダウン動的取得 =====
    function populateBudgetYearDropdown() {
      var sel = document.getElementById('exportBudgetYear');
      if (!sel) return;
      fetch('api/budgets.php?action=years', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(json) {
          if (!json || !json.success || !Array.isArray(json.data)) return;
          var years = json.data;
          if (years.length === 0) {
            sel.innerHTML = '<option value="">（年度データなし）</option>';
            return;
          }
          sel.innerHTML = '<option value="">すべて</option>' + years.map(function(y) {
            return '<option value="' + y + '">' + y + '年度</option>';
          }).join('');
        })
        .catch(function(e) { console.error('year fetch error:', e); });
    }

    // ===== D&D 対応（upload-area へのドロップ） =====
    document.addEventListener('DOMContentLoaded', function() {
      populateBudgetYearDropdown();
      loadExportFilterMasters();

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

    // 発注データExcel出力（サーバー側 xlsx）
    function exportOrderData() {
      var params = [];
      var zone = document.getElementById('exportOrderZone').value;
      var area = document.getElementById('exportOrderArea').value;
      var shop = document.getElementById('exportOrderShop').value;
      var dateFrom = document.getElementById('exportDateFrom').value;
      var dateTo = document.getElementById('exportDateTo').value;
      var type = document.getElementById('exportType').value;
      var status = document.getElementById('exportStatus').value;
      if (zone)     params.push('zone='      + encodeURIComponent(zone));
      if (area)     params.push('area='      + encodeURIComponent(area));
      if (shop)     params.push('shop='      + encodeURIComponent(shop));
      if (dateFrom) params.push('date_from=' + encodeURIComponent(dateFrom));
      if (dateTo)   params.push('date_to='   + encodeURIComponent(dateTo));
      if (type)     params.push('type='      + encodeURIComponent(type));
      if (status)   params.push('status='    + encodeURIComponent(status));
      var url = 'api/export/orders.php' + (params.length ? '?' + params.join('&') : '');
      window.location.href = url;
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

    // 予算データExcel出力（サーバー側 xlsx）
    //   year='' (すべて) のときは全年度をまとめて出力
    //   dept='' (すべて) のときは全カテゴリブレークダウン
    function exportBudgetData() {
      var year = document.getElementById('exportBudgetYear').value; // '' or 'YYYY'
      var zone = document.getElementById('exportBudgetZone').value;
      var area = document.getElementById('exportBudgetArea').value;
      var shop = document.getElementById('exportBudgetShop').value;
      var dept = document.getElementById('exportBudgetDept').value || 'all';
      var params = ['dept=' + encodeURIComponent(dept)];
      if (year) params.push('year=' + encodeURIComponent(year));
      if (zone) params.push('zone=' + encodeURIComponent(zone));
      if (area) params.push('area=' + encodeURIComponent(area));
      if (shop) params.push('shop=' + encodeURIComponent(shop));
      window.location.href = 'api/export/budgets.php?' + params.join('&');
    }
