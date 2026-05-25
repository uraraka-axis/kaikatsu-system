// ===== Loading オーバーレイ（全画面共通） =====
window.showLoading = function(text) {
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
  overlay.classList.add('visible');
};
window.hideLoading = function() {
  var overlay = document.getElementById('__loadingOverlay');
  if (overlay) overlay.classList.remove('visible');
};

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
  fetch('api/me.php', { credentials: 'same-origin' })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.success) {
        // 未ログイン → ログインページへ
        window.location.href = 'login.html';
        return;
      }

      var user = data.user;
      // admin / system は管理側ナビ、system はさらに監査ログを追加
      var isAdmin = user.role === 'admin' || user.role === 'system';
      var isSystem = user.role === 'system';

      // ヘッダーのユーザー名を設定
      var userSpan = document.querySelector('.header-user');
      if (userSpan) {
        userSpan.textContent = user.name;
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

      var navItems = isSystem ? systemNav : (isAdmin ? adminNav : storeNav);

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
