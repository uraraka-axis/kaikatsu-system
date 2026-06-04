'use strict';

/**
 * システム設定画面
 * - 期の開始月（fiscal_start_month）の取得・更新
 * - カテゴリの一覧表示・追加・削除・締めルール編集
 */

const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

document.addEventListener('DOMContentLoaded', () => {
  // 読み込み完了まで保存不可（カテゴリ取得前に保存して空配列が送られる競合を防止）
  const saveBtn = document.querySelector('.btn-submit');
  if (saveBtn) saveBtn.disabled = true;
  loadSettings();
});

async function loadSettings() {
  try {
    const [settingsRes, categoriesRes] = await Promise.all([
      fetch('api/admin/system-settings.php', { credentials: 'same-origin' }),
      fetch('api/admin/categories.php', { credentials: 'same-origin' }),
    ]);

    if (settingsRes.status === 401 || categoriesRes.status === 401) {
      window.location.href = 'login.html';
      return;
    }
    if (!settingsRes.ok || !categoriesRes.ok) {
      throw new Error('設定の取得に失敗しました');
    }

    const settings = await settingsRes.json();
    const categories = await categoriesRes.json();

    if (!settings.success || !categories.success) {
      throw new Error(settings.error || categories.error || '設定の取得に失敗しました');
    }

    renderFiscalStartMonth(settings.data.fiscal_start_month);
    renderProductDeptEmail(settings.data.product_dept_email);
    renderCategoryTable(categories.data);

    // 読み込み完了。保存を有効化
    const saveBtn = document.querySelector('.btn-submit');
    if (saveBtn) saveBtn.disabled = false;
  } catch (err) {
    console.error(err);
    alert('設定の読み込みに失敗しました: ' + err.message);
  }
}

function renderFiscalStartMonth(month) {
  const select = document.getElementById('fiscalStartMonth');
  if (!select) return;
  const m = parseInt(month, 10);
  select.value = (m >= 1 && m <= 12) ? String(m) : '4';
}

function renderProductDeptEmail(email) {
  const input = document.getElementById('productDeptEmail');
  if (!input) return;
  input.value = email || '';
}

function renderCategoryTable(categories) {
  const tbody = document.getElementById('categoryTableBody');
  tbody.innerHTML = '';
  categories.forEach(cat => appendCategoryRow(cat));
}

function appendCategoryRow(cat) {
  const tbody = document.getElementById('categoryTableBody');
  const tr = document.createElement('tr');
  tr.dataset.originalCode = cat.code || '';

  // カテゴリ名
  const nameTd = document.createElement('td');
  nameTd.className = 'col-name';
  const nameInput = document.createElement('input');
  nameInput.type = 'text';
  nameInput.className = 'cat-input cat-name';
  nameInput.maxLength = 50;
  nameInput.value = cat.name || '';
  nameInput.placeholder = '例: フィットネス';
  nameTd.appendChild(nameInput);

  // コード
  const codeTd = document.createElement('td');
  codeTd.className = 'col-code';
  const codeInput = document.createElement('input');
  codeInput.type = 'text';
  codeInput.className = 'cat-input cat-code';
  codeInput.maxLength = 20;
  codeInput.value = cat.code || '';
  codeInput.placeholder = '例: fitness';
  codeInput.pattern = '[a-z0-9_]+';
  if (cat.code) {
    codeInput.disabled = true;
    codeInput.title = 'コードは登録後変更できません';
  }
  codeTd.appendChild(codeInput);

  // 締めタイプ
  const typeTd = document.createElement('td');
  typeTd.className = 'col-type';
  const typeSelect = document.createElement('select');
  typeSelect.className = 'cat-select cat-type';
  [
    { v: 'none',    l: '都度' },
    { v: 'monthly', l: '月次' },
    { v: 'weekly',  l: '週次' },
  ].forEach(o => {
    const opt = document.createElement('option');
    opt.value = o.v;
    opt.textContent = o.l;
    typeSelect.appendChild(opt);
  });
  typeSelect.value = cat.closing_type || 'none';
  typeTd.appendChild(typeSelect);

  // 締め日 / 曜日
  const dayTd = document.createElement('td');
  dayTd.className = 'col-day';
  dayTd.appendChild(buildClosingDayControl(typeSelect.value, cat.closing_day));

  typeSelect.addEventListener('change', () => {
    dayTd.innerHTML = '';
    dayTd.appendChild(buildClosingDayControl(typeSelect.value, 0));
  });

  // 操作（削除）
  const actionTd = document.createElement('td');
  actionTd.className = 'col-action';
  const removeBtn = document.createElement('button');
  removeBtn.type = 'button';
  removeBtn.className = 'btn-icon';
  removeBtn.title = '削除';
  removeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
  removeBtn.addEventListener('click', () => removeCategoryRow(tr));
  actionTd.appendChild(removeBtn);

  tr.appendChild(nameTd);
  tr.appendChild(codeTd);
  tr.appendChild(typeTd);
  tr.appendChild(dayTd);
  tr.appendChild(actionTd);
  tbody.appendChild(tr);
}

