// ===== Loading オーバーレイ（全画面共通） =====
// 遅延表示: showLoading 後 250ms 以内に hideLoading された高速処理では
// オーバーレイを出さない（白い幕が一瞬光るチラつきを防止）。
(function() {
  var SHOW_DELAY_MS = 250;
  var delayTimer = null;

  function ensureOverlay(text) {
    var overlay = document.getElementById('__loadingOverlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = '__loadingOverlay';
      overlay.className = 'loading-overlay';
      overlay.innerHTML = '<div class="loading-box">' +
                            '<div class="loading-spinner"></div>' +
                            '<div class="loading-text">読み込み中…</div>' +
                          '</div>';
      document.body.appendChild(overlay);
    }
    var txtEl = overlay.querySelector('.loading-text');
    if (txtEl) txtEl.textContent = text || '読み込み中…';
    return overlay;
  }

  window.showLoading = function(text) {
    if (delayTimer) clearTimeout(delayTimer);
    delayTimer = setTimeout(function() {
      ensureOverlay(text).classList.add('visible');
      delayTimer = null;
    }, SHOW_DELAY_MS);
  };
  window.hideLoading = function() {
    if (delayTimer) { clearTimeout(delayTimer); delayTimer = null; }
    var overlay = document.getElementById('__loadingOverlay');
    if (overlay) overlay.classList.remove('visible');
  };
})();

