    // ===== State =====
    let unavailableSlots = [];
    let selectedDays = [];
    let photos = [];

    // ===== Initialize =====
    document.addEventListener('DOMContentLoaded', function() {
      initDateInput();
      initHourSelects();
      initNavigation();
    });

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

    function initNavigation() {
      document.querySelectorAll('.nav-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          document.querySelectorAll('.nav-btn').forEach(function(b) { b.classList.remove('active'); });
          btn.classList.add('active');
        });
      });
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

      var remaining = 3 - photos.length;
      for (var i = 0; i < Math.min(files.length, remaining); i++) {
        var file = files[i];
        var url = URL.createObjectURL(file);
        photos.push({ id: Date.now() + '-' + i, url: url, file: file });
      }

      renderPhotos();
      e.target.value = '';
    }

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

      if (photos.length >= 3) {
        uploadArea.style.display = 'none';
      } else {
        uploadArea.style.display = '';
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

    // ===== Submit =====
    function submitForm() {
      alert('修理依頼を送信しました');
      document.getElementById('category').value = '';
      document.getElementById('equipmentName').value = '';
      document.getElementById('issueDescription').value = '';
      unavailableSlots = [];
      selectedDays = [];
      photos = [];
      renderSlots();
      renderDayButtons();
      document.getElementById('photoPreviews').innerHTML = '';
      updateSubmitState();
    }
