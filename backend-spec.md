# バックエンド開発仕様書

快活フロンティア フィットネス／ゴルフ 発注管理・予算管理システム

最終更新: 2026-05-25

---

## 1. システム概要

店舗スタッフと本部（商品部）が使う発注管理システム。
店舗から修理・備品・部品の発注を行い、本部が承認・管理する。

### ロール

| ロール | 説明 | 識別 |
|--------|------|------|
| `shop` | 各店舗のスタッフ。発注の作成・自店の一覧閲覧 | セッション `role` |
| `admin` | 本部の商品部。全店舗の発注管理・マスタ管理 | セッション `role` |
| `system` | IT 管理者。admin 全権限 + 監査ログ閲覧 ※2026-05 追加 | セッション `role` |

権限ガードは `includes/auth.php` の `requireLogin()` / `requireAdmin()` / `requireSystem()` を使用。
`requireAdmin()` は admin と system の両方を許可する（system が admin に上位互換）。

### 画面一覧

| # | 画面 | ファイル | 店舗 | 管理者 | system | 概要 |
|---|------|---------|:----:|:------:|:------:|------|
| 1 | ログイン | login.html | o | o | o | 認証（bcrypt + login_history 記録） |
| 2 | メニュー | menu.html | o | o | o | ロール別メニュー（system は監査ログ追加） |
| 3 | 修理発注 | repair-order.html | o | - | - | 修理依頼フォーム |
| 4 | 備品発注 | equipment-order.html | o | - | - | 商品カタログからカート形式で発注 |
| 5 | 部品発注 | parts-order.html | o | - | - | 部品の個別発注フォーム |
| 6 | 発注一覧 | order-list.html | o | o | o | 一覧/詳細/ステータス管理/Excel出力/メール下書き |
| 7 | 予算管理 | budget-management.html | o | o | o | 予算消化状況・Excel出力 |
| 8 | 自店調達 | procurement-history.html | o | o | o | 自店調達申請の履歴・管理 |
| 9 | 管理メニュー | admin-menu.html | - | o | o | 7 種マスタ Excel UL/DL・予約更新・データ出力 |
| 10 | システム設定 | system-settings.html | - | o | o | カテゴリ管理・期間設定 |
| 11 | 監査ログ | master-change-log.html | - | - | o | マスタ変更/予約更新/ログイン履歴の 3 タブ |

---

## 2. データモデル（テーブル設計案）

### 2.1 マスタテーブル

#### zones（ゾーン）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(3) PK | ゾーンコード（100, 200） |
| name | VARCHAR(50) | ゾーン名（東日本, 西日本） |

#### areas（エリア）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(3) PK | エリアコード（101, 102, 201...） |
| name | VARCHAR(50) | エリア名（北海道, 東北, 関東...） |
| zone_code | VARCHAR(3) FK | 所属ゾーン |

#### shops（店舗）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(5) PK | 店舗コード（10301 等） |
| name | VARCHAR(50) | 店舗名（新宿東口 等） |
| short_code | VARCHAR(3) | 短縮コード（S01 等）※発注番号の採番に使用 |
| area_code | VARCHAR(3) FK | 所属エリア |

#### categories（カテゴリ）

| カラム | 型 | 説明 |
|--------|------|------|
| code | VARCHAR(20) PK | カテゴリコード（fitness, golf） |
| name | VARCHAR(50) | カテゴリ名（フィットネス, インドアゴルフ） |

#### products（商品マスタ）※備品発注用

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK | 商品ID |
| name | VARCHAR(100) | 商品名 |
| code | VARCHAR(20) | 商品コード（MAT-001 等） |
| price | INT | 単価（税込・円） |
| supplier | VARCHAR(100) | 仕入先名 |
| category_code | VARCHAR(20) FK | カテゴリ |
| recommended | BOOLEAN | おすすめフラグ |

#### system_settings（システム設定）

| カラム | 型 | 説明 |
|--------|------|------|
| key | VARCHAR(50) PK | 設定キー |
| value | VARCHAR(255) | 設定値 |

現在の設定値：

| key | value | 説明 |
|-----|-------|------|
| fiscal_start_month | 4 | 期の開始月（1-12） |

> 備品発注の締めルールは `categories` テーブルの `closing_type` / `closing_day` で管理する（カテゴリ別）。

### 2.2 トランザクションテーブル

#### orders（発注）

