    function handleLogin(e) {
      e.preventDefault();
      var userId = document.getElementById('userId').value.trim();
      var password = document.getElementById('password').value.trim();

      if (!userId) {
        showError('ユーザーIDを入力してください');
        return false;
      }
      if (!password) {
        showError('パスワードを入力してください');
        return false;
      }

      // ボタンを無効化（二重送信防止）
      var btn = document.getElementById('loginBtn');
      btn.disabled = true;
      btn.textContent = 'ログイン中...';

      fetch('api/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ login_id: userId, password: password })
      })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.success) {
          window.location.href = 'menu.html';
        } else {
          showError(data.error || 'ログインに失敗しました');
          btn.disabled = false;
          btn.textContent = 'ログイン';
        }
      })
      .catch(function() {
        showError('通信エラーが発生しました');
        btn.disabled = false;
        btn.textContent = 'ログイン';
      });

      return false;
    }

    function showError(msg) {
      var el = document.getElementById('errorMessage');
      el.textContent = msg;
      el.classList.add('visible');
    }

    function clearError() {
      document.getElementById('errorMessage').classList.remove('visible');
    }
