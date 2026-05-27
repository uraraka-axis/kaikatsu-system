/**
 * 共通通知モーダル
 * showNotify(type, title, message, options)
 *   type: 'success' | 'error' | 'warning'
 *   title: モーダルタイトル
 *   message: 本文HTML
 *   options: { onClose: function, buttonLabel: string }
 */
(function() {
  'use strict';

  var overlay = null;
  var onCloseCallback = null;

  function ensureDOM() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'notify-overlay';
    overlay.id = 'notifyOverlay';
    overlay.innerHTML =
      '<div class="notify-dialog" onclick="event.stopPropagation()">' +
        '<div class="notify-title" id="notifyTitle"></div>' +
        '<div class="notify-body" id="notifyBody"></div>' +
        '<div class="notify-footer">' +
          '<button class="btn-notify" id="notifyBtn" onclick="closeNotify()">OK</button>' +
        '</div>' +
      '</div>';
    overlay.addEventListener('click', function() { closeNotify(); });
    document.body.appendChild(overlay);
  }

  window.showNotify = function(type, title, message, options) {
    ensureDOM();
    options = options || {};
    onCloseCallback = options.onClose || null;

    var dialog = overlay.querySelector('.notify-dialog');
    dialog.className = 'notify-dialog' + (type ? ' ' + type : '');

    document.getElementById('notifyTitle').textContent = title;
    document.getElementById('notifyBody').innerHTML = message;

    var btn = document.getElementById('notifyBtn');
    btn.textContent = options.buttonLabel || 'OK';

    overlay.classList.add('open');
  };

  window.closeNotify = function() {
    if (overlay) overlay.classList.remove('open');
    if (onCloseCallback) {
      var cb = onCloseCallback;
      onCloseCallback = null;
      cb();
    }
  };
})();