| カラム | 型 | 説明 |
|--------|------|------|
| id | VARCHAR(30) PK | 発注番号（採番ルールは後述） |
| type | ENUM('repair','equipment','parts') | 発注種別 |
| category_code | VARCHAR(20) FK | カテゴリ |
| status | TINYINT | ステータス（0〜4） |
| shop_code | VARCHAR(5) FK | 発注元店舗 |
| date | DATE | 発注日 |
| estimate_amount | INT NULL | 見積金額 |
| final_amount | INT NULL | 最終金額 |
| delivery_date | DATE NULL | 納品予定日 |
| actual_delivery_date | DATE NULL | 実納品日（備品のみ） |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

#### order_repair_details（修理発注の詳細）

| カラム | 型 | 説明 |
|--------|------|------|
| order_id | VARCHAR(30) FK PK | 発注番号 |
| equipment_name | VARCHAR(100) | 故障機材名 |
| issue | TEXT | 不具合内容 |
| repair_schedule_date | DATE NULL | 修理予定日 |
| repair_completed_date | DATE NULL | 修理完了日 |

#### order_repair_unavail_dates（修理の対応不可日時）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| date | DATE | 対応不可日 |
| is_all_day | BOOLEAN | 終日かどうか |
| time_start | TIME NULL | 不可開始時刻 |
| time_end | TIME NULL | 不可終了時刻 |

#### order_repair_unavail_days（修理の対応不可曜日）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| day_of_week | VARCHAR(10) | 曜日（火曜日, 木曜日 等） |

#### order_equipment_items（備品発注の明細行）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| product_id | INT FK | 商品ID |
| product_name | VARCHAR(100) | 商品名（スナップショット） |
| product_code | VARCHAR(20) | 商品コード（スナップショット） |
| price | INT | 発注時単価（スナップショット） |
| qty | INT | 数量 |
| supplier | VARCHAR(100) | 仕入先（スナップショット） |
| arrival_date | DATE NULL | 入荷予定日 |

#### order_parts_details（部品発注の詳細）

| カラム | 型 | 説明 |
|--------|------|------|
| order_id | VARCHAR(30) FK PK | 発注番号 |
| parts_name | VARCHAR(100) | 部品名・品番 |
| target_equipment | VARCHAR(100) | 対象機材 |
| reason | TEXT | 発注理由・備考 |
| quantity | INT | 数量 |

#### order_photos（発注写真）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| file_path | VARCHAR(255) | ファイルパス |
| sort_order | TINYINT | 表示順 |

#### order_status_history（ステータス履歴）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| order_id | VARCHAR(30) FK | 発注番号 |
| status | TINYINT | ステータス（0〜4） |
| changed_at | DATETIME | 変更日時 |
| changed_by | VARCHAR(50) | 変更者（ユーザー名 or 'system'） |
| memo | TEXT | メモ |

#### procurement_requests（自店調達申請）

| カラム | 型 | 説明 |
|--------|------|------|
| id | VARCHAR(30) PK | 申請番号（REQ-店舗コード-日付-連番） |
| shop_code | VARCHAR(5) FK | 申請店舗 |
| category_code | VARCHAR(20) FK | カテゴリ |
| amount | INT | 金額 |
| reason | TEXT | 理由 |
| date | DATE | 申請日 |
| status | VARCHAR(20) | 承認ステータス |

#### budgets（予算）

| カラム | 型 | 説明 |
|--------|------|------|
| id | INT PK AUTO | ID |
| shop_code | VARCHAR(5) FK | 店舗 |
| fiscal_year | INT | 年度（2026 = 2025年4月〜2026年3月） |
| month | TINYINT | 月（1〜12）※暦月 |
| department | ENUM('all','fit','ig') | 部門（all=全体合算） |
| budget_amount | INT | 予算額 |
| actual_amount | INT | 実績額 |

---

## 3. 発注番号の採番ルール

| 種別 | プレフィクス | 形式 | 例 |
|------|-------------|------|-----|
| 修理 | REP | REP-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | REP-S01-20260301-0001 |
| 備品 | EQU | EQU-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | EQU-S01-20260226-0001 |
| 部品 | PTS | PTS-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | PTS-S01-20260303-0001 |
| 自店調達 | REQ | REQ-{店舗短縮コード}-{YYYYMMDD}-{連番4桁} | REQ-S01-20260226-0001 |

