# 引継ぎドキュメント — 快活フロンティア 発注管理システム

最終更新: 2026-05-25
最新コミット: `0625490 発注メール下書き機能 (Phase 1) + 管理画面 UI 微修正`（develop ブランチ push 済み）

---

## 1. プロジェクト概要

店舗向け発注管理システム（修理・備品・部品の3種別 + 予算管理 + 自店調達申請 + マスタメンテ + 監査ログ）。
快活フロンティア（旧 快活CLUB）等の店舗運営における発注業務の効率化が目的。

- **業務領域**: 発注管理 / 予算管理 / 自店調達申請 / 各種マスタメンテ / 監査ログ
- **発注タイプ**: 修理発注 / 備品発注（フィットネス・ゴルフ）/ 部品発注
- **ステータス**: 依頼中(0) → 発注済(1) → 配達中/修理待ち(2) → 納品済/修理済(3) → 完了(4)
- **ロール**:
  - `shop` — 店舗スタッフ（自店データのみ）
  - `admin` — 本部商品部（全店データ・マスタ管理）
  - `system` — IT 管理者（admin 全権限 + 監査ログ閲覧）※ 2026-05 追加

---

## 2. リポジトリ・ブランチ運用

| 項目 | 値 |
|---|---|
| リポジトリ | https://github.com/uraraka-axis/kaikatsu-system.git |
| `master` | **モック版（静的HTML/CSS/JS）** — GitHub Pages で先方確認用に公開中。**直接 push 禁止** |
| `develop` | **PHP版（バックエンド開発）** — ローカル XAMPP で動作確認 |
| モック公開URL | https://uraraka-axis.github.io/kaikatsu-system/ |
| モック公開URL(ログイン) | https://uraraka-axis.github.io/kaikatsu-system/login.html |

### コミット運用
- 機能単位でコミット、メッセージは日本語可
- master への直接 push 禁止／force-push は原則禁止
- 開発は `develop` で行う

---

## 3. 技術スタック

| 領域 | 採用技術 |
|---|---|
| サーバ | XAMPP（Apache + MySQL 8.0 + PHP 8.2） |
| 言語 | PHP 8.2 / vanilla JavaScript（フレームワーク不使用） |
| フロント | HTML + CSS + JS、フォントは Inter / Noto Sans JP |
| ライブラリ | PhpSpreadsheet（Excel出力用、Composer で導入） |
| 文字コード | UTF-8（BOM なし）／ DB は utf8mb4 |
| 本番想定 | さくらレンタルサーバー |

---

## 4. 開発環境構築手順

