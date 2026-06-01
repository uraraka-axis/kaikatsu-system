    let photos = [];
    let currentUser = null;

    function updateSubmitState() {
      var cat = document.getElementById('category').value;
      var name = document.getElementById('partsName').value.trim();
      var qty = document.getElementById('quantity').value;
      var reason = document.getElementById('orderReason').value.trim();
      document.getElementById('submitBtn').disabled = !(cat && name && qty > 0 && reason);
    }

    function triggerFileInput() {
      if (photos.length >= 3) return;
      document.getElementById('fileInput').click();
    }

    function handlePhotoUpload(e) {
      var files = e.target.files; if (!files) return;
      addPhotoFiles(files);
      e.target.value = '';
    }

    function addPhotoFiles(files) {
      var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      var remaining = 3 - photos.length;
      var picked = [];
      for (var i = 0; i < files.length && picked.length < remaining; i++) {
        if (allowed.indexOf(files[i].type) >= 0) picked.push(files[i]);
      }
      // 送信前に縮小（大きい写真がサーバ上限で無言スキップされるのを防ぐ）
      picked.forEach(function(file) {
        downscaleImage(file, 2000, 0.85).then(function(out) {
          if (photos.length >= 3) return; // 並行処理中に上限到達した場合の保険
          photos.push({ id: 'p' + Date.now() + '-' + Math.round(Math.random() * 1e6), url: URL.createObjectURL(out), file: out });
          renderPhotos();
        });
      });
    }

    function removePhoto(id) {
      photos = photos.filter(function(p) { if (p.id === id) { URL.revokeObjectURL(p.url); return false; } return true; });
      renderPhotos();
    }

    function renderPhotos() {
      var container = document.getElementById('photoPreviews');
      var uploadArea = document.getElementById('uploadArea');
      var uploadText = document.getElementById('uploadText');
      var uploadSubtext = document.getElementById('uploadSubtext');

      if (photos.length >= 3) {
        uploadArea.classList.add('disabled');
        if (uploadText) uploadText.textContent = '写真は最大3枚まで';
        if (uploadSubtext) uploadSubtext.textContent = '×ボタンで削除すると追加できます';
      } else {
        uploadArea.classList.remove('disabled');
        if (uploadText) uploadText.textContent = 'タップして写真を選択';
        if (uploadSubtext) uploadSubtext.textContent = '残り' + (3 - photos.length) + '枚 追加可能（JPEG / PNG / GIF / WebP）';
      }

      if (!photos.length) { container.innerHTML = ''; return; }
      container.innerHTML = photos.map(function(p) {
        return '<div class="photo-preview"><img src="' + p.url + '" alt="部品写真"><button type="button" class="photo-remove" onclick="removePhoto(\'' + p.id + '\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div>';
      }).join('');
    }

    // ===== ドラッグ＆ドロップ対応 =====
    function populateCategoryOptions() {
      var sel = document.getElementById('category');
      if (!sel) return;
      fetch('api/master/categories.php', { credentials: 'same-origin' })
        .then(function(r) {
          if (r.status === 401) { window.location.href = 'login.html'; return null; }
          return r.json();
        })
        .then(function(data) {
          if (!data || !data.success || !Array.isArray(data.data)) return;
          while (sel.options.length > 1) sel.remove(1);
          data.data.forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            sel.appendChild(opt);
          });
        })
        .catch(function(e) { console.error('categories fetch error:', e); });
    }

    document.addEventListener('DOMContentLoaded', function() {
      populateCategoryOptions();
      var area = document.getElementById('uploadArea');
      if (!area) return;
      ['dragenter', 'dragover'].forEach(function(ev) {
        area.addEventListener(ev, function(e) {
          e.preventDefault(); e.stopPropagation();
          if (photos.length >= 3) return;
          area.classList.add('dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function(ev) {
        area.addEventListener(ev, function(e) {
          e.preventDefault(); e.stopPropagation();
          area.classList.remove('dragover');
        });
      });
      area.addEventListener('drop', function(e) {
        if (photos.length >= 3) return;
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length > 0) addPhotoFiles(files);
      });
    });

    // ===== Submit (API) =====
    function submitForm() {
      var submitBtn = document.getElementById('submitBtn');
      // 送信中は画面全体のクリックを遮断（多重操作・処理中の画面遷移を防止）
      var endBusy = beginBusy(submitBtn, '送信中...');

      var formData = new FormData();
      formData.append('type', 'parts');
      formData.append('category', document.getElementById('category').value);
      formData.append('parts_name', document.getElementById('partsName').value.trim());
      formData.append('target_equipment', document.getElementById('targetEquipment').value.trim());
      formData.append('quantity', document.getElementById('quantity').value);
      formData.append('reason', document.getElementById('orderReason').value.trim());

      photos.forEach(function(p) {
        formData.append('photos[]', p.file);
      });

      fetch('api/orders/create.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          showNotify('success', '部品発注を送信しました',
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
        endBusy();          // 元のラベルに復元＋クリック遮断解除
        updateSubmitState(); // 入力状態に応じて活性/非活性を再評価
      });
    }

    function resetForm() {
      document.getElementById('category').value = '';
      document.getElementById('partsName').value = '';
      document.getElementById('targetEquipment').value = '';
      document.getElementById('quantity').value = '1';
      document.getElementById('orderReason').value = '';
      photos = [];
      document.getElementById('photoPreviews').innerHTML = '';
      renderPhotos(); // uploadArea のテキストを初期状態に戻す
      updateSubmitState();
    }

    // ===== Boot =====
    function bootPartsOrder(user) {
      if (currentUser) return;
      currentUser = user;
    }

    window.addEventListener('userLoaded', function(e) {
      bootPartsOrder(e.detail);
    });

    if (window.__currentUser) {
      bootPartsOrder(window.__currentUser);
    }
