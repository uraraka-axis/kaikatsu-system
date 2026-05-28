# 引継ぎドキュメント — 快活フロンティア 発注管理システム

最終更新: 2026-05-28
最新コミット: `b7a5735 発注API: メール送信を非同期化してレスポンス即時返却`（develop ブランチ push 済み）

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
  - `zone` — ゾーンマネージャー（管轄ゾーン配下の店舗を横断閲覧・閲覧専用）※ 2026-05-26 追加
  - `area` — エリアマネージャー（管轄エリア配下の店舗を横断閲覧・閲覧専用）※ 2026-05-26 追加

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

### 7-9. ゾーン／エリアマネージャー ロール追加（2026-05-26）
従来の shop/admin/system に加え、`zone`（ゾーンマネージャー）/ `area`（エリアマネージャー）の 2 ロールを追加。

- **DB**: `database/migration_zone_area_roles.sql` — users.role enum 拡張 + `zone_code`/`area_code` カラム追加
- **Backend**: `auth.php` に `isManager()` / `requireManager()` / `getRoleScopeSql()` を追加。`orders.php` / `budgets.php` / `procurement.php` / `photo.php` / `export/orders.php` / `export/budgets.php` をロール別スコープで絞り込み
- **Frontend**: `menu.html` / `order-list.html` / `budget-management.html` / `procurement-history.html` で zone/area ビュー対応。フィルタは管轄ゾーン／エリアで固定 (disabled) 表示
- **権限**:
  - 利用可能画面 = 発注一覧 / 予算管理 / 自店調達 の 3 画面のみ（**閲覧専用**）
  - ステータス操作・発注作成・マスタ管理は不可
  - Excel 出力は管轄スコープ内で可能
- **テストデータ**: `tools/seed_zone_area_users.php`（Z100/Z200 + A101〜A202 の計 7 ユーザー、パスワード `password`）

### 7-10. users マスタ Excel UL/DL 拡張（2026-05-26）
- `zone_code` / `area_code` 列を追加（zone/area ユーザーを Excel から編集可能に）
- ヘッダー名「メアド」→「メールアドレス」に変更（ゾーンマネージャーメールアドレス / エリアマネージャーメールアドレス）
- role pattern を `shop|admin|system|zone|area` の 5 種に拡張
- preprocess_rows で role に応じて shop_code/zone_code/area_code を NULL 強制
- validate_extra で role=zone/area の各コード必須チェック
- FK チェック: shops/zones/areas の 3 マスタで参照整合性を確認

### 7-11. me.php の DB 最新化（2026-05-26）
`api/me.php` を呼び出すたびに DB から最新の `name` / `shop_name` / `zone_name` / `area_name` を取得しセッションを更新。

- 効果: admin が users マスタを Excel で UL してユーザー名を変更しても、対象ユーザーが画面リロードするだけで反映される
- DB で `is_active=0` または削除されていた場合は自動ログアウト + 401（セッション残存対策）

### 7-12. 検索ボタン削除 + フィルタ即時反映（2026-05-26）
発注一覧 / 予算管理画面から「検索」ボタンを削除。各 select/date input は `onchange` で即時に API 再フェッチする統一仕様に。自店調達画面（元から検索ボタン無し）と挙動を統一。

### 7-13. categories API の zone/area 対応（2026-05-26）
`api/master/categories.php` が `admin/system` のみ全カテゴリを返す作りだったため、zone/area で空配列が返り、カテゴリフィルタが「すべてのカテゴリ」しか表示されない問題があった。shop ロールのみ shop_categories と JOIN し、admin/system/zone/area は全カテゴリを返すように修正。

### 7-14. テストデータの整理（2026-05-26）
- `tools/seed_users_sort_and_emails.php` で sort_order を振り直し: shop=1〜30、zone=60〜61、area=70〜74、admin=80、system=99
- 各 shop ユーザーに `zone_manager_email` / `area_manager_email` を投入（`zone-east@example.test` など）

### 7-15. 発注の論理削除（取消）機能（2026-05-28）
誤発注対応として `orders.cancelled_at` で論理削除する仕組みを追加。

- **DB**: `orders` に `cancelled_at` / `cancelled_by` / `cancel_reason` の 3 カラム + `idx_orders_cancelled` 追加
- **API**: `api/orders/cancel.php`（admin/system のみ、status=0 のみ取消可、cancel_reason 500字以内必須）
- **挙動**: `order_status_history` に「【取消】<理由>」として履歴記録。以後の一覧・Excel・メール下書きは `cancelled_at IS NULL` で完全に非表示
- **既存 API 影響**: `orders.php` / `bulk-status.php` / `update-info.php` / `draft-mails.php` / `export/orders.php` 全てに `cancelled_at IS NULL` フィルタ追加
- 詳細は [backend-spec.md §5.6](backend-spec.md) 参照

### 7-16. 予算実績計上を納品月ベースに変更（2026-05-28）
予算消化のタイミングを「ステータス遷移日」ではなく「実納品月」ベースに修正。