function buildClosingDayControl(type, currentValue) {
  if (type === 'monthly') {
    const sel = document.createElement('select');
    sel.className = 'cat-select cat-day';
    for (let d = 1; d <= 31; d++) {
      const opt = document.createElement('option');
      opt.value = String(d);
      opt.textContent = d + '日';
      sel.appendChild(opt);
    }
    const v = parseInt(currentValue, 10);
    sel.value = (v >= 1 && v <= 31) ? String(v) : '1';
    return sel;
  }
  if (type === 'weekly') {
    const sel = document.createElement('select');
    sel.className = 'cat-select cat-day';
    WEEKDAY_LABELS.forEach((label, i) => {
      const opt = document.createElement('option');
      opt.value = String(i);
      opt.textContent = label + '曜日';
      sel.appendChild(opt);
    });
    const v = parseInt(currentValue, 10);
    sel.value = (v >= 0 && v <= 6) ? String(v) : '0';
    return sel;
  }
  // none: 入力不要
  const placeholder = document.createElement('input');
  placeholder.type = 'text';
  placeholder.className = 'cat-input cat-day';
  placeholder.value = '—';
  placeholder.disabled = true;
  return placeholder;
}

function addCategoryRow() {
  appendCategoryRow({ code: '', name: '', closing_type: 'none', closing_day: 0 });
}

function removeCategoryRow(tr) {
  if (!confirm('このカテゴリを削除しますか？\n（過去の発注・予算はカテゴリコードで紐付くため、データが残っているカテゴリは削除できません）')) return;
  tr.remove();
}

function collectCategories() {
  const rows = document.querySelectorAll('#categoryTableBody tr');
  const list = [];
  for (const tr of rows) {
    const name = tr.querySelector('.cat-name').value.trim();
    const code = tr.querySelector('.cat-code').value.trim();
    const type = tr.querySelector('.cat-type').value;
    const dayEl = tr.querySelector('.cat-day');
    let day = 0;
    if (type === 'monthly' || type === 'weekly') {
      day = parseInt(dayEl.value, 10);
      if (Number.isNaN(day)) day = 0;
    }

    if (!name || !code) {
      throw new Error('カテゴリ名とコードは必須です');
    }
    if (!/^[a-z0-9_]+$/.test(code)) {
      throw new Error(`カテゴリコード "${code}" は英小文字・数字・_ のみ使用可能です`);
    }
    if (type === 'monthly' && (day < 1 || day > 31)) {
      throw new Error(`カテゴリ "${name}" の締め日は1〜31の範囲で指定してください`);
    }
    if (type === 'weekly' && (day < 0 || day > 6)) {
      throw new Error(`カテゴリ "${name}" の締め曜日が不正です`);
    }

    list.push({
      code,
      name,
      closing_type: type,
      closing_day: day,
      original_code: tr.dataset.originalCode || '',
    });
  }
  return list;
}

async function saveSettings() {
  let categories;
  try {
    categories = collectCategories();
  } catch (e) {
    alert(e.message);
    return;
  }

  const fiscalStartMonth = parseInt(document.getElementById('fiscalStartMonth').value, 10);
  if (!(fiscalStartMonth >= 1 && fiscalStartMonth <= 12)) {
    alert('期の開始月が不正です');
    return;
  }

  const productDeptEmail = (document.getElementById('productDeptEmail').value || '').trim();
  // 空文字は許容（通知/CCを使わない運用）。値があれば形式チェック
  if (productDeptEmail !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(productDeptEmail)) {
    alert('商品部メールアドレスの形式が正しくありません');
    return;
  }

  const btn = document.querySelector('.btn-submit');
  // 保存中は画面全体のクリックを遮断（多重操作・処理中の画面遷移を防止）
  const endBusy = beginBusy(btn, '保存中...');

  try {
    const res = await fetch('api/admin/system-settings.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify({
        fiscal_start_month: fiscalStartMonth,
        product_dept_email: productDeptEmail,
        categories,
      }),
    });

    if (res.status === 401) {
      window.location.href = 'login.html';
      return;
    }

    const result = await res.json();
    if (!res.ok || !result.success) {
      throw new Error(result.error || '保存に失敗しました');
    }

    alert('設定を保存しました');
    loadSettings();
  } catch (err) {
    console.error(err);
    alert('保存に失敗しました: ' + err.message);
  } finally {
    endBusy(); // 元のラベルに復元＋クリック遮断解除
  }
}
