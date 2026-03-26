# データベース定義書（テーブル仕様書）

**システム名:** 快活フロンティア 発注管理・予算管理システム
**作成日:** 2026-03-26
**対応DB:** MySQL 8.0

---

## 1. 概要

### データベース基本情報

| 項目 | 値 |
|------|------|
| データベース名 | `kaikatsu` |
| 文字コード | `utf8mb4` |
| 照合順序 | `utf8mb4_general_ci` |
| ストレージエンジン | InnoDB（全テーブル共通） |

### テーブル一覧

#### マスタテーブル（9テーブル）

基本情報を保持するテーブル群です。頻繁に更新されることは少なく、各種トランザクションから参照されます。

| # | テーブル名 | 日本語名 | 概要 |
|---|-----------|---------|------|
| 1 | zones | ゾーンマスタ | 東日本・西日本などの大区分 |
| 2 | areas | エリアマスタ | 北海道・東北・関東などの中区分 |
| 3 | shops | 店舗マスタ | 各店舗の基本情報 |
| 4 | categories | カテゴリマスタ | フィットネス・インドアゴルフなどの業態分類 |
| 5 | shop_categories | 店舗カテゴリ中間テーブル | 店舗とカテゴリの多対多関係 |
| 6 | suppliers | 仕入先マスタ | 備品の仕入先情報 |
| 7 | products | 商品マスタ | 備品発注用の商品カタログ |
| 8 | product_images | 商品画像 | 商品の画像（最大3枚、メイン画像フラグ付き） |
| 9 | users | ユーザーマスタ | システム利用者の情報 |
| 9 | system_settings | システム設定 | システム全体のキーバリュー設定 |

#### トランザクションテーブル（10テーブル）

日々の業務で発生するデータを記録するテーブル群です。

| # | テーブル名 | 日本語名 | 概要 |
|---|-----------|---------|------|
| 1 | orders | 発注ヘッダ | 修理・備品・部品発注の親レコード |
| 2 | order_repair_details | 修理発注詳細 | 修理発注の詳細情報（orders と 1:1） |
| 3 | order_repair_unavail_dates | 修理対応不可日時 | 修理時に対応できない日時 |
| 4 | order_repair_unavail_days | 修理対応不可曜日 | 修理時に対応できない曜日 |
| 5 | order_equipment_items | 備品発注明細 | 備品発注の商品明細行 |
| 6 | order_parts_details | 部品発注詳細 | 部品発注の詳細情報（orders と 1:1） |
| 7 | order_photos | 発注写真 | 修理・部品発注に添付する写真（最大3枚） |
| 8 | order_status_history | ステータス変更履歴 | 発注ステータスの変更ログ |
| 9 | procurement_requests | 自店調達申請 | 店舗が自ら調達する場合の申請 |
| 10 | budgets | 予算 | 店舗ごとの月次予算・実績 ※マスタ兼トランザクション |

#### ユーティリティテーブル（2テーブル）

システムの運用を支援するテーブル群です。

| # | テーブル名 | 日本語名 | 概要 |
|---|-----------|---------|------|
| 1 | order_sequences | 採番管理 | 発注番号の連番を管理 |
| 2 | master_scheduled_changes | マスタ予約更新 | CSVアップロード等による予約反映を管理 |

---

## 2. ER図（テキストベース）