連番は種別×店舗×日付ごとにリセット。

---

## 4. ステータスフロー

### 4.1 ステータス定義

| コード | 修理 | 備品 | 部品 |
|:------:|------|------|------|
| 0 | 依頼中 | 依頼中 | 依頼中 |
| 1 | 発注済 | 発注済 | 発注済 |
| 2 | 修理待ち | 配達中 | 配達中 |
| 3 | 修理済 | 納品済 | 納品済 |
| 4 | 完了 | 完了 | 完了 |

### 4.2 遷移ルール

```
0 (依頼中)
  │
  ├─ 管理者が手動 ──→ 1 (発注済)
  │   入力: 見積金額（必須）、予定日、メモ
  │
  └─ [備品のみ] システム自動（カテゴリ締め日到来）──→ 1 (発注済)
      見積金額 = カート合計額を自動適用

1 (発注済)
  │
  ├─ [修理] 管理者が手動 ──→ 2 (修理待ち)
  │   入力: メモ
  │
  ├─ [部品] 管理者が手動 ──→ 2 (配達中)
  │   入力: メモ
  │
  └─ [備品] システム自動（カテゴリ締め日翌日）──→ 2 (配達中)

2 (修理待ち / 配達中)
  │
  ├─ [修理] 店舗が手動 ──→ 3 (修理済)
  │   入力: 修理完了日（必須）、メモ
  │
  ├─ [備品・部品] 店舗が手動 ──→ 3 (納品済)
  │   入力: メモ
  │
  └─ [備品] システム自動（納品予定日到来）──→ 3 (納品済)

3 (修理済 / 納品済)
  │
  ├─ 管理者が手動 ──→ 4 (完了)
  │   入力: 最終金額（修理は必須、他は任意）、メモ
  │   未入力時: 最終金額 = 見積金額を自動適用
  │
  └─ [備品] システム自動（納品予定日翌日）──→ 4 (完了)
      最終金額 = 見積金額を自動適用

4 (完了)
  └─ 最終状態。変更不可。
```

### 4.3 各ステータスでの画面表示

| ステータス | 店舗画面のアクション | 管理者画面のアクション |
|-----------|---------------------|----------------------|
| 0 依頼中 | 「本部対応待ち」表示 | 「発注済にする」ボタン |
| 1 発注済 | 備品:「配達待ち」/ 他:「本部対応待ち」 | 修理:「修理待ちにする」/ 部品:「配達中にする」/ 備品:「自動遷移待ち」 |
| 2 修理待ち/配達中 | 修理:「修理完了報告」/ 備品・部品:「納品済にする」 | 「店舗の報告待ち」表示 |
| 3 修理済/納品済 | 「本部の最終確認待ち」表示 | 「完了にする」ボタン |
| 4 完了 | 「完了」表示 | 「完了」表示 |

### 4.4 対応情報の編集権限

| 条件 | 編集可否 |
|------|---------|
| ステータス0（依頼中） | 編集不可 |
| ステータス1〜3 + 管理者 | 見積金額・予定日・最終金額・メモを編集可 |
| ステータス3（修理済）+ 店舗 + 修理 | 修理完了日・メモを編集可 |
| ステータス4（完了）+ 管理者 | 最終金額・メモを編集可 |

---

## 5. ビジネスルール

### 5.1 備品発注の締め日ロジック

- 締めルールは `categories` テーブルでカテゴリ別に管理
  - `closing_type`: `none`（都度）/ `monthly`（月次）/ `weekly`（週次）
  - `closing_day`: monthly なら日(1-31)、weekly なら曜日(0=日〜6=土)
- 初期値: フィットネス備品=毎月8日、ゴルフ備品=毎週火曜日
- 締め日に、依頼中（ステータス0）の備品発注をカテゴリごとにまとめて自動発注
- 締め日の翌日に自動で「配達中」へ遷移
- 納品予定日に自動で「納品済」へ遷移
- 納品予定日の翌日に自動で「完了」へ遷移（最終金額 = 見積金額）
- カテゴリの `closing_type = 'none'` の場合は都度発注（自動バッチ対象外）

### 5.2 修理発注の対応不可日

- 店舗は発注時に「対応不可日時」と「対応不可曜日」を指定できる
- 対応不可日は複数指定可（終日 or 時間帯指定）
- 対応不可曜日は複数指定可
- 修理予定日は3営業日以上先の日付のみ指定可能

