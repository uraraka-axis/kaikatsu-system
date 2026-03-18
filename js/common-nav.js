(function() {
  var params = new URLSearchParams(window.location.search);
  var isAdmin = params.get('role') === 'admin';
  var userName = isAdmin ? '商品部' : '新宿東口店';

  // ===== Header =====
  var header = document.querySelector('header.header');
  if (header) {
    var title = header.getAttribute('data-title') || '';
    header.innerHTML =
      '<h1>' + title + '</h1>' +
      '<div class="header-right">' +
        '<span class="header-user">' + userName + '</span>' +
        '<button class="btn-logout" onclick="location.href=\'login.html\'">ログアウト</button>' +
      '</div>';
  }

  // ===== Navigation =====
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
    { href: 'procurement-history.html', label: '自店調達管理' },
    { href: 'admin-menu.html', label: '管理メニュー' }
  ];

  var navItems = isAdmin ? adminNav : storeNav;
  var roleParam = isAdmin ? '?role=admin' : '';

  var nav = document.querySelector('nav.nav');
  if (!nav) return;

  var activePage = nav.getAttribute('data-active') || '';
  var html = '';
  navItems.forEach(function(item) {
    var isActive = item.href === activePage;
    if (isActive) {
      html += '<button class="nav-btn active">' + item.label + '</button>';
    } else {
      html += '<button class="nav-btn" onclick="location.href=\'' + item.href + roleParam + '\'">' + item.label + '</button>';
    }
  });
  nav.innerHTML = html;
})();
