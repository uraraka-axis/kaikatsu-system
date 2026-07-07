# 快活システム 回帰スモークテスト

主要11画面×3ロール（admin / shop / zone）を Playwright で自動巡回し、
「開ける・弾かれる・真っ白でない・consoleエラーなし」を計25項目・約30秒で検証する。

## 実行方法

前提: XAMPP の Apache + MySQL が起動していること（DB=kaikatsu）。

```
tests\e2e\run_smoke.bat          … 通常実行（ヘッドレス）
tests\e2e\run_smoke.bat --headed … ブラウザを表示しながら実行
```

またはリポジトリルートで `python tests\e2e\smoke_test.py`。
全項目○なら exit 0、1件でもNGなら exit 1 とNG一覧を表示。

## 検証内容

- 未ログインで保護ページ → login.html へ誘導されるか
- ログインUI（誤パスワード拒否 / 正常ログインで menu.html 遷移）
- ロール別に許可画面がすべて開くか（HTTP・リダイレクト・auth-pending・描画量・consoleエラー）
- shop が管理画面（admin-menu / system-settings）に入れないか

## アカウント

現ローカルDBは「パスワード = ログインID」（admin/admin, 30101/30101, 100004/100004）。
DBを再構築してパスワードが変わったら `smoke_test.py` の `ACCOUNTS` を更新すること。

## 使いどころ

- 改修後の動作確認（手動1周30分 → 20秒）
- 本番反映前の最終チェック（deploy-check スキルとセットで）
- 検証シート対応後の「別の画面が壊れていないか」確認

## 注意

- **DBへの書き込みは行わない**読み取り専用テスト。発注登録・ステータス変更の
  書き込み系回帰は `tools/verify_2026_05_28.php` 等のAPIレベル検証を使う
- テストは専用のヘッドレスブラウザで動く（普段使いのChromeには触らない）
- 2026-07-07: 初回実行で admin-menu / system-settings のフロントロールガード欠落を検出
  → userLoaded ガードを追加して修正済み（25項目全○）。API側はもともと403で保護
