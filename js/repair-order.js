    // ===== State =====
    let unavailableSlots = [];
    let selectedDays = [];
    let photos = [];
    let currentUser = null;

    // ===== Initialize =====
    document.addEventListener('DOMContentLoaded', function() {
      initDateInput();
      initHourSelects();
      populateCategoryOptions();
    });

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

    function initDateInput() {
      const dateInput = document.getElementById('slotDate');
      dateInput.min = getMinDate();
    }

    function initHourSelects() {
      const startHour = document.getElementById('startHour');
      const endHour = document.getElementById('endHour');
      for (let h = 0; h < 24; h++) {
        const val = String(h).padStart(2, '0');
        startHour.innerHTML += '<option value="' + val + '">' + val + '</option>';
        endHour.innerHTML += '<option value="' + val + '">' + val + '</option>';
      }
    }

    // ===== Calculate min date (3 business days) =====
    function getMinDate() {
      var today = new Date();
      var businessDays = 0;
      var current = new Date(today);

      while (businessDays < 3) {
        current.setDate(current.getDate() + 1);
        var dow = current.getDay();
        if (dow !== 0 && dow !== 6) {
          businessDays++;
        }
      }
      return current.toISOString().split('T')[0];
    }

    // ===== Time Selection Toggle =====
    function toggleTimeSelection() {
      var timeType = document.querySelector('input[name="timeType"]:checked').value;
      var timeSelection = document.getElementById('timeSelection');
      if (timeType === 'timerange') {
        timeSelection.classList.add('visible');
      } else {
        timeSelection.classList.remove('visible');
      }
      updateAddBtnState();
    }

    // ===== Add Button State =====
    function updateAddBtnState() {
      var dateVal = document.getElementById('slotDate').value;
      var timeType = document.querySelector('input[name="timeType"]:checked').value;
      var addBtn = document.getElementById('addSlotBtn');
      var canAdd = false;

      if (dateVal) {
        if (timeType === 'allday') {
          canAdd = true;
        } else {
          var sh = document.getElementById('startHour').value;
          var sm = document.getElementById('startMinute').value;
          var eh = document.getElementById('endHour').value;
          var em = document.getElementById('endMinute').value;
          canAdd = sh !== '' && sm !== '' && eh !== '' && em !== '';
        }
      }

      addBtn.disabled = !canAdd;
    }

    // ===== Add Slot =====
    function addSlot() {
      var dateVal = document.getElementById('slotDate').value;
      var timeType = document.querySelector('input[name="timeType"]:checked').value;

      var slot = {
        id: Date.now().toString(),
        date: dateVal,
        isAllDay: timeType === 'allday'
      };

      if (!slot.isAllDay) {
        var sh = document.getElementById('startHour').value;
        var sm = document.getElementById('startMinute').value;
        var eh = document.getElementById('endHour').value;
        var em = document.getElementById('endMinute').value;
        slot.timeStart = sh + ':' + sm;
        slot.timeEnd = eh + ':' + em;
      }

      unavailableSlots.push(slot);
      unavailableSlots.sort(function(a, b) { return a.date.localeCompare(b.date); });
      renderSlots();
      resetSlotForm();
    }

    function resetSlotForm() {
      document.getElementById('slotDate').value = '';
      document.getElementById('startHour').value = '';
      document.getElementById('startMinute').value = '';
      document.getElementById('endHour').value = '';
      document.getElementById('endMinute').value = '';
      document.querySelector('input[name="timeType"][value="allday"]').checked = true;
      document.getElementById('timeSelection').classList.remove('visible');
      document.getElementById('addSlotBtn').disabled = true;
    }

    function removeSlot(id) {
      unavailableSlots = unavailableSlots.filter(function(s) { return s.id !== id; });
      renderSlots();
    }

    function renderSlots() {
      var container = document.getElementById('slotsList');
      if (unavailableSlots.length === 0) {
        container.innerHTML = '';
        return;
      }
      var html = '';
      unavailableSlots.forEach(function(slot) {
        var dateParts = slot.date.split('-');
        var displayDate = dateParts[0] + '/' + dateParts[1] + '/' + dateParts[2];
        var timeText = slot.isAllDay ? '終日' : slot.timeStart + ' 〜 ' + slot.timeEnd;

        html += '<div class="slot-item">' +
          '<div class="slot-item-info">' +
            '<span class="slot-date">' + displayDate + '</span>' +
            '<span class="slot-time">' + timeText + '</span>' +
          '</div>' +
          '<button type="button" class="slot-remove" onclick="removeSlot(\'' + slot.id + '\')">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
          '</button>' +
        '</div>';
      });
      container.innerHTML = html;
    }

    // ===== Day Toggle =====
    function renderDayButtons() {
      document.querySelectorAll('.day-btn').forEach(function(btn) { btn.classList.remove('selected'); });
    }

    function toggleDay(btn) {
      var day = btn.getAttribute('data-day');
      var idx = selectedDays.indexOf(day);
      if (idx >= 0) {
        selectedDays.splice(idx, 1);
        btn.classList.remove('selected');
      } else {
        selectedDays.push(day);
        btn.classList.add('selected');
      }
    }

    // ===== Photo Upload =====
    function triggerFileInput() {
      if (photos.length >= 3) return;
      document.getElementById('fileInput').click();
    }

    function handlePhotoUpload(e) {
      var files = e.target.files;
      if (!files) return;
      addPhotoFiles(files);
      e.target.value = '';
    }

    function addPhotoFiles(files) {
      var remaining = 3 - photos.length;
      var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      for (var i = 0; i < Math.min(files.length, remaining); i++) {
        var file = files[i];
        if (allowed.indexOf(file.type) < 0) continue;
        var url = URL.createObjectURL(file);
        photos.push({ id: Date.now() + '-' + i, url: url, file: file });
      }
      renderPhotos();
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

    function removePhoto(id) {
      photos = photos.filter(function(p) {
        if (p.id === id) {
          URL.revokeObjectURL(p.url);
          return false;
        }
        return true;
      });
      renderPhotos();
    }

    function renderPhotos() {
      var container = document.getElementById('photoPreviews');
      var uploadArea = document.getElementById('uploadArea');
      var uploadText = document.getElementById('uploadText');
      var uploadSubtext = document.getElementById('uploadSubtext');

      // 3枚到達時は領域を残しつつ無効化（C-9,16: 突然消えるのを防ぐ）
      if (photos.length >= 3) {
        uploadArea.classList.add('disabled');
        if (uploadText) uploadText.textContent = '写真は最大3枚まで';
        if (uploadSubtext) uploadSubtext.textContent = '×ボタンで削除すると追加できます';
      } else {
        uploadArea.classList.remove('disabled');
        if (uploadText) uploadText.textContent = 'クリックまたはドラッグ＆ドロップで写真を追加';
        if (uploadSubtext) uploadSubtext.textContent = '残り' + (3 - photos.length) + '枚 追加可能（JPEG / PNG / GIF / WebP）';
      }

      if (photos.length === 0) {
        container.innerHTML = '';
        return;
      }

      var html = '';
      photos.forEach(function(photo) {
        html += '<div class="photo-preview">' +
          '<img src="' + photo.url + '" alt="故障箇所写真">' +
          '<button type="button" class="photo-remove" onclick="removePhoto(\'' + photo.id + '\')">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
          '</button>' +
        '</div>';
      });
      container.innerHTML = html;
    }

    // ===== Submit State =====
    function updateSubmitState() {
      var category = document.getElementById('category').value;
      var equipment = document.getElementById('equipmentName').value.trim();
      var issue = document.getElementById('issueDescription').value.trim();
      var submitBtn = document.getElementById('submitBtn');

      submitBtn.disabled = !(category && equipment && issue);
    }

    // ===== Submit (API) =====
    function submitForm() {
      var submitBtn = document.getElementById('submitBtn');
      submitBtn.disabled = true;
      submitBtn.textContent = '送信中...';

      var formData = new FormData();
      formData.append('type', 'repair');
      formData.append('category', document.getElementById('category').value);
      formData.append('equipment_name', document.getElementById('equipmentName').value.trim());
      formData.append('issue', document.getElementById('issueDescription').value.trim());
      formData.append('unavail_dates', JSON.stringify(unavailableSlots));
      formData.append('unavail_days', JSON.stringify(selectedDays));

      // 写真を追加
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
          showNotify('success', '修理依頼を送信しました',
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
        submitBtn.textContent = '修理依頼を送信';
        updateSubmitState();
      });
    }

    function resetForm() {
      document.getElementById('category').value = '';
      document.getElementById('equipmentName').value = '';
      document.getElementById('issueDescription').value = '';
      unavailableSlots = [];
      selectedDays = [];
      photos = [];
      renderSlots();
      renderDayButtons();
      document.getElementById('photoPreviews').innerHTML = '';
      renderPhotos(); // uploadArea のテキストを初期状態に戻す
      updateSubmitState();
    }

    // ===== Boot =====
    function bootRepairOrder(user) {
      if (currentUser) return;
      currentUser = user;
    }

    window.addEventListener('userLoaded', function(e) {
      bootRepairOrder(e.detail);
    });

    if (window.__currentUser) {
      bootRepairOrder(window.__currentUser);
    }