### 5.3 写真アップロード

- 修理発注・部品発注で写真添付可能
- 最大3枚まで
- プレビュー表示あり

### 5.4 予算管理

- 年度ラベル: 2026年度 = 2025年4月〜2026年3月（月度配列: 4,5,6,7,8,9,10,11,12,1,2,3）
- 部門別（全体 / フィットネス / インドアゴルフ）に予算・実績を管理
- 集計期間:
  - 当期: 年度全体（12ヶ月）
  - 期中: 四半期ベース（Q1=4〜6月, Q2=7〜9月, Q3=10〜12月, Q4=1〜3月）
  - 当月: 当該月のみ
- 消化率の色分け: 緑（60%未満）、黄（60〜90%）、赤（90%以上）
- 管理者画面: ゾーン→エリア→店舗のカスケードフィルタ、部門・年度フィルタ
- 店舗画面: 自店のデータのみ表示、部門・年度フィルタ
- デフォルト年度: 最新年度を初期選択
- 備品発注時、月次予算（50,000円）を超える場合にアラート表示

### 5.5 一括ステータス変更（管理者）

- チェックボックスで複数発注を選択
- 同一ステータスの発注をまとめて次のステータスに遷移可能
- 備品の「発注済→配達中」は自動遷移のため一括変更対象外

---

## 6. API設計案

### 6.1 認証

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| POST | /api/login | ログイン |
| POST | /api/logout | ログアウト |
| GET | /api/me | ログインユーザー情報取得 |

### 6.2 発注

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/orders.php | 発注一覧取得 | 店舗:自店のみ / 管理者:全店 |
| POST | /api/orders/create.php | 発注作成（3種別統一） | 店舗 |
| POST | /api/orders/status.php | ステータス変更 | 遷移ルールに従う |
| POST | /api/orders/update-info.php | 対応情報の編集 | 編集権限に従う |
| POST | /api/orders/bulk-status.php | 一括ステータス変更 | 管理者 |
| GET | /api/orders/draft-mails.php | ★発注メール下書き取得（仕入先別集計） | 管理者 |
| GET | /api/photo.php?id={id} | 発注写真取得 | 認証済 |

**GET /api/orders クエリパラメータ:**

| パラメータ | 型 | 説明 |
|-----------|------|------|
| type | string | 種別フィルタ（repair/equipment/parts） |
| status | int | ステータスフィルタ（0〜4） |
| category | string | カテゴリフィルタ |
| shop | string | 店舗コード（管理者のみ） |
| zone | string | ゾーンコード（管理者のみ） |
| area | string | エリアコード（管理者のみ） |
| date_from | date | 発注日From |
| date_to | date | 発注日To |

**PUT /api/orders/{id}/status リクエストボディ:**

```json
{
  "action": "order|to-delivering|repair-done|delivery-done|complete",
  "estimate_amount": 35000,
  "schedule_date": "2026-03-15",
  "final_amount": 42000,
  "repair_completed_date": "2026-02-28",
  "actual_delivery_date": "2026-02-25",
  "memo": "メモ内容"
}
```

### 6.3 商品カタログ（備品発注用）

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/products | 商品一覧取得 | 店舗 |
| GET | /api/products?category=fitness | カテゴリ絞り込み | 店舗 |

### 6.4 予算

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/budgets | 予算一覧取得 | 店舗:自店 / 管理者:全店 |
| GET | /api/budgets?shop={code}&year={year}&dept={dept} | 店舗別・年度別・部門別取得 | 同上 |

**クエリパラメータ:**

| パラメータ | 型 | 説明 |
|-----------|------|------|
| year | int | 年度（2026 等） |
| dept | string | 部門フィルタ（all/fit/ig） |
| zone | string | ゾーンコード（管理者のみ） |
| area | string | エリアコード（管理者のみ） |
| shop | string | 店舗コード |

**レスポンス例:**

```json
{
  "shop": "10301",
  "shop_name": "新宿東口",
  "zone": "100",
  "area": "103",
  "fiscal_year": 2026,
  "period": { "budget": 624000, "actual": 576100, "balance": 47900, "rate": 92.3 },
  "midterm": { "quarter": "Q4", "months": "1〜3月", "budget": 156000, "actual": 133660, "balance": 22340, "rate": 85.7 },
  "month": { "month": 3, "budget": 52000, "actual": 37000, "balance": 15000, "rate": 71.2 },
  "monthly_details": {
    "all": [
      { "month": 4, "budget": 52000, "actual": 47840, "balance": 4160, "rate": 92.0 },
      ...
    ],
    "fit": [...],
    "ig": [...]
  }
}
```

