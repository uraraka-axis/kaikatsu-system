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
      var remaining = 3 - photos.length;
      for (var i = 0; i < Math.min(files.length, remaining); i++) {
        photos.push({ id: Date.now() + '-' + i, url: URL.createObjectURL(files[i]), file: files[i] });
      }
      renderPhotos(); e.target.value = '';
    }

    function removePhoto(id) {
      photos = photos.filter(function(p) { if (p.id === id) { URL.revokeObjectURL(p.url); return false; } return true; });
      renderPhotos();
    }

    function renderPhotos() {
      var container = document.getElementById('photoPreviews');
      document.getElementById('uploadArea').style.display = photos.length >= 3 ? 'none' : '';
      if (!photos.length) { container.innerHTML = ''; return; }
      container.innerHTML = photos.map(function(p) {
        return '<div class="photo-preview"><img src="' + p.url + '" alt="部品写真"><button type="button" class="photo-remove" onclick="removePhoto(\'' + p.id + '\')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button></div>';
      }).join('');
    }

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
      document.getElementById('uploadArea').style.display = '';
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
