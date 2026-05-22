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
      var remaining = 3 - photos.length;
      var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      for (var i = 0; i < Math.min(files.length, remaining); i++) {
        var file = files[i];
        if (allowed.indexOf(file.type) < 0) continue;
        photos.push({ id: Date.now() + '-' + i, url: URL.createObjectURL(file), file: file });
      }
      renderPhotos();
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
        if (uploadText) uploadText.textContent = 'クリックまたはドラッグ＆ドロップで写真を追加';
        if (uploadSubtext) uploadSubtext.textContent = '残り' + (3 - photos.length) + '枚 追加可能（JPEG / PNG / GIF / WebP）';
      }

      if (!photos.length) { container.innerHTML = ''; return; }
      container.innerHTML = photos.map(function(p) {
        return '<div class="photo-preview"><img src="' + p.url + '" alt="部品写真"><button type="button" class="photo-remove" onclick="removePhoto(\'' + p.id + '\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div>';
      }).join('');
    }

    // ===== ドラッグ＆ドロップ対応 =====
    document.addEventListener('DOMContentLoaded', function() {
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
      submitBtn.disabled = true;
      submitBtn.textContent = '送信中...';

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
        submitBtn.textContent = '部品発注を送信';
        updateSubmitState();
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
