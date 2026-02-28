    let photos = [];
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
    function submitForm() {
      alert('部品発注を送信しました。\n\n' + JSON.stringify({
        category: document.getElementById('category').value,
        partsName: document.getElementById('partsName').value,
        targetEquipment: document.getElementById('targetEquipment').value,
        quantity: document.getElementById('quantity').value,
        orderReason: document.getElementById('orderReason').value,
        photoCount: photos.length
      }, null, 2));
    }