- **新ヘルパー**: `includes/budget.php` の `applyBudgetActualDeltaByDelivery()` / `resolveBudgetKeyByDelivery()`
  - 備品/部品: `orders.actual_delivery_date` の月
  - 修理/シート交換: `order_repair_details.repair_completed_date` / `order_seat_replacement_details.repair_completed_date` の月
- **DB**: `orders.actual_delivery_date` を **必須相当**（status=3 遷移時に入力強制、`api/orders/status.php` の `delivery-done` でバリデーション）
- **加算ルール**:
  - status=3 (納品済/修理済): `estimate_amount` を加算
  - status=4 (完了): `final_amount - estimate_amount` の差分を加算
  - 完了後の final_amount 編集: 差分のみ加減算
- 詳細は [backend-spec.md §7 予算実績反映の設計](backend-spec.md)

### 7-17. 備品ステータス自動遷移バッチ廃止（2026-05-28）
備品の全自動遷移バッチ `setup/auto_advance_status.php` を **廃止**（ファイル削除）。

- 商品部の運用要望「実際に業者へ発注／配達／納品されたタイミングで進めたい」に対応
- `to-delivering`（1→2）も従来は備品のみバッチ自動だったが、商品部の手動操作（個別・一括）に変更
- 一括ステータス変更（`api/orders/bulk-status.php`）も備品を含む全種別が対象に
- 検証: `tools/verify_2026_05_28.php`（21 ケース、全 PASS）

### 7-18. シート交換発注機能（2026-05-28）
マシンのシート交換専用の発注種別を追加。修理発注と同じステータスフロー / UI。

- **DB**: `database/migration_seat_replacement.sql`
  - `orders.type` ENUM に `seat-replacement` 追加
  - `order_seat_replacement_details` テーブル新設（修理 details と同等カラム）
- **画面**: `seat-replacement.html` / `js/seat-replacement.js`
  - フィットネス固定（カテゴリ強制）
  - 依頼内容「マシンのシート交換」固定文言
  - ボタン名「交換を依頼する」
- **メニュー**: `menu.html` に「シート交換」カード追加（部品発注 ⇔ 発注一覧の間、トレーニングマシンアイコン）
- **発注番号 prefix**: `SHT`（例: `SHT-S01-20260528-0001`）— `includes/config.php` に `ORDER_PREFIX_SEAT_REPLACEMENT` 定義
- **修理ライク判定**: `includes/functions.php` に `isRepairLikeType()` / `getRepairLikeDetailTable()` を追加し、全 API・全 JS の `type === 'repair'` チェックを置換
- **発注一覧表示**: バッジ「交換」+ 紫色（`#9333ea`）、行左ボーダー紫
- **検証**: `tools/verify_seat_replacement.php`（14 ケース、全 PASS）

### 7-19. 自店調達申請の予算反映（2026-05-28）
自店調達申請の作成時に申請月の `budgets.actual_amount` へ即時加算する仕様を追加。

- **対象 API**: `POST /api/procurement.php`
- **計上月**: `procurement_requests.date`（申請日）の月
- **新ヘルパー**: `includes/budget.php` の `resolveBudgetKeyByDate()` / `applyBudgetActualDeltaByDate()`
- 納品概念がないため `actual_delivery_date` ベースの発注ロジックとは別物
- **検証**: `tools/verify_procurement_budget.php`（PASS）

### 7-20. メール下書きに CC（商品部）欄追加（2026-05-28）
メール下書き画面に商品部メールアドレスを CC で自動セット。

- **設定保存**: `system_settings.product_dept_email`（単一アドレス・email 検証）
- **取得 API**: `GET /api/orders/draft-mails.php` のレスポンスに `cc_email` を追加
- **編集 UI**: `system-settings.html` に「商品部メールアドレス」入力欄追加（GET/POST 共に `api/admin/system-settings.php`）
- **フロント**: `js/order-list.js` の `draftMailsState.ccEmail` で保持、CC 入力欄 + `mailto:?cc=` に反映

### 7-21. メール送信の非同期化（2026-05-28）
発注作成・ステータス変更時のメール送信が SMTP タイムアウト時に UI を止めていたため、レスポンス先行返却に切替。

- **対象**: `api/orders/create.php` / `api/orders/status.php` / `api/orders/bulk-status.php`
- **共通ヘルパー**: `includes/functions.php` の `jsonResponseAndContinue(mixed $data, int $status = 200)`
  - PHP-FPM: `fastcgi_finish_request()`
  - mod_php (XAMPP): `Content-Length` + `Connection: close` + `flush()`
- **規約**: `commit()` 後に `jsonResponseAndContinue()` を呼び、続けてメール送信、最後に `exit;`
- **制約**: メール送信失敗はクライアントへ伝わらず `error_log` のみ。永続化リトライが必要なら別途 mail_queue 機構を用意

