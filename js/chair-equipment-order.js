    // ============================================================
    // チェア備品発注（マッサージチェア備品・日本メディック製）
    //
    // 備品発注 (equipment-order.js) のクローン。相違点:
    //  - 商品は api/products.php?chair=1（is_chair_item=1 のみ）
    //  - カテゴリは fitness 固定（カテゴリフィルタなし）
    //  - 月次締めの対象外（都度発注）: 予算の計上四半期は「今日」の属する四半期
    //  - 送信 type は 'chair-equipment'
    // ============================================================

    // ===== State =====
    var products = [];
    var cart = {};
    var budgetInfo = {
      budget: 0,
      actual: 0,       // 確定実績（納品済以上）
      inflight: 0,     // 未納品の発注見込み（status 0/1/2・全種別）
      remaining: 0,    // 予算 − 確定実績 − 未納品見込み（＝仮計上残高）
      loaded: false,
      category: null,
      quarterLabel: '',
      quarterRange: '',
    };
    var cartExpanded = false;
    var currentUser = null;
    var budgetFetching = false;
    var CHAIR_CATEGORY = 'fitness'; // チェア備品はフィットネス予算へ都度計上

    // ===== API =====
    function fetchProducts(callback) {
      fetch('api/products.php?chair=1', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(data) {
          if (data && data.success) {
            products = data.data.map(function(p) {
              return {
                id: p.id,
                name: p.name,
                code: p.code,
                price: parseInt(p.price, 10),
                supplier: p.supplier || '',
                category: p.category,
                recommended: parseInt(p.recommended, 10) === 1,
                image_path: p.image_path || '',
                image_path2: p.image_path2 || '',
                image_path3: p.image_path3 || ''
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

    // ===== 検索条件保存/復元（同タブ内） =====
    var CHAIR_FILTER_KEY = 'filters:chair-equipment-order';
    function saveChairFilters() {
      var srcEl = document.getElementById('searchInput');
      var state = {};
      if (srcEl) state.search = srcEl.value;
      try { sessionStorage.setItem(CHAIR_FILTER_KEY, JSON.stringify(state)); } catch (e) {}
    }
    function restoreChairFilters() {
      var raw;
      try { raw = sessionStorage.getItem(CHAIR_FILTER_KEY); } catch (e) { return; }
      if (!raw) return;
      var state;
      try { state = JSON.parse(raw); } catch (e) { return; }
      var srcEl = document.getElementById('searchInput');
      if (srcEl && state.search != null) srcEl.value = state.search;
    }

    // ===== Filter & Render =====
    function filterProducts() {
      saveChairFilters();
      var search = document.getElementById('searchInput').value.trim().toLowerCase();
      search = search.replace(/[！-～]/g, function(c) {
        return String.fromCharCode(c.charCodeAt(0) - 0xfee0);
      });

      var filtered = products.filter(function(p) {
        var pName = p.name.toLowerCase().replace(/[！-～]/g, function(c) {
          return String.fromCharCode(c.charCodeAt(0) - 0xfee0);
        });
        return !search || pName.indexOf(search) >= 0;
      });

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
        var placeholderSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
        var hasAnyImage = p.image_path || p.image_path2 || p.image_path3;
        var imgHtml;
        if (p.image_path) {
          imgHtml = '<div class="product-img has-image clickable" onclick="openProductLightbox(' + p.id + ')">' +
            '<img src="api/product-image.php?code=' + encodeURIComponent(p.code) + '&slot=0" alt="" loading="lazy" onerror="this.parentNode.classList.remove(\'has-image\')">' +
            placeholderSvg +
            '</div>';
        } else if (hasAnyImage) {
          imgHtml = '<div class="product-img clickable" onclick="openProductLightbox(' + p.id + ')">' + placeholderSvg + '</div>';
        } else {
          imgHtml = '<div class="product-img">' + placeholderSvg + '</div>';
        }
        return '<div class="product-card' + (isSelected ? ' selected' : '') + '" id="card-' + p.id + '">' +
          imgHtml +
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

    function updateCardState(id) {
      var qty = cart[id] || 0;
      var card = document.getElementById('card-' + id);
      if (card) card.classList.toggle('selected', qty > 0);
      var input = document.getElementById('qty-' + id);
      if (input && String(input.value) !== String(qty)) input.value = qty;
    }

    function changeQty(id, delta) {
      var current = cart[id] || 0;
      var newQty = Math.max(0, current + delta);
      if (newQty === 0) { delete cart[id]; } else { cart[id] = newQty; }
      updateCardState(id);
      updateCart();
    }

    function setQty(id, val) {
      var qty = Math.max(0, parseInt(val) || 0);
      if (qty === 0) { delete cart[id]; } else { cart[id] = qty; }
      updateCardState(id);
      updateCart();
    }

    function removeFromCart(id) {
      delete cart[id];
      updateCardState(id);
      updateCart();
    }

    function updateCart() {
      var keys = Object.keys(cart);
      var bar = document.getElementById('cartBar');

      if (!keys.length) {
        bar.classList.remove('visible', 'expanded');
        cartExpanded = false;
        var budgetAlertEmpty = document.getElementById('budgetAlert');
        if (budgetAlertEmpty) budgetAlertEmpty.classList.remove('visible');
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

      // フィットネス予算を取得（初回のみ）
      if (!budgetInfo.loaded && !budgetFetching) {
        fetchBudgetForCategory(CHAIR_CATEGORY);
      }

      // 予算アラート表示（四半期予算ベース）
      var budgetAlert = document.getElementById('budgetAlert');
      var overBudget = budgetInfo.loaded && totalPrice > budgetInfo.remaining;
      if (overBudget) {
        var alertText = document.getElementById('budgetAlertText');
        if (alertText) {
          var over = totalPrice - budgetInfo.remaining;
          var qLabel = budgetInfo.quarterLabel || 'Q';
          var qRange = budgetInfo.quarterRange ? '（' + budgetInfo.quarterRange + '）' : '';
          alertText.innerHTML = '<strong>四半期予算超過の可能性があります。</strong>' +
            ' ' + qLabel + qRange + '予算: ¥' + budgetInfo.budget.toLocaleString() +
            ' / 実績: ¥' + budgetInfo.actual.toLocaleString() +
            ' / 発注見込み: ¥' + budgetInfo.inflight.toLocaleString() +
            ' / 残高: ¥' + budgetInfo.remaining.toLocaleString() +
            ' / 今回発注予定額 ¥' + totalPrice.toLocaleString() +
            '（¥' + over.toLocaleString() + ' 超過）';
        }
        budgetAlert.classList.add('visible');
      } else {
        budgetAlert.classList.remove('visible');
      }

      bar.classList.toggle('over-budget', overBudget);
      var cartWarn = document.getElementById('cartBudgetWarning');
      if (cartWarn) cartWarn.style.display = overBudget ? '' : 'none';
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

    // ===== Product Image Lightbox (carousel) =====
    var lightboxCurrentIndex = 0;
    var lightboxImageCount = 0;
    var lightboxScrollHandler = null;

    window.openProductLightbox = function(productId) {
      var p = products.find(function(x) { return x.id === productId; });
      if (!p) return;
      var slots = [];
      if (p.image_path)  slots.push({ slot: 0, file: p.image_path });
      if (p.image_path2) slots.push({ slot: 1, file: p.image_path2 });
      if (p.image_path3) slots.push({ slot: 2, file: p.image_path3 });
      if (slots.length === 0) return;

      var lightbox = document.getElementById('productLightbox');
      var track    = document.getElementById('lightboxTrack');
      var dots     = document.getElementById('lightboxDots');
      var captionEl = document.getElementById('lightboxCaption');
      var prevBtn  = lightbox.querySelector('.lightbox-prev');
      var nextBtn  = lightbox.querySelector('.lightbox-next');

      track.innerHTML = '';
      dots.innerHTML  = '';

      slots.forEach(function(s, i) {
        var slide = document.createElement('div');
        slide.className = 'lightbox-slide';
        var img = document.createElement('img');
        img.src = 'api/product-image.php?code=' + encodeURIComponent(p.code) + '&slot=' + s.slot;
        img.alt = '';
        img.onerror = function() {
          var ph = document.createElement('div');
          ph.className = 'lightbox-no-image';
          ph.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
            '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>' +
            '<circle cx="8.5" cy="8.5" r="1.5"></circle>' +
            '<polyline points="21 15 16 10 5 21"></polyline>' +
            '</svg>' +
            '<div class="lightbox-no-image-text">画像が見つかりません</div>' +
            '<div class="lightbox-no-image-file"></div>';
          ph.querySelector('.lightbox-no-image-file').textContent = s.file;
          slide.replaceChild(ph, img);
        };
        slide.appendChild(img);
        track.appendChild(slide);

        var dot = document.createElement('span');
        dot.className = 'lightbox-dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('data-index', String(i));
        dot.addEventListener('click', function() { lightboxGoto(i); });
        dots.appendChild(dot);
      });

      lightboxImageCount  = slots.length;
      lightboxCurrentIndex = 0;
      captionEl.textContent = p.name;

      var multi = lightboxImageCount > 1;
      prevBtn.style.display = multi ? '' : 'none';
      nextBtn.style.display = multi ? '' : 'none';
      dots.style.display    = multi ? '' : 'none';

      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';

      var prevBehavior = track.style.scrollBehavior;
      track.style.scrollBehavior = 'auto';
      track.scrollLeft = 0;
      requestAnimationFrame(function() {
        track.style.scrollBehavior = prevBehavior;
      });

      if (lightboxScrollHandler) track.removeEventListener('scroll', lightboxScrollHandler);
      lightboxScrollHandler = debounce(function() {
        var w = track.clientWidth;
        if (w <= 0) return;
        var idx = Math.round(track.scrollLeft / w);
        if (idx !== lightboxCurrentIndex) updateLightboxDots(idx);
      }, 80);
      track.addEventListener('scroll', lightboxScrollHandler);
    };

    window.closeProductLightbox = function() {
      var lightbox = document.getElementById('productLightbox');
      if (!lightbox) return;
      lightbox.hidden = true;
      document.body.style.overflow = '';
    };

    function lightboxGoto(idx) {
      var track = document.getElementById('lightboxTrack');
      var slide = track.children[idx];
      if (!slide) return;
      track.scrollTo({ left: slide.offsetLeft, behavior: 'smooth' });
      updateLightboxDots(idx);
    }

    window.lightboxPrev = function() {
      lightboxGoto(Math.max(0, lightboxCurrentIndex - 1));
    };

    window.lightboxNext = function() {
      lightboxGoto(Math.min(lightboxImageCount - 1, lightboxCurrentIndex + 1));
    };

    function updateLightboxDots(idx) {
      lightboxCurrentIndex = idx;
      var dots = document.getElementById('lightboxDots').children;
      for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('active', i === idx);
      }
    }

    function debounce(fn, ms) {
      var t;
      return function() {
        var ctx = this, args = arguments;
        clearTimeout(t);
        t = setTimeout(function() { fn.apply(ctx, args); }, ms);
      };
    }

    document.addEventListener('keydown', function(e) {
      var lightbox = document.getElementById('productLightbox');
      if (!lightbox || lightbox.hidden) return;
      if (e.key === 'Escape')    { e.preventDefault(); window.closeProductLightbox(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); window.lightboxPrev(); }
      if (e.key === 'ArrowRight'){ e.preventDefault(); window.lightboxNext(); }
    });

    document.addEventListener('DOMContentLoaded', function() {
      var lightbox = document.getElementById('productLightbox');
      if (lightbox) {
        lightbox.addEventListener('click', function(e) {
          if (e.target === lightbox) window.closeProductLightbox();
        });
      }
    });

    // ===== Submit (API) =====
    function submitOrder() {
      var keys = Object.keys(cart);
      if (!keys.length) return;

      var submitBtn = document.getElementById('submitBtn');
      var endBusy = beginBusy(submitBtn, '送信中...');

      var items = keys.map(function(id) {
        return { product_id: parseInt(id, 10), qty: cart[id] };
      });

      var formData = new FormData();
      formData.append('type', 'chair-equipment');
      formData.append('category', CHAIR_CATEGORY); // サーバ側でも fitness に強制される
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
                ' / 発注見込み: ¥' + budgetInfo.inflight.toLocaleString() +
                ' / 残高: ¥' + budgetInfo.remaining.toLocaleString() +
                '<br>今回発注予定額 ¥' + totalPrice.toLocaleString() +
                '（¥' + over.toLocaleString() + ' 超過）</span>';
              showNotify('warning', 'チェア備品発注を送信しました', bodyHtml);
            } else {
              showNotify('success', 'チェア備品発注を送信しました', bodyHtml);
            }
          } else {
            showNotify('success', 'チェア備品発注を送信しました', bodyHtml);
          }
          cart = {};
          budgetInfo.loaded = false;
          budgetInfo.category = null;
          filterProducts();
          updateCart();
        } else {
          showNotify('error', '送信エラー', data.error || '送信に失敗しました');
        }
      })
      .catch(function(e) {
        console.error('Submit error:', e);
        showNotify('error', '通信エラー', 'サーバーとの通信に失敗しました。<br>ネットワーク接続を確認してください。');
      })
      .finally(function() {
        endBusy();
      });
    }

    // ===== Budget =====
    // チェア備品は月次締めの対象外（都度発注）のため、計上四半期は常に「今日」の属する四半期。
    function getQuarterInfo(month) {
      if (month >= 4 && month <= 6)  return { label: 'Q1', range: '4-6月',  months: [4, 5, 6] };
      if (month >= 7 && month <= 9)  return { label: 'Q2', range: '7-9月',  months: [7, 8, 9] };
      if (month >= 10 && month <= 12) return { label: 'Q3', range: '10-12月', months: [10, 11, 12] };
      return { label: 'Q4', range: '1-3月', months: [1, 2, 3] };
    }

    function fetchBudgetForCategory(category) {
      var today = new Date();
      var month = today.getMonth() + 1;
      var year  = today.getFullYear();
      var fiscalYear = month >= 4 ? year : year - 1;
      var quarter = getQuarterInfo(month);

      budgetFetching = true;
      fetch('api/budgets.php?year=' + fiscalYear + '&dept=' + category, { credentials: 'same-origin' })
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
          budgetInfo.inflight     = 0;
          budgetInfo.remaining    = qBudget - qActual;
          budgetInfo.loaded       = true;
          budgetInfo.category     = category;
          budgetInfo.quarterLabel = quarter.label;
          budgetInfo.quarterRange = quarter.range;
          updateCart();

          fetch('api/budgets.php?action=inflight&dept=' + encodeURIComponent(category) +
                '&year=' + fiscalYear + '&month=' + month, { credentials: 'same-origin' })
            .then(function(r) {
              if (r.status === 401) { window.location.href = 'login.html'; return null; }
              return r.json();
            })
            .then(function(inf) {
              if (!inf || !inf.success) return;
              if (budgetInfo.category !== category) return;
              budgetInfo.inflight  = inf.inflight || 0;
              budgetInfo.remaining = budgetInfo.budget - budgetInfo.actual - budgetInfo.inflight;
              updateCart();
            })
            .catch(function(e) {
              console.error('Inflight fetch error:', e);
            });
        })
        .catch(function(e) {
          console.error('Budget fetch error:', e);
        })
        .finally(function() {
          budgetFetching = false;
        });
    }

    // ===== 商品名検索のIME対応バインド =====
    function bindSearchInput() {
      var el = document.getElementById('searchInput');
      if (!el || el.dataset.bound) return;
      el.dataset.bound = '1';
      var composing = false;
      el.addEventListener('compositionstart', function() { composing = true; });
      el.addEventListener('compositionend', function() { composing = false; filterProducts(); });
      el.addEventListener('input', function(e) {
        if (composing || e.isComposing) return;
        filterProducts();
      });
    }

    // ===== Boot =====
    function bootChairEquipmentOrder(user) {
      if (currentUser) return;
      currentUser = user;

      bindSearchInput();

      fetchProducts(function() {
        restoreChairFilters();
        filterProducts();
      });
    }

    window.addEventListener('userLoaded', function(e) {
      bootChairEquipmentOrder(e.detail);
    });

    if (window.__currentUser) {
      bootChairEquipmentOrder(window.__currentUser);
    }
