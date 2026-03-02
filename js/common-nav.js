(function() {
  var navItems = [
    { href: 'menu.html', label: 'メニュー' },
    { href: 'repair-order.html', label: '修理発注' },
    { href: 'equipment-order.html', label: '備品発注' },
    { href: 'parts-order.html', label: '部品発注' },
    { href: 'order-list.html', label: '発注一覧' },
    { href: 'budget-management.html', label: '予算管理' },
    { href: 'procurement-history.html', label: '自店調達' },
    { href: 'admin-menu.html', label: '管理メニュー' }
  ];

  var nav = document.querySelector('nav.nav');
  if (!nav) return;

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
})();