```
┌──────────┐    ┌──────────┐    ┌──────────┐
│  zones   │◄──┤  areas   │◄──┤  shops   │
│  (ゾーン) │    │ (エリア)  │    │  (店舗)   │
└──────────┘    └──────────┘    └────┬─────┘
                                     │
             ┌───────────────┬───────┼───────────┬──────────────┐
             │               │       │           │              │
             ▼               ▼       ▼           ▼              ▼
      ┌────────────┐  ┌──────────┐  │   ┌────────────────┐  ┌─────────┐
      │shop_       │  │  users   │  │   │procurement_    │  │ budgets │
      │categories  │  │(ユーザー) │  │   │requests        │  │ (予算)   │
      │(店舗×ｶﾃｺﾞﾘ)│  └────┬─────┘  │   │(自店調達申請)    │  └─────────┘
      └─────┬──────┘       │        │   └────────────────┘
            │              │        │
            ▼              │        │
      ┌──────────┐         │        │
      │categories│         │        │
      │(ｶﾃｺﾞﾘ)   │◄────────┼────────┤
      └────┬─────┘         │        │
           │               │        │
           ▼               ▼        ▼
      ┌──────────┐    ┌──────────────────┐
      │ products │    │     orders       │
      │ (商品)    │    │   (発注ヘッダ)     │
      └────┬─────┘    └───────┬──────────┘
           │                  │
           │    ┌─────────────┼──────────────┬──────────────┬──────────────┐
           │    │             │              │              │              │
           │    ▼             ▼              ▼              ▼              ▼
           │ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
           │ │repair_   │ │repair_   │ │repair_   │ │parts_    │ │order_    │
           │ │details   │ │unavail_  │ │unavail_  │ │details   │ │photos    │
           │ │(修理詳細) │ │dates     │ │days      │ │(部品詳細) │ │(写真)     │
           │ └──────────┘ │(不可日時) │ │(不可曜日) │ └──────────┘ └──────────┘
           │              └──────────┘ └──────────┘
           │                  │
           │                  ▼
           │           ┌──────────────┐    ┌──────────────────────┐
           └──────────►│equipment_    │    │order_status_history  │
                       │items         │    │(ステータス変更履歴)     │◄── orders
                       │(備品明細)     │    └──────────────────────┘
                       └──────────────┘

      ┌──────────┐
      │suppliers │────────► products（仕入先 → 商品）
      │(仕入先)   │
      └──────────┘

      ┌──────────────────────┐
      │master_scheduled_     │────► users（登録者）
      │changes (予約更新)      │
      └──────────────────────┘
```

### 外部キーリレーション一覧

| 子テーブル | カラム | 参照先テーブル | 参照先カラム | ON UPDATE | ON DELETE |
|-----------|--------|--------------|------------|-----------|-----------|
| areas | zone_code | zones | code | CASCADE | RESTRICT |
| shops | area_code | areas | code | CASCADE | RESTRICT |
| shop_categories | shop_code | shops | code | CASCADE | CASCADE |
| shop_categories | category_code | categories | code | CASCADE | CASCADE |
| products | category_code | categories | code | CASCADE | RESTRICT |
| products | supplier_id | suppliers | id | CASCADE | SET NULL |
| users | shop_code | shops | code | CASCADE | SET NULL |
| orders | shop_code | shops | code | CASCADE | RESTRICT |
| orders | category_code | categories | code | CASCADE | RESTRICT |
| orders | created_by | users | id | CASCADE | SET NULL |
| order_repair_details | order_id | orders | id | CASCADE | CASCADE |
| order_repair_unavail_dates | order_id | orders | id | CASCADE | CASCADE |
| order_repair_unavail_days | order_id | orders | id | CASCADE | CASCADE |
| order_equipment_items | order_id | orders | id | CASCADE | CASCADE |
| order_equipment_items | product_id | products | id | CASCADE | RESTRICT |
| order_parts_details | order_id | orders | id | CASCADE | CASCADE |
| order_photos | order_id | orders | id | CASCADE | CASCADE |
| order_status_history | order_id | orders | id | CASCADE | CASCADE |
| procurement_requests | shop_code | shops | code | CASCADE | RESTRICT |
| procurement_requests | category_code | categories | code | CASCADE | RESTRICT |
| procurement_requests | created_by | users | id | CASCADE | SET NULL |
| budgets | shop_code | shops | code | CASCADE | RESTRICT |
| master_scheduled_changes | created_by_id | users | id | CASCADE | SET NULL |

---

## 3. 各テーブルの詳細

---

### 3.1 zones（ゾーンマスタ）

**用途:** 東日本・西日本などの大きなエリア区分を管理します。エリアマスタの上位階層にあたります。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| code | VARCHAR(3) | NO | - | ゾーンコード（100, 200 等） |
| name | VARCHAR(50) | NO | - | ゾーン名 |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `code`
- **外部キー:** なし
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** なし（主キーのみ）

---

### 3.2 areas（エリアマスタ）

