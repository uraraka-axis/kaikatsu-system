    // Sample product data
    var products = [
      { id: 1, name: 'トレーニングマット', code: 'MAT-001', price: 3500, supplier: 'フィットネスジャパン', category: 'fitness', recommended: true },
      { id: 2, name: 'ダンベル 5kg', code: 'DB-005', price: 2800, supplier: 'フィットネスジャパン', category: 'fitness', recommended: true },
      { id: 3, name: 'バランスボール 65cm', code: 'BB-065', price: 1800, supplier: 'スポーツ用品販売', category: 'fitness', recommended: false },
      { id: 4, name: 'ヨガブロック', code: 'YB-001', price: 1200, supplier: 'フィットネスジャパン', category: 'fitness', recommended: false },
      { id: 5, name: 'ゴルフボール 1ダース', code: 'GB-012', price: 4200, supplier: 'ゴルフサプライ', category: 'golf', recommended: true },
      { id: 6, name: 'ゴルフティー 100本入り', code: 'GT-100', price: 800, supplier: 'ゴルフサプライ', category: 'golf', recommended: false },
      { id: 7, name: 'グローブ Lサイズ', code: 'GL-L01', price: 1500, supplier: 'ゴルフサプライ', category: 'golf', recommended: true },
      { id: 8, name: 'タオル（大）10枚セット', code: 'TW-L10', price: 5600, supplier: 'リネンサービス', category: 'fitness', recommended: true },
      { id: 9, name: '消毒スプレー 500ml', code: 'DS-500', price: 980, supplier: '衛生用品販売', category: 'fitness', recommended: false },
      { id: 10, name: 'スコアカード 100枚', code: 'SC-100', price: 1200, supplier: 'ゴルフサプライ', category: 'golf', recommended: false },
    ];

    var cart = {};
    var monthlyBudget = 50000;
    var cartExpanded = false;

    function filterProducts() {
      var cat = document.getElementById('category').value;
      var search = document.getElementById('searchInput').value.trim().toLowerCase();
      search = search.replace(/[\uff01-\uff5e]/g, function(c) {
        return String.fromCharCode(c.charCodeAt(0) - 0xfee0);
      });

      var filtered = products.filter(function(p) {
        var matchCat = !cat || p.category === cat;
        var pName = p.name.toLowerCase().replace(/[\uff01-\uff5e]/g, function(c) {
          return String.fromCharCode(c.charCodeAt(0) - 0xfee0);
        });
        var matchSearch = !search || pName.indexOf(search) >= 0;
        return matchCat && matchSearch;
      });

      filtered.sort(function(a, b) { return (b.recommended ? 1 : 0) - (a.recommended ? 1 : 0); });
      renderProducts(filtered);
    }

    function renderProducts(list) {
      var grid = document.getElementById('productGrid');
      if (!list.length) {
        grid.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg><p>該当する商品が見つかりません</p></div>';
        return;
      }
      grid.innerHTML = list.map(function(p) {
        var qty = cart[p.id] || 0;
        var isSelected = qty > 0;
        return '<div class="product-card' + (isSelected ? ' selected' : '') + '" id="card-' + p.id + '">' +
          '<div class="product-img"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></div>' +
          (p.recommended ? '<span class="product-badge">推奨品</span>' : '') +
          '<div class="product-name">' + p.name + '</div>' +
          '<div class="product-code">' + p.code + '</div>' +
          '<div class="product-price">¥' + p.price.toLocaleString() + '</div>' +
          '<div class="product-supplier">仕入先: ' + p.supplier + '</div>' +
          '<div class="qty-row">' +
            '<button class="qty-btn" onclick="changeQty(' + p.id + ', -1)">−</button>' +
            '<input type="number" class="qty-input" id="qty-' + p.id + '" value="' + qty + '" min="0" onchange="setQty(' + p.id + ', this.value)">' +
            '<button class="qty-btn" onclick="changeQty(' + p.id + ', 1)">＋</button>' +
          '</div>' +
        '</div>';
      }).join('');
    }

    function changeQty(id, delta) {
      var current = cart[id] || 0;
      var newQty = Math.max(0, current + delta);
      if (newQty === 0) { delete cart[id]; } else { cart[id] = newQty; }
      filterProducts();
      updateCart();
    }

    function setQty(id, val) {
      var qty = Math.max(0, parseInt(val) || 0);
      if (qty === 0) { delete cart[id]; } else { cart[id] = qty; }
      filterProducts();
      updateCart();
    }

    function removeFromCart(id) {
      delete cart[id];
      filterProducts();
      updateCart();
    }

    function updateCart() {
      var keys = Object.keys(cart);
      var bar = document.getElementById('cartBar');

      if (!keys.length) {
        bar.classList.remove('visible', 'expanded');
        cartExpanded = false;
        return;
      }
      bar.classList.add('visible');

      var totalItems = 0;
      var totalPrice = 0;
      var itemsHtml = '';

      keys.forEach(function(id) {
        var p = products.find(function(x) { return x.id == id; });
        var qty = cart[id];
        var subtotal = p.price * qty;
        totalItems += qty;
        totalPrice += subtotal;
        itemsHtml += '<div class="cart-item">' +
          '<div class="cart-item-info">' +
            '<span class="cart-item-name">' + p.name + '</span>' +
            '<span class="cart-item-qty">' + qty + '点 × ¥' + p.price.toLocaleString() + '</span>' +
          '</div>' +
          '<span class="cart-item-price">¥' + subtotal.toLocaleString() + '</span>' +
          '<button class="cart-item-remove" onclick="removeFromCart(' + p.id + ')" title="削除">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
          '</button>' +
        '</div>';
      });

      document.getElementById('cartCount').textContent = totalItems;
      document.getElementById('cartItems').innerHTML = itemsHtml;
      document.getElementById('cartTotal').textContent = '¥' + totalPrice.toLocaleString();

      // Budget check
      var alert = document.getElementById('budgetAlert');
      if (totalPrice > monthlyBudget) { alert.classList.add('visible'); } else { alert.classList.remove('visible'); }
    }

    function toggleCart() {
      var bar = document.getElementById('cartBar');
      cartExpanded = !cartExpanded;
      if (cartExpanded) {
        bar.classList.add('expanded');
      } else {
        bar.classList.remove('expanded');
      }
    }

    function submitOrder() {
      var count = Object.keys(cart).length;
      alert('備品発注を送信しました（' + count + '商品）');
      cart = {};
      filterProducts();
      updateCart();
    }

    // Initial render
    filterProducts();
