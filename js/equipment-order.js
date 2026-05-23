    // ===== State =====
    var products = [];
    var cart = {};
    var categoriesMap = {}; // code → { closing_type, closing_day }
    var budgetInfo = {
      budget: 0,
      actual: 0,
      remaining: 0,
      loaded: false,
      category: null,
      quarterLabel: '',  // 例: 'Q1'
      quarterRange: '',  // 例: '4-6月'
    };
    var cartExpanded = false;
    var currentUser = null;
    var budgetFetching = false;

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

    function fetchCategories(callback) {
      fetch('api/master/categories.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success && Array.isArray(data.data)) {
            data.data.forEach(function(c) {
              categoriesMap[c.code] = {
                closing_type: c.closing_type || 'none',
                closing_day: parseInt(c.closing_day, 10) || 0,
              };
            });
          }
          if (callback) callback();
        })
        .catch(function(e) {
          console.error('Failed to fetch categories:', e);
          if (callback) callback();
        });
    }

    // ===== 検索条件保存/復元（同タブ内） =====
    var EQ_FILTER_KEY = 'filters:equipment-order';
    function saveEquipFilters() {
      var catEl = document.getElementById('category');
      var srcEl = document.getElementById('searchInput');
      var state = {};
      if (catEl && !catEl.disabled) state.category = catEl.value;
      if (srcEl) state.search = srcEl.value;
      try { sessionStorage.setItem(EQ_FILTER_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function restoreEquipFilters() {
      var raw;
      try { raw = sessionStorage.getItem(EQ_FILTER_KEY); } catch (e) { return; }
      if (!raw) return;
      var state;
      try { state = JSON.parse(raw); } catch (e) { return; }
      var catEl = document.getElementById('category');
      var srcEl = document.getElementById('searchInput');
      if (catEl && !catEl.disabled && state.category != null) catEl.value = state.category;
      if (srcEl && state.search != null) srcEl.value = state.search;
    }

    // ===== Filter & Render =====
    function filterProducts() {
      saveEquipFilters();
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
        // カート空時は予算アラートも消す（既に超過していた場合に残ってしまうため）
        var budgetAlert = document.getElementById('budgetAlert');
        if (budgetAlert) budgetAlert.classList.remove('visible');
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

      // カテゴリに応じた予算情報を取得（カテゴリ変更時のみ再フェッチ）
      var cartCat = determineCartCategory();
      if (cartCat && (!budgetInfo.loaded || budgetInfo.category !== cartCat)) {
        if (!budgetFetching) {
          fetchBudgetForCategory(cartCat);
        }
      }

      // 予算アラート表示（四半期予算ベース）
      var budgetAlert = document.getElementById('budgetAlert');
      if (budgetInfo.loaded && totalPrice > budgetInfo.remaining) {
        var alertText = document.getElementById('budgetAlertText');
        if (alertText) {
          var over = totalPrice - budgetInfo.remaining;
          var qLabel = budgetInfo.quarterLabel || 'Q';
          var qRange = budgetInfo.quarterRange ? '（' + budgetInfo.quarterRange + '）' : '';
          alertText.innerHTML = '<strong>四半期予算超過の可能性があります。</strong>' +
            ' ' + qLabel + qRange + '予算: ¥' + budgetInfo.budget.toLocaleString() +
            ' / 実績: ¥' + budgetInfo.actual.toLocaleString() +
            ' / 残高: ¥' + budgetInfo.remaining.toLocaleString() +
            ' / 今回発注予定額 ¥' + totalPrice.toLocaleString() +
            '（¥' + over.toLocaleString() + ' 超過）';
        }
        budgetAlert.classList.add('visible');
      } else {
        budgetAlert.classList.remove('visible');
      }
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
      var category = determineCartCategory() || 'fitness';

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
          var bodyHtml = '発注番号: <span class="notify-order-id">' + data.order_id + '</span>';
          if (budgetInfo.loaded) {
            var totalPrice = 0;
            Object.keys(cart).forEach(function(id) {
              var p = products.find(function(x) { return x.id == id; });
              if (p) totalPrice += p.price * cart[id];
            });
            if (totalPrice > budgetInfo.remaining) {
              var over = totalPrice - budgetInfo.remaining;
              var qLabel = budgetInfo.quarterLabel || 'Q';
              var qRange = budgetInfo.quarterRange ? '（' + budgetInfo.quarterRange + '）' : '';
              bodyHtml += '<span class="notify-warning">' +
                '<strong>⚠ 四半期予算超過の可能性があります</strong><br>' +
                qLabel + qRange + '予算: ¥' + budgetInfo.budget.toLocaleString() +
                ' / 実績: ¥' + budgetInfo.actual.toLocaleString() +
                ' / 残高: ¥' + budgetInfo.remaining.toLocaleString() +
                '<br>今回発注予定額 ¥' + totalPrice.toLocaleString() +
                '（¥' + over.toLocaleString() + ' 超過）</span>';
              showNotify('warning', '備品発注を送信しました', bodyHtml);
            } else {
              showNotify('success', '備品発注を送信しました', bodyHtml);
            }
          } else {
            showNotify('success', '備品発注を送信しました', bodyHtml);
          }
          cart = {};
          // カート空 → 予算情報リセット（次回追加時に再フェッチ）
          budgetInfo.loaded = false;
          budgetInfo.category = null;
          filterProducts();
          updateCart();
          // 送信後、現在のカテゴリに応じて再取得（実績反映のため）
          // 現状はカートが空になるためフェッチしない。次回カート操作時に取得される。
        } else {
          showNotify('error', '送信エラー', data.error || '送信に失敗しました');
        }
      })
      .catch(function(e) {
        console.error('Submit error:', e);
        showNotify('error', '通信エラー', 'サーバーとの通信に失敗しました。<br>ネットワーク接続を確認してください。');
      })
      .finally(function() {
        submitBtn.disabled = false;
        submitBtn.textContent = '発注する';
      });
    }

    // ===== Cart category & Budget =====
    function determineCartCategory() {
      var keys = Object.keys(cart);
      if (!keys.length) return null;
      var cats = {};
      keys.forEach(function(id) {
        var p = products.find(function(x) { return x.id == id; });
        if (p) cats[p.category] = true;
      });
      var catKeys = Object.keys(cats);
      if (catKeys.length === 0) return null;
      return catKeys[0]; // カート内が単一カテゴリ前提（複数の場合は最初を使用）
    }

    /**
     * 発注日とカテゴリの締めルールから「計上月」となる Date を返す。
     * - monthly: today.day <= closing_day なら当月、超えたら翌月1日
     * - weekly:  次の closing_day(曜日) の翌日
     * - none:    今日
     */
    function computeSettlementDate(orderDate, closingType, closingDay) {
      var d = new Date(orderDate.getTime());
      if (closingType === 'monthly') {
        var day = d.getDate();
        if (day <= closingDay) {
          return d;
        }
        return new Date(d.getFullYear(), d.getMonth() + 1, 1);
      }
      if (closingType === 'weekly') {
        var currentDow = d.getDay(); // 0=日 .. 6=土
        var daysUntil = ((closingDay - currentDow + 7) % 7) + 1;
        var nd = new Date(d.getTime());
        nd.setDate(d.getDate() + daysUntil);
        return nd;
      }
      return d; // none
    }

    // 2026-05-22: budgets.department migration により dept パラメータは categories.code をそのまま受け入れる
    // 2026-05-23: 月次→四半期予算ベースの判定に変更（Q1=4-6月, Q2=7-9月, Q3=10-12月, Q4=1-3月）
    function getQuarterInfo(month) {
      if (month >= 4 && month <= 6)  return { label: 'Q1', range: '4-6月',  months: [4, 5, 6] };
      if (month >= 7 && month <= 9)  return { label: 'Q2', range: '7-9月',  months: [7, 8, 9] };
      if (month >= 10 && month <= 12) return { label: 'Q3', range: '10-12月', months: [10, 11, 12] };
      return { label: 'Q4', range: '1-3月', months: [1, 2, 3] };
    }

    function fetchBudgetForCategory(category) {
      var closing = categoriesMap[category];
      if (!closing || !category) {
        budgetInfo.loaded = false;
        return;
      }
      var dept = category; // budget API は categories.code を直接受け取る

      var settlement = computeSettlementDate(new Date(), closing.closing_type, closing.closing_day);
      var settlementMonth = settlement.getMonth() + 1;
      var settlementYear  = settlement.getFullYear();
      var fiscalYear      = settlementMonth >= 4 ? settlementYear : settlementYear - 1;
      var quarter         = getQuarterInfo(settlementMonth);

      budgetFetching = true;
      fetch('api/budgets.php?year=' + fiscalYear + '&dept=' + dept, { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) {
            window.location.href = 'login.html';
            return null;
          }
          return r.json();
        })
        .then(function(data) {
          if (!data || !data.success || !data.data || !data.data.length) return;
          var shop = data.data[0];
          // 四半期（3ヶ月）合計の budget / actual を集計
          var qBudget = 0;
          var qActual = 0;
          if (shop.monthly) {
            shop.monthly.forEach(function(m) {
              if (quarter.months.indexOf(m.month) >= 0) {
                qBudget += m.budget || 0;
                qActual += m.actual || 0;
              }
            });
          }
          budgetInfo.budget       = qBudget;
          budgetInfo.actual       = qActual;
          budgetInfo.remaining    = qBudget - qActual;
          budgetInfo.loaded       = true;
          budgetInfo.category     = category;
          budgetInfo.quarterLabel = quarter.label;
          budgetInfo.quarterRange = quarter.range;
          updateCart();
        })
        .catch(function(e) {
          console.error('Budget fetch error:', e);
        })
        .finally(function() {
          budgetFetching = false;
        });
    }

    // ===== Boot =====
    function bootEquipmentOrder(user) {
      if (currentUser) return;
      currentUser = user;

      // 取扱カテゴリでドロップダウンを絞り込み＋単一カテゴリの場合は自動選択＋ロック
      if (Array.isArray(user.categories) && user.categories.length > 0) {
        var catSel = document.getElementById('category');
        if (catSel) {
          var opts = '<option value="">すべて</option>';
          user.categories.forEach(function(c) {
            opts += '<option value="' + c.code + '">' + c.name + '</option>';
          });
          catSel.innerHTML = opts;
          if (user.categories.length === 1) {
            catSel.value = user.categories[0].code;
            catSel.disabled = true;
          }
        }
      }

      fetchCategories(function() {
        fetchProducts(function() {
          restoreEquipFilters();
          filterProducts();
        });
      });
    }

    window.addEventListener('userLoaded', function(e) {
      bootEquipmentOrder(e.detail);
    });

    if (window.__currentUser) {
      bootEquipmentOrder(window.__currentUser);
    }