**用途:** 北海道・東北・関東などの中区分を管理します。ゾーンに所属し、店舗の上位階層にあたります。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| code | VARCHAR(3) | NO | - | エリアコード（101, 102, 201 等） |
| name | VARCHAR(50) | NO | - | エリア名 |
| zone_code | VARCHAR(3) | NO | - | 所属ゾーンコード |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `code`
- **外部キー:** `zone_code` -> `zones.code`
- **インデックス:** `idx_areas_zone_code` (zone_code)
- **ユニーク制約:** なし（主キーのみ）

---

### 3.3 shops（店舗マスタ）

**用途:** 各店舗の基本情報を管理します。発注・予算管理の基本単位となるテーブルです。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| code | VARCHAR(5) | NO | - | 店舗コード（10301 等） |
| name | VARCHAR(50) | NO | - | 店舗名 |
| short_code | VARCHAR(3) | NO | - | 短縮コード（S01 等）※発注番号の採番に使用 |
| area_code | VARCHAR(3) | NO | - | 所属エリアコード |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `code`
- **外部キー:** `area_code` -> `areas.code`
- **インデックス:** `idx_shops_area_code` (area_code)
- **ユニーク制約:** `uk_shops_short_code` (short_code)

---

### 3.4 categories（カテゴリマスタ）

**用途:** フィットネス・インドアゴルフなどの業態カテゴリを管理します。商品や発注の分類に使用されます。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| code | VARCHAR(20) | NO | - | カテゴリコード（fitness, golf 等） |
| name | VARCHAR(50) | NO | - | カテゴリ名 |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `code`
- **外部キー:** なし
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** なし（主キーのみ）

---

### 3.5 shop_categories（店舗カテゴリ中間テーブル）

**用途:** 店舗とカテゴリの多対多の関係を管理します。ある店舗がどのカテゴリ（フィットネス、ゴルフ等）を扱うかを定義します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| shop_code | VARCHAR(5) | NO | - | 店舗コード |
| category_code | VARCHAR(20) | NO | - | カテゴリコード |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `(shop_code, category_code)` ※複合主キー
- **外部キー:**
  - `shop_code` -> `shops.code`
  - `category_code` -> `categories.code`
- **インデックス:** `idx_shop_categories_category` (category_code)
- **ユニーク制約:** 主キーが複合ユニーク

---

### 3.6 suppliers（仕入先マスタ）

**用途:** 備品の仕入先（取引先企業）の情報を管理します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | 仕入先ID |
| name | VARCHAR(100) | NO | - | 仕入先名 |
| code | VARCHAR(20) | YES | NULL | 仕入先コード |
| contact | VARCHAR(100) | YES | NULL | 担当者名 |
| phone | VARCHAR(20) | YES | NULL | 電話番号 |
| email | VARCHAR(100) | YES | NULL | メールアドレス |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** なし
- **インデックス:** `idx_suppliers_code` (code)
- **ユニーク制約:** なし

---

### 3.7 products（商品マスタ）

**用途:** 備品発注用の商品カタログです。店舗が備品を発注する際に、このテーブルから商品を選択します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | 商品ID |
| name | VARCHAR(100) | NO | - | 商品名 |
| code | VARCHAR(20) | NO | - | 商品コード（MAT-001 等） |
| price | INT | NO | - | 単価（税込・円） |
| supplier_id | INT | YES | NULL | 仕入先ID |
| category_code | VARCHAR(20) | NO | - | カテゴリコード |
| recommended | BOOLEAN | NO | FALSE | おすすめフラグ |
| image_path | VARCHAR(255) | YES | NULL | 商品画像パス |
| description | TEXT | YES | NULL | 商品説明 |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:**
  - `category_code` -> `categories.code`
  - `supplier_id` -> `suppliers.id`
- **インデックス:**
  - `idx_products_category` (category_code)
  - `idx_products_supplier` (supplier_id)
  - `idx_products_recommended` (recommended)
- **ユニーク制約:** `uk_products_code` (code)

---

### 3.8 users（ユーザーマスタ）

