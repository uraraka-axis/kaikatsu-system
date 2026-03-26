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
      var isAdmin = user.role === 'admin';

      // ヘッダーのユーザー名を設定
      var userSpan = document.querySelector('.header-user');
      if (userSpan) {
        userSpan.textContent = user.name;
      }

      // ログアウトボタン
      var logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
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

      var navItems = isAdmin ? adminNav : storeNav;

      if (nav) {
        var activePage = nav.getAttribute('data-active') || '';
        var html = '';
        navItems.forEach(function(item) {
          var isActive = item.href === activePage;
          if (isActive) {
            html += '<button class="nav-btn active">' + item.label + '</button>';
          } else {
            html += '<button class="nav-btn" onclick="location.href=\'' + item.href + '\'">' + item.label + '</button>';
          }
        });
        nav.innerHTML = html;
      }

      // メニュー画面のセクション表示切替をイベントで通知
      window.dispatchEvent(new CustomEvent('userLoaded', { detail: user }));
    })
    .catch(function() {
      // API通信エラー → ログインページへ
      window.location.href = 'login.html';
    });
})();