### 6.5 自店調達

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/procurement | 申請一覧取得 | 店舗:自店 / 管理者:全店 |
| POST | /api/procurement | 自店調達申請を作成 | 店舗 |

### 6.6 マスタ管理（参照）

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/master/zones.php | ゾーン一覧 | 全ロール |
| GET | /api/master/areas.php | エリア一覧 | 全ロール |
| GET | /api/master/shops.php | 店舗一覧 | 全ロール |
| GET | /api/master/categories.php | カテゴリ一覧 | 全ロール |

### 6.6.2 マスタ CRUD（Excel UL/DL）— 管理者

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| POST | /api/admin/master/zones.php | ゾーン UL（`dry_run=1` でプレビュー） |
| POST | /api/admin/master/areas.php | エリア UL |
| POST | /api/admin/master/shops.php | 店舗 UL |
| POST | /api/admin/master/suppliers.php | 仕入先 UL |
| POST | /api/admin/master/users.php | ユーザー UL（password はマスク扱い） |
| POST | /api/admin/master/products.php | 商品 UL |
| POST | /api/admin/master/budgets.php | 予算 UL（ピボット形式） |
| GET | /api/export/master/{type}.php | 現状の各マスタ DL（Excel） |
| POST | /api/admin/categories.php | カテゴリ追加/編集 |
| DELETE | /api/admin/categories.php | カテゴリ削除（使用中チェック） |
| GET/PUT | /api/admin/system-settings.php | 期間設定 |

### 6.6.3 マスタ予約更新 — 管理者

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| GET | /api/admin/scheduled-changes/list.php | 予約一覧 |
| POST | /api/admin/scheduled-changes/create.php | 予約作成（target_table/scheduled_at/change_data） |
| POST | /api/admin/scheduled-changes/cancel.php | 予約取消 |

cron バッチは `setup/apply_scheduled_changes.php`（5 分間隔想定）。

### 6.6.4 system 専用（監査ログ） — system ロールのみ

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| GET | /api/system/master-change-log.php | マスタ変更履歴 |
| GET | /api/system/master-scheduled-changes.php | マスタ予約更新一覧 |
| GET | /api/system/login-history.php | ログイン履歴 |

### 6.7 データ出力

| メソッド | エンドポイント | 説明 | 権限 |
|---------|--------------|------|------|
| GET | /api/export/orders.php | 発注データ Excel 出力（チェック行 or フィルタ） | 管理者 |
| GET | /api/export/budgets.php | 予算データ Excel 出力（年度別・部門別、月別明細付き） | 全ロール |
| GET | /api/export/master/{type}.php | マスタ Excel DL（現状データ + テンプレ） | 管理者 |

---

## 7. 自動バッチ処理

バックエンドで定期実行が必要な処理：

| 処理 | タイミング | 対象 | 動作 | 状況 |
|------|-----------|------|------|------|
| ~~備品自動発注~~ | ~~カテゴリの締め日 0:00~~ | ~~ステータス0の備品発注~~ | ~~→ ステータス1へ~~ | **対象外**（手動運用で代替） |
| 配達中自動遷移（備品のみ） | カテゴリ締め日翌日 0:00 | ステータス1の備品発注（該当カテゴリのみ） | → ステータス2へ。delivery_date 未設定なら **締め日+4日** で仮設定 | **完成** `setup/auto_advance_status.php` |
| 納品済/修理済自動遷移 | 毎日 0:00 | ステータス2かつ「予定日=当日」 | → ステータス3へ。actual_delivery_date / repair_completed_date を当日でセット（未設定時のみ） | **完成** 同上 |
| 完了自動遷移 | 毎日 0:00 | ステータス3かつ「予定日翌日=当日」 | → ステータス4へ。final_amount 未設定なら estimate_amount を適用 + 予算実績に差分反映 | **完成** 同上 |
| ~~予算実績締め処理~~ | ~~カテゴリ締め日 0:00~~ | ~~budgets テーブル~~ | ~~発注確定額を actual_amount に集計反映~~ | **設計変更**（下記） |
| マスタ予約更新の自動反映 | 5 分間隔 | master_scheduled_changes | pending を検出して反映 | **完成** `setup/apply_scheduled_changes.php` |

