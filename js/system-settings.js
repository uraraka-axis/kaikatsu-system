    function addCategory() {
      var name = prompt('カテゴリ名を入力してください：');
      if (!name) return;
      var code = prompt('カテゴリコード（英数字）を入力してください：');
      if (!code) return;
      var list = document.getElementById('categoryList');
      var div = document.createElement('div');
      div.className = 'category-item';
      div.innerHTML = '<div><span class="category-item-name">' + name + '</span><span class="category-item-code">' + code + '</span></div>' +
        '<button class="btn-icon" onclick="removeCategory(this)" title="削除"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
      list.appendChild(div);
    }

    function removeCategory(btn) {
      if (confirm('このカテゴリを削除しますか？')) {
        btn.parentElement.remove();
      }
    }

    function saveSettings() {
      alert('設定を保存しました');
    }