// ===== 画像ダウンスケール（送信前の縮小） =====
// iPad 等の大きい写真（5MB超）はサーバ側上限で無言スキップされていたため、
// 送信前に長辺を maxEdge px まで縮小し JPEG 再エンコードして確実に上限内へ収める。
// jpeg/png/webp のみ対象（gif はアニメ保持のためそのまま）。失敗時は元ファイルを返す。
window.downscaleImage = function(file, maxEdge, quality) {
  maxEdge = maxEdge || 2000;
  quality = quality || 0.85;
  return new Promise(function(resolve) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
    var url = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function() {
      var w = img.naturalWidth, h = img.naturalHeight;
      var longEdge = Math.max(w, h);
      if (!longEdge || longEdge <= maxEdge) { URL.revokeObjectURL(url); resolve(file); return; }
      var scale = maxEdge / longEdge;
      var cw = Math.max(1, Math.round(w * scale));
      var ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw; canvas.height = ch;
      try {
        canvas.getContext('2d').drawImage(img, 0, 0, cw, ch);
      } catch (e) { URL.revokeObjectURL(url); resolve(file); return; }
      URL.revokeObjectURL(url);
      canvas.toBlob(function(blob) {
        if (!blob) { resolve(file); return; }
        var newName = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
        try {
          resolve(new File([blob], newName, { type: 'image/jpeg', lastModified: file.lastModified }));
        } catch (e2) {
          // 一部環境で File コンストラクタ不可 → Blob にプロパティを付与して代替
          blob.name = newName;
          resolve(blob);
        }
      }, 'image/jpeg', quality);
    };
    img.onerror = function() { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
};

// ===== Busy オーバーレイ（送信処理中の多重操作防止） =====
// 送信系処理（発注・申請・保存・アップロード反映等）の実行中、画面全体の
// クリックを即時に遮断し、グロナビ等で別画面へ遷移して処理が中断されるのを防ぐ。
// 併せて起点ボタンを disabled + ラベル変更し、終了時に「元のラベル」へ復元する。
(function() {
  // 検証者案: ほぼ透明のオーバーレイを最前面に出して全クリックを遮断し、
  // 待機カーソル(cursor:wait)のみ表示する（白いフラッシュを出さない）。
  function ensureBusy() {
    var o = document.getElementById('__busyOverlay');
    if (!o) {
      o = document.createElement('div');
      o.id = '__busyOverlay';
      o.className = 'loading-overlay busy'; // 中身なし＝視覚的にはほぼ透明な遮断レイヤー
      document.body.appendChild(o);
    }
    return o;
  }

  // 使い方:
  //   var end = beginBusy(submitBtn, '送信中…');
  //   fetch(...).then(...).catch(...).finally(end);   // 必ず end() を呼ぶ
  // btn は省略可（null）。busyText はボタンの処理中ラベル（オーバーレイには表示しない）。
  window.beginBusy = function(btn, busyText) {
    var prev = null;
    if (btn) {
      prev = { disabled: btn.disabled, text: btn.textContent };
      btn.disabled = true;
      if (busyText) btn.textContent = busyText;
    }
    ensureBusy().classList.add('visible');
    var ended = false;
    return function endBusy() {
      if (ended) return;
      ended = true;
      var o = document.getElementById('__busyOverlay');
      if (o) o.classList.remove('visible');
      if (btn && prev) { btn.disabled = prev.disabled; btn.textContent = prev.text; }
    };
  };
})();

(function() {
  // ログインページではナビを構築しない
  if (window.location.pathname.indexOf('login.html') !== -1) return;

  var header = document.querySelector('header.header');
  var nav = document.querySelector('nav.nav');

  // ヘッダーをローディング状態で仮表示
  if (header) {
    var title = header.getAttribute('data-title') || '';
    header.innerHTML =
      '<h1>' + title + '</h1>' +
      '<div class="header-right">' +
        '<span class="header-user"></span>' +
        '<button class="btn-logout" id="logoutBtn">ログアウト</button>' +
      '</div>';
  }

  // セッションからユーザー情報を取得
  // ?_=timestamp でURLを毎回ユニークにし、ブラウザ/プロキシのキャッシュを完全回避
  //（共有端末で前ユーザーの権限メニューが残る不具合の確実な対策）
  fetch('api/me.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.success) {
        // 未ログイン → ログインページへ
        window.location.href = 'login.html';
        return;
      }

      var user = data.user;
      // ロール判定
      //  - admin / system : 全機能管理ナビ（system は監査ログ追加）
      //  - zone / area    : 閲覧3画面のみのナビ（管轄スコープ付き）
      //  - shop           : 店舗ナビ
      var isAdmin = user.role === 'admin' || user.role === 'system';
      var isSystem = user.role === 'system';
      var isZone = user.role === 'zone';
      var isArea = user.role === 'area';
      var isManager = isAdmin || isZone || isArea;

      // ヘッダーのユーザー名を設定。zone/area で user.name に管轄名が含まれていない場合のみ
      // 「(○○ゾーン)」「(○○エリア)」を補記する。重複表示は避ける。
      var userSpan = document.querySelector('.header-user');
      if (userSpan) {
        var label = user.name;
        if (isZone && user.zone_name && user.name.indexOf(user.zone_name) === -1) {
          label = user.name + '（' + user.zone_name + 'ゾーンマネージャー）';
        } else if (isArea && user.area_name && user.name.indexOf(user.area_name) === -1) {
          label = user.name + '（' + user.area_name + 'エリアマネージャー）';
        }
        userSpan.textContent = label;
      }

      // ログアウトボタン
      var logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
          // ログアウト時、画面別の検索条件・選択状態をクリア
          try {
            sessionStorage.removeItem('filters:order-list');
            sessionStorage.removeItem('filters:equipment-order');
            sessionStorage.removeItem('filters:budget-management');
          } catch (e) {}
          fetch('api/logout.php', {
            method: 'POST',
            credentials: 'same-origin'
          }).then(function() {
            window.location.href = 'login.html';
          }).catch(function() {
            window.location.href = 'login.html';
          });
        });
      }

      // ナビゲーション構築
      var storeNav = [
        { href: 'menu.html', label: 'メニュー' },
        { href: 'repair-order.html', label: '修理発注' },
        { href: 'equipment-order.html', label: '備品発注' },
        { href: 'parts-order.html', label: '部品発注' },
        { href: 'seat-replacement.html', label: 'シート交換' },
        { href: 'order-list.html', label: '発注一覧' },
        { href: 'budget-management.html', label: '予算管理' },
        { href: 'procurement-history.html', label: '自店調達' }
      ];

      var adminNav = [
        { href: 'menu.html', label: 'メニュー' },
        { href: 'order-list.html', label: '発注一覧' },
        { href: 'budget-management.html', label: '予算管理' },
        { href: 'procurement-history.html', label: '自店調達' },
        { href: 'admin-menu.html', label: '管理メニュー' }
      ];

      // system は管理ナビに監査ログを追加
      var systemNav = adminNav.concat([
        { href: 'master-change-log.html', label: '監査ログ' }
      ]);

      // zone / area は閲覧3画面のみ（管理メニュー無し）
      var managerScopedNav = [
        { href: 'menu.html', label: 'メニュー' },
        { href: 'order-list.html', label: '発注一覧' },
        { href: 'budget-management.html', label: '予算管理' },
        { href: 'procurement-history.html', label: '自店調達' }
      ];

      var navItems;
      if (isSystem) navItems = systemNav;
      else if (isAdmin) navItems = adminNav;
      else if (isZone || isArea) navItems = managerScopedNav;
      else navItems = storeNav;

      if (nav) {
        var activePage = nav.getAttribute('data-active') || '';
        var html = '';
        navItems.forEach(function(item) {
          var isActive = item.href === activePage;
          if (isActive) {
            // 現在ページのボタン押下時はページ再読込（再表示要望対応）
            html += '<button class="nav-btn active" onclick="location.reload()">' + item.label + '</button>';
          } else {
            html += '<button class="nav-btn" onclick="location.href=\'' + item.href + '\'">' + item.label + '</button>';
          }
        });
        nav.innerHTML = html;
      }

      // ユーザー情報をグローバルに保存（レース条件対策）
      window.__currentUser = user;

      // メニュー画面のセクション表示切替をイベントで通知
      window.dispatchEvent(new CustomEvent('userLoaded', { detail: user }));

      // 認証チェック完了: コンテンツを表示
      document.documentElement.classList.remove('auth-pending');
    })
    .catch(function() {
      // API通信エラー → ログインページへ（bodyは隠したまま遷移）
      window.location.href = 'login.html';
    });
})();
