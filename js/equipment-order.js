    // ===== State =====
    var products = [];
    var cart = {};
    var monthlyBudget = 50000;
    var cartExpanded = false;
    var currentUser = null;

    // ===== API =====
    function fetchProducts(callback) {
      fetch('api/products.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success) {
            products = data.data.map(function(p) {
              return {
                id: p.id,
                name: p.name,
                code: p.code,
                price: parseInt(p.price, 10),
                supplier: p.supplier || '',
                category: p.category,
                recommended: parseInt(p.recommended, 10) === 1
              };
            });
          }
          if (callback) callback();
        })
        .catch(function(e) {
          console.error('Failed to fetch products:', e);
          if (callback) callback();
        });
    }

    // ===== Filter & Render =====
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
          (p.recommended ? '<span class="product-badge">よく発注される商品</span>' : '') +
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
        if (!p) return;
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

      var budgetAlert = document.getElementById('budgetAlert');
      if (totalPrice > monthlyBudget) { budgetAlert.classList.add('visible'); } else { budgetAlert.classList.remove('visible'); }
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

    // ===== Submit (API) =====
    function submitOrder() {
      var keys = Object.keys(cart);
      if (!keys.length) return;

      // カテゴリを判定（カート内商品のカテゴリ）
      var categories = {};
      keys.forEach(function(id) {
        var p = products.find(function(x) { return x.id == id; });
        if (p) categories[p.category] = true;
      });
      var catKeys = Object.keys(categories);
      var category = catKeys.length === 1 ? catKeys[0] : 'fitness';

      var submitBtn = document.getElementById('submitBtn');
      submitBtn.disabled = true;
      submitBtn.textContent = '送信中...';

      var items = keys.map(function(id) {
        return { product_id: parseInt(id, 10), qty: cart[id] };
      });

      var formData = new FormData();
      formData.append('type', 'equipment');
      formData.append('category', category);
      formData.append('items', JSON.stringify(items));

      fetch('api/orders/create.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          alert('備品発注を送信しました\n発注番号: ' + data.order_id);
          cart = {};
          filterProducts();
          updateCart();
        } else {
          alert('エラー: ' + (data.error || '送信に失敗しました'));
        }
      })
      .catch(function(e) {
        console.error('Submit error:', e);
        alert('通信エラーが発生しました');
      })
      .finally(function() {
        submitBtn.disabled = false;
        submitBtn.textContent = '発注する';
      });
    }

    // ===== Boot =====
    function bootEquipmentOrder(user) {
      if (currentUser) return;
      currentUser = user;
      fetchProducts(function() {
        filterProducts();
      });
    }

    window.addEventListener('userLoaded', function(e) {
      bootEquipmentOrder(e.detail);
    });

    if (window.__currentUser) {
      bootEquipmentOrder(window.__currentUser);
    }