**用途:** システムにログインするユーザーの情報を管理します。店舗ユーザーと管理者の2種類のロールがあります。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ユーザーID |
| login_id | VARCHAR(50) | NO | - | ログインID |
| password | VARCHAR(255) | NO | - | パスワード（ハッシュ化して保存） |
| name | VARCHAR(50) | NO | - | ユーザー名 |
| role | ENUM('shop','admin') | NO | 'shop' | ロール（shop=店舗ユーザー, admin=管理者） |
| shop_code | VARCHAR(5) | YES | NULL | 所属店舗コード（管理者はNULL可） |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `shop_code` -> `shops.code`
- **インデックス:**
  - `idx_users_shop_code` (shop_code)
  - `idx_users_role` (role)
- **ユニーク制約:** `uk_users_login_id` (login_id)

---

### 3.9 system_settings（システム設定）

**用途:** システム全体の設定をキーバリュー形式で管理します。備品発注の締め曜日などの運用パラメータを保持します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| key | VARCHAR(50) | NO | - | 設定キー |
| value | VARCHAR(255) | NO | - | 設定値 |
| description | VARCHAR(255) | YES | NULL | 設定の説明 |
| is_active | BOOLEAN | NO | TRUE | 有効フラグ |
| sort_order | INT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `key`
- **外部キー:** なし
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** なし（主キーのみ）

#### 初期登録データ

| key | value | description |
|-----|-------|-------------|
| equipment_deadline_weekday | 3 | 備品発注の締め曜日（0=日, 1=月, 2=火, 3=水, 4=木, 5=金, 6=土） |

---

### 3.10 orders（発注ヘッダ）

