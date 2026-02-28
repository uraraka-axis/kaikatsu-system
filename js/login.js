    function handleLogin(e) {
      e.preventDefault();
      var userId = document.getElementById('userId').value.trim();
      var password = document.getElementById('password').value.trim();
      var errorEl = document.getElementById('errorMessage');

      if (!userId) {
        showError('ユーザーIDを入力してください');
        return false;
      }
      if (!password) {
        showError('パスワードを入力してください');
        return false;
      }

      // モックアップ：ログイン成功時はメニュー画面に遷移
      alert('ログイン成功（モックアップ）\n\nユーザーID: ' + userId + '\n権限: システム管理者');
      window.location.href = 'menu.html';
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