全ての自動遷移で `order_status_history` にレコードを追加する。
`changed_by` は `'system_batch'` とする。

### 備品自動発注を対象外とした理由

カテゴリ締め日に「依頼中の備品発注を一括で発注済化」する仕組みを当初計画していたが、以下の理由で対象外とする：

- 商品部の運用では「**実際に業者へ発注したタイミング**で `発注済` にしたい」というニーズが強い
- 締め日ピッタリに業者発注が完了するわけではないため、自動化するとシステム上のステータスと実態がずれる
- 発注一覧画面の「ステータス一括変更」＋「📧 メール下書き → この仕入先分を発注済にする」で十分対応可能

### ステータス自動遷移バッチ（`setup/auto_advance_status.php`）

#### 引数

| オプション | 用途 |
|---|---|
| `--date=YYYY-MM-DD` | 任意の日付を「当日」として実行（テスト用、省略時は今日） |
| `--dry-run` | DB 変更なしで対象だけ表示 |
| `--only=1to2,2to3,3to4` | 実行する遷移を限定（カンマ区切り） |

#### 1→2 遷移ルール（備品のみ）

| カテゴリ | closing_type | closing_day | 自動実行日 |
|---|---|---|---|
| フィットネス備品 | monthly | 8 | 毎月 9 日 |
| ゴルフ備品 | weekly | 2 (火曜) | 毎週水曜 |

`delivery_date` 未設定時は「締め日 + 4 日」で仮設定する（既存値は維持）。修理・部品の 1→2 は手動運用（`api/orders/bulk-status.php` の `action=to-delivering`）のまま。

#### 2→3 遷移ルール（全種別）

| 種別 | 予定日フィールド | 完了日の自動セット |
|---|---|---|
| 修理 | `order_repair_details.repair_schedule_date` | `repair_completed_date = 当日`（未設定時） |
| 備品 | `orders.delivery_date` | `actual_delivery_date = 当日`（未設定時） |
| 部品 | `orders.delivery_date` | （なし） |

#### 3→4 遷移ルール（全種別）

- 「予定日 + 1 = 当日」の発注を対象
- `final_amount` 未設定なら `estimate_amount` をコピー
- 既存の `applyBudgetActualDelta()` を呼んで `budgets.actual_amount` に差分を反映

### 予算実績締め処理の設計変更（Plan B / 2026-05-23）

当初は「カテゴリ締め日 0:00 のバッチで `budgets.actual_amount` を集計反映」する設計だったが、2026-05-23 から **status 遷移時にリアルタイムで反映する設計（Plan B）** に変更済。

| 設計 | 反映タイミング | 反映ロジック |
|---|---|---|
| Plan A（旧） | 締め日 0:00 のバッチ | 当日までの発注合計を actual_amount に上書き |
| **Plan B（現行）** | status 0→1、3→4 の各遷移時 | `applyBudgetActualDelta()` で差分加算 |

メリット:
- `budgets.actual_amount` が常に最新値（DB と画面表示が一致）
- 別バッチ不要、cron 不要
- 1 status 遷移 = 1 トランザクションで一貫性確保

詳細: `includes/budget.php` の `applyBudgetActualDelta()` および `api/orders/status.php` / `api/orders/bulk-status.php` 参照。

---

## 8. フロントエンド構成（参考）

```
kaikatsu-system/
├── index.html              ... ログイン画面
├── menu.html               ... メニュー
├── repair-order.html       ... 修理発注
├── equipment-order.html    ... 備品発注
├── parts-order.html        ... 部品発注
├── order-list.html         ... 発注一覧
├── budget-management.html  ... 予算管理
├── procurement-history.html ... 自店調達
├── admin-menu.html         ... 管理メニュー
├── system-settings.html    ... システム設定
├── css/
│   ├── common.css          ... 共通スタイル
│   └── [画面名].css        ... 画面別スタイル
└── js/
    ├── common-nav.js       ... ヘッダー・ナビ生成
    ├── login.js            ... ログイン処理
    └── [画面名].js         ... 画面別ロジック（サンプルデータ内包）
```

各JSファイルの先頭にサンプルデータが配列として定義されています。
バックエンド化する際は、この配列部分をAPI呼び出しに置き換えてください。