**用途:** 修理・備品・部品の全発注に共通する親レコードです。発注種別ごとに子テーブル（repair_details, equipment_items, parts_details）と連携します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | VARCHAR(30) | NO | - | 発注番号（REP-S01-20260301-0001 等） |
| type | ENUM('repair','equipment','parts') | NO | - | 発注種別（repair=修理, equipment=備品, parts=部品） |
| category_code | VARCHAR(20) | NO | - | カテゴリコード |
| status | TINYINT | NO | 0 | ステータス（0:依頼中 ~ 4:完了）※詳細は補足参照 |
| shop_code | VARCHAR(5) | NO | - | 発注元店舗コード |
| date | DATE | NO | - | 発注日 |
| estimate_amount | INT | YES | NULL | 見積金額 |
| final_amount | INT | YES | NULL | 最終金額 |
| delivery_date | DATE | YES | NULL | 納品予定日 |
| actual_delivery_date | DATE | YES | NULL | 実納品日（備品のみ） |
| created_by | INT | YES | NULL | 作成者ユーザーID |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id`
- **外部キー:**
  - `shop_code` -> `shops.code`
  - `category_code` -> `categories.code`
  - `created_by` -> `users.id`
- **インデックス:**
  - `idx_orders_type` (type)
  - `idx_orders_status` (status)
  - `idx_orders_shop_code` (shop_code)
  - `idx_orders_category_code` (category_code)
  - `idx_orders_date` (date)
  - `idx_orders_created_by` (created_by)
  - `idx_orders_type_status_date` (type, status, date) ※複合インデックス
- **ユニーク制約:** なし（主キーのみ）

---

### 3.11 order_repair_details（修理発注詳細）

**用途:** 修理発注の詳細情報を保持します。orders テーブルと1対1の関係です。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| equipment_name | VARCHAR(100) | NO | - | 故障機材名 |
| issue | TEXT | NO | - | 不具合内容 |
| repair_schedule_date | DATE | YES | NULL | 修理予定日 |
| repair_completed_date | DATE | YES | NULL | 修理完了日 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `order_id`（orders.id と1:1対応）
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** なし（主キーのみ）

---

### 3.12 order_repair_unavail_dates（修理対応不可日時）

**用途:** 修理の際に店舗が対応できない日時を記録します。業者との日程調整に使用します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| date | DATE | NO | - | 対応不可日 |
| is_all_day | BOOLEAN | NO | TRUE | 終日かどうか |
| time_start | TIME | YES | NULL | 不可開始時刻（終日でない場合） |
| time_end | TIME | YES | NULL | 不可終了時刻（終日でない場合） |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:** `idx_repair_unavail_dates_order` (order_id)
- **ユニーク制約:** なし

---

### 3.13 order_repair_unavail_days（修理対応不可曜日）

**用途:** 修理の際に店舗が対応できない曜日を記録します。定休日などの定期的な不可曜日の指定に使用します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| day_of_week | VARCHAR(10) | NO | - | 曜日（火曜日, 木曜日 等） |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:** `idx_repair_unavail_days_order` (order_id)
- **ユニーク制約:** なし

---

### 3.14 order_equipment_items（備品発注明細）

**用途:** 備品発注の商品明細行です。1つの発注に対して複数の商品を登録できます。単価・商品名等は発注時点の値をスナップショットとして保持します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| product_id | INT | NO | - | 商品ID |
| product_name | VARCHAR(100) | NO | - | 商品名（発注時スナップショット） |
| product_code | VARCHAR(20) | NO | - | 商品コード（発注時スナップショット） |
| price | INT | NO | - | 発注時単価（発注時スナップショット） |
| qty | INT | NO | - | 数量 |
| supplier | VARCHAR(100) | YES | NULL | 仕入先名（発注時スナップショット） |
| arrival_date | DATE | YES | NULL | 入荷予定日 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:**
  - `order_id` -> `orders.id`
  - `product_id` -> `products.id`
- **インデックス:**
  - `idx_equipment_items_order` (order_id)
  - `idx_equipment_items_product` (product_id)
- **ユニーク制約:** なし

---

### 3.15 order_parts_details（部品発注詳細）

**用途:** 部品発注の詳細情報を保持します。orders テーブルと1対1の関係です。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| parts_name | VARCHAR(100) | NO | - | 部品名・品番 |
| target_equipment | VARCHAR(100) | NO | - | 対象機材 |
| reason | TEXT | YES | NULL | 発注理由・備考 |
| quantity | INT | NO | 1 | 数量 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `order_id`（orders.id と1:1対応）
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** なし（主キーのみ）

---

### 3.16 order_photos（発注写真）

**用途:** 修理・部品発注に添付する写真を管理します。1つの発注に対して最大3枚までの写真を登録できます。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| file_path | VARCHAR(255) | NO | - | ファイルパス |
| original_filename | VARCHAR(255) | YES | NULL | 元ファイル名 |
| mime_type | VARCHAR(50) | YES | NULL | MIMEタイプ |
| file_size | INT | YES | NULL | ファイルサイズ（バイト） |
| sort_order | TINYINT | NO | 0 | 表示順 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:** `idx_order_photos_order` (order_id)
- **ユニーク制約:** なし

---

### 3.17 order_status_history（ステータス変更履歴）

**用途:** 発注のステータスが変更されるたびに履歴を記録します。誰がいつどのステータスに変更したかを追跡できます。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| order_id | VARCHAR(30) | NO | - | 発注番号 |
| status | TINYINT | NO | - | ステータス（0~4） |
| changed_at | DATETIME | NO | CURRENT_TIMESTAMP | 変更日時 |
| changed_by | VARCHAR(50) | YES | NULL | 変更者（ユーザー名 or 'system'） |
| memo | TEXT | YES | NULL | メモ |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `order_id` -> `orders.id`
- **インデックス:**
  - `idx_status_history_order` (order_id)
  - `idx_status_history_changed_at` (changed_at)
- **ユニーク制約:** なし

> **備考:** `changed_by` は VARCHAR 型です。ユーザー名のほか、システムによる自動変更の場合は `'system'` などの文字列が格納されるため、外部キーではなく文字列で管理しています。

---

### 3.18 procurement_requests（自店調達申請）

**用途:** 店舗が自ら備品を調達する場合の申請を管理します。本部発注ではなく、店舗独自で購入する際に使用します。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | VARCHAR(30) | NO | - | 申請番号（REQ-S01-20260226-0001 等） |
| shop_code | VARCHAR(5) | NO | - | 申請店舗コード |
| category_code | VARCHAR(20) | NO | - | カテゴリコード |
| amount | INT | NO | - | 金額 |
| reason | TEXT | YES | NULL | 理由 |
| date | DATE | NO | - | 申請日 |
| status | VARCHAR(20) | NO | 'pending' | 承認ステータス |
| created_by | INT | YES | NULL | 作成者ユーザーID |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id`
- **外部キー:**
  - `shop_code` -> `shops.code`
  - `category_code` -> `categories.code`
  - `created_by` -> `users.id`