### 7-22. メニュー画面の改善（2026-05-28）
- `.menu-container` の最大幅を **840px → 1200px** に拡張（大型モニタで中央に小さく見える問題を解消）
- スケジュール枠の見出し: 「5月のスケジュール」（動的）→ **「直近の締めスケジュール」**（固定）
- シート交換用に新規 SVG アイコン（トレーニングマシン）を追加

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
- [ ] **業務フロー設計書 v2.0 PPTX の手動「図形変換」** — 14 スライド分（PowerPoint で各スライド選択 → グラフィックス形式 → 図形に変換）

### 9-2. 中期で残っている開発項目

詳細は **[docs/快活システム_画面機能一覧_開発状況.xlsx](docs/快活システム_画面機能一覧_開発状況.xlsx)** を参照（2026-05-28 時点）。

5シート構成:
1. **画面一覧** — 全画面のフロント/バック開発状況
2. **API一覧** — 全エンドポイントのメソッド・権限・状況
3. **機能要件** — ビジネスルールの実装状況
4. **要件トレーサビリティ** — 元要件・見積明細・設計事項・バッチ処理の対応表
5. **サマリー** — カテゴリ別進捗 + 見積金額ベース進捗 + 主な残作業リスト

> 更新方法: `docs/generate_status_excel.py` を編集して `python generate_status_excel.py` で再生成。

#### 主な残作業（2026-05-28 時点）
- 本番デプロイ（さくらレンタルサーバー）準備（cron 登録含む）
- 本番 SMTP 設定（Mailpit → 本番メールサーバへ切替）

#### 対象外（手動運用 or 不採用）
- **備品自動発注**: 締め日に status=0 → 1 を自動化する想定だったが、商品部の手動「ステータス一括変更」＋「発注メール下書き → 発注済化」で代替できるため対象外
- **発注メール下書き Phase 2 (SMTP 直送 + 仕入先別テンプレ)**: Phase 1 の mailto/コピー方式で十分なため当面実装しない

#### 完了済
- **ステータス自動遷移**: 当初 `setup/auto_advance_status.php` で備品の全遷移を自動化していたが、2026-05-28 に **廃止**。商品部の運用要望により全ステータス遷移を手動運用へ変更（`to-delivering` も備品対応に拡張、ファイルは削除済）
- **予算実績締め処理**: 当初の cron バッチは廃止。2026-05-23 から status 遷移時にリアルタイム反映する設計に変更。さらに 2026-05-28 に **納品月ベース** に切替（`applyBudgetActualDeltaByDelivery()`、status=3 で estimate 加算、status=4 で final-estimate 差分）
- **予算超過通知の条件検証**: `tools/test_budget_notify.php` で 9 ケース網羅テスト全 PASS（境界クロス・既超過・delta=0/負・メアド未設定 など）
- **iPad レスポンシブ対応 4 項目**: breakpoint を 1024→1280px に拡張、テーブル横スクロール、タッチターゲット 44px、iOS Safari ズーム対策（input/select 16px）— iPad 9/10 の portrait/landscape 計 4 ビューポートで実機サイズ検証済
- **自店調達のハードコード解消**: 年度プルダウンを DB 由来化（`GET /api/procurement.php?action=years` 追加）、カテゴリフィルタを categories マスタから動的構築、カテゴリ・role バリデーションをマスタ参照に変更、admin の「商品部様」表示を共通ナビ任せに統一
- **3 画面のフィルタバー UI 統一**: 発注一覧 admin/store・予算管理・自店調達の全 5 フィルタを `.admin-filter-bar > .admin-filter-row > .filter-group > .filter-label + control` の共通レイアウトに統一（スタイル本体は `common.css` に集約）
- **予算管理のプルダウン挙動を発注一覧に整合**: ゾーン未選択時に全エリア・全店舗を表示、ゾーン/エリア選択時のみ絞り込み（カスケード）。option ラベルも「ゾーン/エリア/店舗」→「すべて」に統一
- **備品発注フィルタラベル統一**: `.filter-label` を `.form-label`（14px / #334155）に揃え、修理・部品発注のフォームラベルと同じ見た目に
- **発注済モーダルの納品予定日デフォルトを「締め日+4日」に統一**: 画面側 `getEquipmentDeliveryDate()` で「締め日+4日」をデフォルト表示（配達 4 日想定。当初は同名ロジックを自動遷移バッチでも使っていたが、バッチ廃止後は画面側のみ）
- **ステータス履歴メモの改行表示 + XSS 対策**: `.timeline-memo` に `white-space: pre-wrap` を追加して入力時の改行を画面で再現、同時に memo / changed_at / changed_by を `escapeHtml()` で安全に出力
- **テストデータ整備**:
  - `tools/seed_yokohama_test_data.php` — 横浜店(10303)で 3 種別 × 5 ステータス + 備品 10 明細サンプル = 計 16 件
  - `tools/seed_draft_mail_test_data.php` — 依頼中履歴の changed_by/created_by を「商品部」固定から店舗ユーザーに修正（実運用に整合）
  - `tools/seed_expand_to_30_shops.php` — 既存 11 → 全国 30 店舗に拡張（shops / shop_categories / users / budgets 3 年分一括投入。`--reset` 付）

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