### 4-1. 必要なソフト
- [XAMPP](https://www.apachefriends.org/jp/index.html)（PHP 8.2 系を含むバージョン）
- Git（Git for Windows 等）
- 任意のエディタ（VS Code 推奨）

### 4-2. リポジトリのクローン
```bash
# 推奨配置: C:\Users\<ユーザー名>\kaikatsu-system\
cd C:\Users\<ユーザー名>\
git clone https://github.com/uraraka-axis/kaikatsu-system.git
cd kaikatsu-system
git checkout develop
```

### 4-3. Apache Alias 設定
htdocs にコピーせず、git リポジトリを直接配信する方式を採用。

`C:\xampp\apache\conf\httpd.conf` の末尾付近に以下を追加（パスはクローン先に合わせて変更）:

```apache
# === kaikatsu-system: git repo を直接配信 ===
Alias /kaikatsu-system "C:/Users/<ユーザー名>/kaikatsu-system"
<Directory "C:/Users/<ユーザー名>/kaikatsu-system">
    Options Indexes FollowSymLinks Includes ExecCGI
    AllowOverride All
    Require all granted
</Directory>
```

設定後、XAMPP コントロールパネルから Apache を再起動。

### 4-4. データベースセットアップ
1. XAMPP の MySQL を起動
2. phpMyAdmin（http://localhost/phpmyadmin/）または MySQL CLI で以下を実行:
   ```sql
   CREATE DATABASE kaikatsu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. スキーマ・初期データを投入:
   ```bash
   mysql -u root kaikatsu < database/schema.sql
   mysql -u root kaikatsu < database/seed.sql
   ```
4. 既存DBがある場合は、必要な migration を順次適用:
   ```bash
   mysql -u root kaikatsu < database/migration_categories_closing.sql
   mysql -u root kaikatsu < database/migration_products_codes.sql       # JAN/仕入先商品コード列追加
   mysql -u root kaikatsu < database/migration_master_change_log.sql    # マスタ変更履歴
   mysql -u root kaikatsu < database/migration_master_scheduled_changes.sql  # マスタ予約更新
   mysql -u root kaikatsu < database/migration_user_manager_emails.sql  # ゾンマネ/エリマネ通知メアド
   mysql -u root kaikatsu < database/migration_login_history.sql        # ログイン履歴
   ```
   ※ 2026-05-25 時点で `schema.sql` には上記すべて統合済み。新規構築時は schema.sql 一本で OK。

### 4-5. パスワードハッシュ化
seed.sql ではパスワードがプレーンテキスト `password` で投入されるため、ハッシュ化スクリプトを実行:

```bash
cd C:\Users\<ユーザー名>\kaikatsu-system
php setup/hash_passwords.php
```

（再実行しても安全。既にハッシュ化済みのものはスキップされる）

### 4-6. Composer 依存ライブラリの導入
```bash
php composer.phar install
```

→ `vendor/` ディレクトリに PhpSpreadsheet がインストールされる。

### 4-7. uploads ディレクトリの権限
```
kaikatsu-system/uploads/
```
が書き込み可能であることを確認（写真アップロード用）。

### 4-8. 動作確認
ブラウザで http://localhost/kaikatsu-system/login.html にアクセス。
以下のテストアカウントでログイン可能:

| login_id | password | role | 備考 |
|---|---|---|---|
| admin | password | admin | 商品部 |
| system | password | system | IT 管理者（監査ログ閲覧可） |
| 10301 | password | shop | 新宿東口店 |
| 10101 | pass001 | shop | 札幌店（マスタ UL でパスワード変更済） |
| (他の店舗コードも基本は password) | | shop | seed.sql 参照、マスタ UL で個別変更可能 |

---

## 5. ディレクトリ構成

```
kaikatsu-system/
├── api/                          # APIエンドポイント
│   ├── admin/
│   │   ├── master/               # マスタCRUD（Excel UL/DL）: zones/areas/shops/suppliers/users/products/budgets
│   │   ├── scheduled-changes/    # マスタ予約更新の一覧/作成/取消
│   │   ├── categories.php        # カテゴリCRUD
│   │   └── system-settings.php   # 期間設定（会計年度開始月）
│   ├── export/
│   │   ├── master/               # マスタDL（テンプレ + 現状データ）
│   │   ├── orders.php            # 発注Excel出力（チェック行/フィルタ）
│   │   └── budgets.php           # 予算Excel出力
│   ├── master/                   # マスタ参照API（zones/areas/shops/categories）
│   ├── orders/                   # 発注関連API
│   │   ├── create.php
│   │   ├── status.php / bulk-status.php
│   │   ├── update-info.php
│   │   └── draft-mails.php       # ★発注メール下書き（Phase 1, 2026-05-25 追加）
│   ├── system/                   # ★system 専用API
│   │   ├── master-change-log.php
│   │   ├── master-scheduled-changes.php
│   │   └── login-history.php
│   ├── budgets.php / orders.php / procurement.php / products.php
│   ├── login.php / logout.php / me.php
│   └── photo.php / product-image.php
├── css/                          # スタイルシート
├── database/
│   ├── schema.sql                          # DBスキーマ（24テーブル）
│   ├── seed.sql                            # 初期データ
│   ├── migration_*.sql                     # 既存DB追従用の差分
│   ├── db-spec.md                          # DBスキーマ仕様書
│   └── db-guide.md                         # DB運用ガイド（非エンジニア向け）
├── includes/                     # PHP共通基盤
│   ├── config.php / db.php / auth.php / csrf.php / functions.php
│   ├── master_excel.php          # マスタExcel UL/DL 共通フレームワーク
│   ├── master_scheduling.php     # マスタ予約更新 共通フレームワーク
│   ├── budget.php / budget_notify.php
│   └── mail.php                  # PHPMailer 経由メール送信ヘルパ
├── js/                           # フロントエンドJS
├── setup/
│   ├── hash_passwords.php           # パスワードハッシュ化スクリプト
│   └── apply_scheduled_changes.php  # マスタ予約更新の cron 適用バッチ
├── tools/                        # 開発・テスト補助スクリプト
│   ├── seed_draft_mail_test_data.php    # 発注メール下書き動作確認用
│   └── seed_supplier_emails.sql
├── uploads/                      # 写真アップロード保存先（gitignore）
├── vendor/                       # Composer依存（gitignore）
├── docs/                         # 画面設計書・SVGスライド・ダイアログキャプチャ（gitignore）
├── backup/                       # 古いバックアップ（gitignore）
├── *.html                        # 各画面
├── backend-spec.md               # バックエンド仕様書（必読）
├── master-crud-spec.md           # マスタCRUD（Excel UL/DL）仕様書
├── composer.json / composer.lock / composer.phar
└── HANDOVER.md                   # 本ファイル
```

---

## 6. 仕様書・ドキュメント（リポジトリ内）

| ファイル | 内容 |
|---|---|
| `backend-spec.md` | バックエンド全体仕様（API一覧・締めルール・バッチ処理・ステータス遷移） |
| `master-crud-spec.md` | マスタCRUD（Excel UL/DL）仕様 — 監査ログ・予約更新含む |
| `database/db-spec.md` | DBスキーマ仕様（全 24 テーブル定義） |
| `database/db-guide.md` | DB運用ガイド（非エンジニア向けの解説、初期データ含む） |
| `docs/screen-design.html` | 画面設計書（master ブランチ参照） |
| `docs/快活システム_画面機能一覧_開発状況.xlsx` | 進捗ダッシュボード（画面・API・要件トレーサビリティ） |
| `docs/快活システム_DB定義書.xlsx` | DB定義書（全テーブルの列・型・コメント） |

---

## 7. 重要な仕様（最近の変更点）

### 7-1. カテゴリ別締めルール（2026-04-28 導入）
**締めルールの所在を `system_settings` から `categories` テーブルへ移行。**

`categories` テーブルに以下を追加:
- `closing_type` ENUM(`none`, `monthly`, `weekly`)
- `closing_day` INT — monthly なら日付(1-31), weekly なら曜日(0=日…6=土)

| カテゴリ | closing_type | closing_day | 意味 |
|---|---|---|---|
| フィットネス備品 | monthly | 8 | 毎月8日締め |
| ゴルフ備品 | weekly | 2 | 毎週火曜締め |
| 部品・修理 | none | NULL | 都度発注 |

`system_settings.equipment_deadline_weekday` は廃止。`fiscal_start_month`(=4) のみ残存。

### 7-2. 締め日計算ロジックの実装位置
- **PHP**: `api/budgets.php`（予算計上月の判定）
- **JS**: `js/equipment-order.js`（備品発注画面の予算アラート）
- **JS**: `js/order-list.js`（管理者モーダルの納品予定日初期値）

### 7-3. 年度規約
- 日本の会計年度（4月開始3月終了）
- 「開始年=年度名」（例: 2026/4〜2027/3 は 2026年度）
- `getCurrentFiscalYear()` および JS `getFiscalMonthIndex()` でこの規約を実装

### 7-4. 部門マッピング
budgets テーブルの `department` 列:
- フィットネス系 → `fit`
- ゴルフ系 → `ig`

### 7-5. マスタCRUD（Excel UL/DL）— 完成（2026-05 完成）
管理メニュー（admin-menu.html）から、Excel ファイル一括 UL/DL 方式で 7 種マスタを更新。

- **対象**: zones / areas / shops / suppliers / users / products / budgets
- **共通フレームワーク**: `includes/master_excel.php`
- **手順**: ファイル選択 → `dry_run=1` でプレビュー（追加/変更/削除/エラー集計） → 「確定」で反映
- **監査**: 確定時に `master_change_log` に 1 レコード/件で記録。同一バッチは UUID で連結
- **機密列**: `users.password` 等は監査ログ・予約レコード・プレビューでマスク（`********`）
- **仕様詳細**: `master-crud-spec.md`

### 7-6. マスタ予約更新（2026-05 完成）
未来日時を指定して反映予定を登録し、cron バッチで適用するフロー。

- **対象**: 7 種マスタすべて（フェーズ 1: zones/areas/suppliers、フェーズ 2A: shops/users/products/shop_categories、フェーズ 2B: budgets）
- **テーブル**: `master_scheduled_changes`
- **バッチ**: `setup/apply_scheduled_changes.php`（cron 5 分間隔想定、`setup/.apply_scheduled_changes.lock` で多重起動防止）
- **API**: `/api/admin/scheduled-changes/*`（一覧/作成/取消）

### 7-7. system ロールと監査ログ閲覧UI（2026-05 追加）
IT 管理者向けの新ロール `system`。admin の全権限に加え、以下が追加で可能:

- 監査ログ閲覧画面（`master-change-log.html`）
  - **タブ 1**: マスタ変更履歴（`master_change_log`）
  - **タブ 2**: マスタ予約更新（`master_scheduled_changes`）
  - **タブ 3**: ログイン履歴（`login_history`）
- メニュー画面で system ロールのときだけ「監査ログ」リンクが出現
- 既存 PHP 6 ファイルの権限チェックを `$user['role'] === 'admin'` から `in_array($user['role'], ['admin','system'], true)` に統一

### 7-8. 発注メール下書き機能 Phase 1（2026-05-25 追加）
発注一覧（admin）に「📧 メール下書き作成」ボタン。

- 「依頼中（status=0）」の備品発注を `supplier` 単位に集計（行チェックがあれば対象限定、なければ画面フィルタを引き継ぎ）
- 仕入先タブごとに To / 件名 / 本文を生成（仕入先マスタから連絡先補完）
- 「メーラーで開く」（`mailto:`、2000 字超で警告） / 「本文コピー」 / 「この仕入先分を発注済にする」
- 「発注済にする」は既存 `api/orders/bulk-status.php` を再利用
- **API**: `api/orders/draft-mails.php`
- **テストデータ**: `tools/seed_draft_mail_test_data.php`（45 件の依頼中・備品発注を生成）

---

## 8. コーディング規約（重要）

### 8-1. PHP（`.claude/rules/php-coding.md`）
- DB操作は **PDOプリペアドステートメント必須**（文字列結合での SQL 構築は禁止）
- 複数テーブル更新時は **トランザクション必須**
- DBカラム名は **snake_case**
- ユーザー入力は `filter_input` / `filter_var` でバリデーション
- HTML出力は `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- セッションに `user_id` と `role` がない場合は 401
- ファイルアップロードは MIME タイプとサイズを検証
- API レスポンス形式:
  - 成功: `{"success": true, "data": ...}`
  - エラー: `{"success": false, "error": "メッセージ"}`
- HTTP ステータスコードを適切に使う（200/400/401/403/404/500）
- Content-Type: `application/json; charset=utf-8`

### 8-2. フロントエンド（`.claude/rules/frontend.md`）
- フォントサイズ最小 **12px**（WCAG準拠）
- テーブル画面は **1400px**、フォーム画面は **1200px**
- フォント: Inter, Noto Sans JP
- API レスポンスのエラーハンドリング必須
- fetch 時の **401 はログインページへリダイレクト**
- `DOMContentLoaded` で初期化

### 8-3. Git（`.claude/rules/git-workflow.md`）
- master への直接 push 禁止（GitHub Pages 公開中）
- develop での開発が基本
- force-push は原則禁止
- コミット前にユーザー確認

---

## 9. 残タスク・継続課題

### 9-1. 直近の未完了タスク
- [ ] **発注メール下書き Phase 1 動作確認** — モーダル/メーラー起動/コピー/発注済化の通し動作
- [ ] **iPad レイアウト調整 4 項目** — 発注一覧右切れ・タッチターゲット 44px・iPad Pro breakpoint・iOS ズーム対策
- [ ] **業務フロー設計書 v2.0 PPTX の手動「図形変換」** — 14 スライド分（PowerPoint で各スライド選択 → グラフィックス形式 → 図形に変換）

### 9-2. 中期で残っている開発項目

詳細は **[docs/快活システム_画面機能一覧_開発状況.xlsx](docs/快活システム_画面機能一覧_開発状況.xlsx)** を参照（2026-05-25 時点）。

5シート構成:
1. **画面一覧** — 全画面のフロント/バック開発状況
2. **API一覧** — 全エンドポイントのメソッド・権限・状況
3. **機能要件** — ビジネスルールの実装状況
4. **要件トレーサビリティ** — 元要件・見積明細・設計事項・バッチ処理の対応表
5. **サマリー** — カテゴリ別進捗 + 見積金額ベース進捗 + 主な残作業リスト

> 更新方法: `docs/generate_status_excel.py` を編集して `python generate_status_excel.py` で再生成。

#### 主な残作業（2026-05-26 時点）
- 予算超過通知のゾンマネ／エリマネ自動メール通知（メール送信基盤は完成済）
- 発注メール下書き Phase 2: SMTP 直送 + 仕入先別テンプレ
- レスポンシブ対応（iPad 4 項目）
- 本番デプロイ（さくらレンタルサーバー）準備（cron 登録含む）

#### 対象外（手動運用で代替）
- **備品自動発注**: 締め日に status=0 → 1 を自動化する想定だったが、商品部の手動「ステータス一括変更」＋「発注メール下書き → 発注済化」で代替できるため対象外とする

#### 完了済（cron 登録は本番デプロイ時）
- **ステータス自動遷移**: `setup/auto_advance_status.php` 実装完了。1→2（備品・カテゴリ締め日翌日）/ 2→3（予定日）/ 3→4（予定日翌日、final_amount 確定 + 予算実績反映）
- **予算実績締め処理**: 当初の cron バッチは廃止。2026-05-23 から `applyBudgetActualDelta()` が status 遷移時にリアルタイム反映する設計（Plan B）に変更済

---

## 10. テスト・確認ツール

### 10-1. URL
- ログイン: http://localhost/kaikatsu-system/login.html
- メニュー: http://localhost/kaikatsu-system/menu.html
- phpMyAdmin: http://localhost/phpmyadmin/

### 10-2. ロール切替（開発時のみ）
URLに `?role=admin` を付けると admin モード、デフォルトは shop。
※ 本番ではセッションの `role` を信頼する（`api/me.php` 経由）。

---

## 11. 連絡・補足

- 不明点は `backend-spec.md` を **正** として参照
- DB変更時は必ず `database/db-spec.md` および `database/db-guide.md` を更新
- 仕様変更があれば本 `HANDOVER.md` も追記更新

---

以上。