- **インデックス:**
  - `idx_procurement_shop` (shop_code)
  - `idx_procurement_category` (category_code)
  - `idx_procurement_date` (date)
  - `idx_procurement_status` (status)
  - `idx_procurement_created_by` (created_by)
- **ユニーク制約:** なし（主キーのみ）

---

### 3.19 budgets（予算）

**用途:** 店舗ごと・月ごと・部門ごとの予算額と実績額を管理します。予算管理画面で使用されます。

> **※ マスタ兼トランザクション:** 管理者が年度初めに各店舗の月次予算額（budget_amount）を登録する「予算マスタ」としての役割と、発注完了時に実績額（actual_amount）が加算されていく「トランザクション」としての役割を兼ねています。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| shop_code | VARCHAR(5) | NO | - | 店舗コード |
| fiscal_year | INT | NO | - | 年度（例: 2026 = 2025年4月~2026年3月） |
| month | TINYINT | NO | - | 月（1~12）※暦月 |
| department | ENUM('all','fit','ig') | NO | 'all' | 部門（all=全体, fit=フィットネス, ig=インドアゴルフ） |
| budget_amount | INT | NO | 0 | 予算額 |
| actual_amount | INT | NO | 0 | 実績額 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `shop_code` -> `shops.code`
- **インデックス:**
  - `idx_budgets_fiscal_year` (fiscal_year)
  - `idx_budgets_shop_code` (shop_code)
- **ユニーク制約:** `uk_budgets_shop_year_month_dept` (shop_code, fiscal_year, month, department)

> **備考:** 年度は「2026」の場合「2025年4月~2026年3月」を意味します。month は暦月（1=1月, 12=12月）で管理します。

---

### 3.20 order_sequences（採番管理）

**用途:** 発注番号・申請番号の連番を管理するテーブルです。種別プレフィクス×店舗×日付の組み合わせごとに連番をカウントアップします。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| prefix | VARCHAR(3) | NO | - | 種別プレフィクス（REP, EQU, PTS, REQ） |
| shop_code | VARCHAR(5) | NO | - | 店舗コード |
| date | DATE | NO | - | 対象日 |
| current_seq | INT | NO | 0 | 現在の連番 |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `(prefix, shop_code, date)` ※複合主キー
- **外部キー:** なし
- **インデックス:** なし（主キーのみ）
- **ユニーク制約:** 主キーが複合ユニーク

---

### 3.21 master_scheduled_changes（マスタ予約更新）

**用途:** マスタデータの予約更新を管理します。CSVアップロードによる一括変更を、指定日時に自動反映する仕組みで使用されます。

#### カラム一覧

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|----------|------|
| id | INT (AUTO_INCREMENT) | NO | 自動採番 | ID |
| target_table | VARCHAR(50) | NO | - | 対象テーブル名 |
| operation | ENUM('insert','update','delete') | NO | - | 操作種別 |
| record_key | VARCHAR(100) | NO | - | 対象レコードのキー |
| change_data | JSON | YES | NULL | 変更内容（JSON形式） |
| scheduled_at | DATETIME | NO | - | 反映予定日時 |
| applied_at | DATETIME | YES | NULL | 反映実行日時 |
| status | ENUM('pending','applied','cancelled','error') | NO | 'pending' | 状態 |
| created_by_id | INT | YES | NULL | 登録者ユーザーID |
| created_at | DATETIME | NO | CURRENT_TIMESTAMP | 作成日時 |
| updated_at | DATETIME | NO | CURRENT_TIMESTAMP (自動更新) | 更新日時 |

