// ===== 代替ゴルフクラブ発送依頼 =====
// 店舗情報は common-nav.js が取得する me.php のユーザー情報（shop_phone 等）を表示する。
// 発注登録は type=club-replacement で api/orders/create.php へ POST（カテゴリはサーバ側で golf に強制）。

(function() {
  // ===== Initialize =====
  document.addEventListener('DOMContentLoaded', function() {
    // 日付（今日）
    var today = new Date();
    var ymd = today.getFullYear() + '-' +
              String(today.getMonth() + 1).padStart(2, '0') + '-' +
              String(today.getDate()).padStart(2, '0');
    document.getElementById('todayDate').value = ymd;

    // 店舗情報: common-nav.js の me.php 取得完了イベントで埋める
    if (window.__currentUser) {
      fillShopInfo(window.__currentUser);
    }
    window.addEventListener('userLoaded', function(e) {
      fillShopInfo(e.detail);
    });
  });

  function fillShopInfo(user) {
    if (!user) return;
    // golf を扱わない店舗・店舗以外のロールはメニューへ戻す（URL直打ち対策）
    var cats = (user.categories || []).map(function(c) { return c.code; });
    if (user.role !== 'shop' || cats.indexOf('golf') < 0) {
      window.location.href = 'menu.html';
      return;
    }
    document.getElementById('shopCode').value = user.shop_code || '';
    document.getElementById('shopName').value = user.shop_name || '';
    document.getElementById('shopPhone').value = user.shop_phone || '';
    document.getElementById('shopPostal').value = user.shop_postal_code || '';
    document.getElementById('shopAddress').value = user.shop_address || '';
  }

  // ===== Submit State =====
  window.updateSubmitState = function() {
    var club = document.getElementById('clubSelect').value;
    var shaft = document.getElementById('shaftSelect').value;
    var damage = document.getElementById('damageText').value.trim();
    var btn = document.getElementById('submitBtn');
    var ok = club !== '' && shaft !== '' && damage !== '';
    btn.disabled = !ok;
    btn.title = ok ? '' : '破損クラブ・シャフト・破損状況をすべて入力してください';
  };

  // ===== ビジー制御（repair-order.js と同方式の簡易版） =====
  function beginBusy(btn, label) {
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = label;
    document.body.style.pointerEvents = 'none';
    return function endBusy() {
      btn.textContent = original;
      document.body.style.pointerEvents = '';
    };
  }

  // ===== Submit =====
  window.submitForm = function() {
    var submitBtn = document.getElementById('submitBtn');
    if (submitBtn.disabled) return;

    var endBusy = beginBusy(submitBtn, '送信中...');

    var formData = new FormData();
    formData.append('type', 'club-replacement');
    formData.append('category', 'golf'); // サーバ側でも golf に強制される
    formData.append('club', document.getElementById('clubSelect').value);
    formData.append('shaft', document.getElementById('shaftSelect').value);
    formData.append('damage', document.getElementById('damageText').value.trim());

    fetch('api/orders/create.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
    .then(function(r) {
      if (r.status === 401) { window.location.href = 'login.html'; return null; }
      return r.json();
    })
    .then(function(data) {
      if (!data) return;
      if (data.success) {
        showNotify('success', '発送依頼を送信しました',
          '発注番号: <span class="notify-order-id">' + data.order_id + '</span>');
        resetForm();
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
      updateSubmitState();
    });
  };

  function resetForm() {
    document.getElementById('clubSelect').value = '';
    document.getElementById('shaftSelect').value = '';
    document.getElementById('damageText').value = '';
    updateSubmitState();
  }
})();