- **主キー:** `id` (AUTO_INCREMENT)
- **外部キー:** `created_by_id` -> `users.id`
- **インデックス:**
  - `idx_scheduled_changes_status` (status)
  - `idx_scheduled_changes_scheduled_at` (scheduled_at)
  - `idx_scheduled_changes_target` (target_table)
  - `idx_scheduled_changes_created_by` (created_by_id)
- **ユニーク制約:** なし

---

## 4. 補足

### 4.1 採番ルール（order_sequences の使い方）

発注番号・申請番号は以下のフォーマットで自動採番されます。

```
{プレフィクス}-{店舗短縮コード}-{日付(YYYYMMDD)}-{連番(4桁ゼロ埋め)}
```

#### プレフィクスの種別

| プレフィクス | 発注種別 | 例 |
|------------|---------|-----|
| REP | 修理発注 | REP-S01-20260301-0001 |
| EQU | 備品発注 | EQU-S01-20260301-0001 |
| PTS | 部品発注 | PTS-S01-20260301-0001 |
| REQ | 自店調達申請 | REQ-S01-20260226-0001 |

#### 採番の流れ

1. 新しい発注を作成する際、`order_sequences` テーブルで該当する `(prefix, shop_code, date)` のレコードを検索
2. レコードが存在しない場合は、`current_seq = 1` で新規挿入
3. レコードが存在する場合は、`current_seq` を +1 してUPDATE
4. 取得した連番を4桁ゼロ埋めして発注番号を生成

> **注意:** 店舗コード（5桁）ではなく店舗短縮コード（short_code, 3文字）が発注番号に使用されます。

---

### 4.2 予約更新の仕組み（master_scheduled_changes の使い方）

マスタデータの変更を即時反映せず、指定した日時に自動反映するための仕組みです。

#### 主な利用シーン

- CSVアップロードによるマスタデータの一括更新
- 店舗の新規追加・統廃合の予約登録
- 商品価格の改定予約

#### 処理フロー

1. **登録:** 管理者がCSVアップロードなどで変更内容を登録（status = `pending`）
2. **待機:** `scheduled_at` の日時まで待機
3. **実行:** バッチ処理が `scheduled_at` を過ぎた `pending` レコードを検出し、`change_data` の内容を対象テーブルに反映
4. **完了:** 反映成功時は status を `applied` に更新し、`applied_at` に実行日時を記録
5. **エラー:** 反映に失敗した場合は status を `error` に更新

#### ステータス遷移

| ステータス | 意味 |
|-----------|------|
| pending | 反映待ち（初期状態） |
| applied | 反映済み |
| cancelled | キャンセル済み（管理者が手動で取消） |
| error | 反映失敗 |

#### change_data の構造例

```json
{
  "name": "新しい店舗名",
  "is_active": true
}
```

対象テーブル・レコードキーで特定されるレコードに対して、JSON内のキーバリューペアで各カラムを更新します。

---

### 4.3 ステータスコードの定義（orders.status）

発注ヘッダ（orders）のステータスは TINYINT で管理され、以下の5段階で遷移します。

| コード | ステータス名 | 説明 |
|-------|------------|------|
| 0 | 依頼中 | 店舗から発注依頼が出された初期状態 |
| 1 | 手配中 | 管理者が発注を確認し、業者への手配を進めている状態 |
| 2 | 手配済 | 業者への発注が完了した状態 |
| 3 | 納品待ち | 商品・修理の完了を待っている状態 |
| 4 | 完了 | 納品または修理が完了した最終状態 |

#### ステータス遷移図

```
[0: 依頼中] → [1: 手配中] → [2: 手配済] → [3: 納品待ち] → [4: 完了]
```

#### 発注種別ごとの補足

| 発注種別 | ステータスの主な意味合い |
|---------|---------------------|
| 修理（repair） | 0:依頼中 → 1:業者調整中 → 2:訪問日確定 → 3:修理作業中 → 4:修理完了 |
| 備品（equipment） | 0:依頼中 → 1:発注準備中 → 2:発注済 → 3:入荷待ち → 4:納品完了 |
| 部品（parts） | 0:依頼中 → 1:調達手配中 → 2:発注済 → 3:入荷待ち → 4:受取完了 |

> **備考:** ステータス変更は `order_status_history` テーブルに全て履歴として記録されます。
