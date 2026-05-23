-- ============================================================
-- 快活フロンティア 発注管理・予算管理システム
-- データベーススキーマ定義（MySQL 8.0）
-- 生成日: 2026-03-26
-- ============================================================

DROP DATABASE IF EXISTS kaikatsu;
CREATE DATABASE kaikatsu
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE kaikatsu;

-- ============================================================
-- マスタテーブル
-- ============================================================

-- ------------------------------------------------------------
-- zones: ゾーンマスタ（東日本・西日本など）
-- ------------------------------------------------------------
CREATE TABLE zones (
  code        VARCHAR(3)   NOT NULL COMMENT 'ゾーンコード（100, 200 等）',
  name        VARCHAR(50)  NOT NULL COMMENT 'ゾーン名',
  is_active   BOOLEAN      NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT          NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ゾーンマスタ';

-- ------------------------------------------------------------
-- areas: エリアマスタ（北海道・東北・関東など）
-- FK: zones
-- ------------------------------------------------------------
CREATE TABLE areas (
  code        VARCHAR(3)   NOT NULL COMMENT 'エリアコード（101, 102, 201 等）',
  name        VARCHAR(50)  NOT NULL COMMENT 'エリア名',
  zone_code   VARCHAR(3)   NOT NULL COMMENT '所属ゾーンコード',
  is_active   BOOLEAN      NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT          NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code),
  INDEX idx_areas_zone_code (zone_code),
  CONSTRAINT fk_areas_zone FOREIGN KEY (zone_code) REFERENCES zones(code)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='エリアマスタ';

-- ------------------------------------------------------------
-- shops: 店舗マスタ
-- FK: areas
-- ------------------------------------------------------------
CREATE TABLE shops (
  code        VARCHAR(5)   NOT NULL COMMENT '店舗コード（10301 等）',
  name        VARCHAR(50)  NOT NULL COMMENT '店舗名',
  short_code  VARCHAR(3)   NOT NULL COMMENT '短縮コード（S01 等）※採番用',
  area_code   VARCHAR(3)   NOT NULL COMMENT '所属エリアコード',
  is_active   BOOLEAN      NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT          NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code),
  UNIQUE INDEX uk_shops_short_code (short_code),
  INDEX idx_shops_area_code (area_code),
  CONSTRAINT fk_shops_area FOREIGN KEY (area_code) REFERENCES areas(code)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='店舗マスタ';

-- ------------------------------------------------------------
-- categories: カテゴリマスタ（フィットネス・インドアゴルフ等）
-- ------------------------------------------------------------
CREATE TABLE categories (
  code          VARCHAR(20)  NOT NULL COMMENT 'カテゴリコード（fitness, golf 等）',
  name          VARCHAR(50)  NOT NULL COMMENT 'カテゴリ名',
  closing_type  ENUM('none','monthly','weekly') NOT NULL DEFAULT 'none'
                COMMENT '締めタイプ（none=都度, monthly=月次, weekly=週次）',
  closing_day   TINYINT      NOT NULL DEFAULT 0
                COMMENT '月次=日(1-31), 週次=曜日(0=日,1=月,2=火,3=水,4=木,5=金,6=土)',
  is_active     BOOLEAN      NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order    INT          NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='カテゴリマスタ（備品発注の締めルールを保持）';

-- ------------------------------------------------------------
-- shop_categories: 店舗×カテゴリ中間テーブル（多対多）
-- FK: shops, categories
-- ------------------------------------------------------------
CREATE TABLE shop_categories (
  shop_code      VARCHAR(5)   NOT NULL COMMENT '店舗コード',
  category_code  VARCHAR(20)  NOT NULL COMMENT 'カテゴリコード',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (shop_code, category_code),
  INDEX idx_shop_categories_category (category_code),
  CONSTRAINT fk_shop_categories_shop FOREIGN KEY (shop_code) REFERENCES shops(code)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_shop_categories_category FOREIGN KEY (category_code) REFERENCES categories(code)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='店舗×カテゴリ中間テーブル';

-- ------------------------------------------------------------
-- suppliers: 仕入先マスタ
-- ------------------------------------------------------------
CREATE TABLE suppliers (
  id          INT          NOT NULL AUTO_INCREMENT COMMENT '仕入先ID',
  name        VARCHAR(100) NOT NULL COMMENT '仕入先名',
  code        VARCHAR(20)  NULL     COMMENT '仕入先コード',
  contact     VARCHAR(100) NULL     COMMENT '担当者名',
  phone       VARCHAR(20)  NULL     COMMENT '電話番号',
  email       VARCHAR(100) NULL     COMMENT 'メールアドレス',
  is_active   BOOLEAN      NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT          NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_suppliers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='仕入先マスタ';

-- ------------------------------------------------------------
-- products: 商品マスタ（備品発注用カタログ）
-- FK: categories, suppliers
-- ------------------------------------------------------------
CREATE TABLE products (
  id                    INT           NOT NULL AUTO_INCREMENT COMMENT '商品ID',
  name                  VARCHAR(100)  NOT NULL COMMENT '商品名',
  code                  VARCHAR(20)   NOT NULL COMMENT '商品コード（MAT-001 等）',
  price                 INT           NOT NULL COMMENT '単価（税込・円）',
  supplier_id           INT           NULL     COMMENT '仕入先ID',
  category_code         VARCHAR(20)   NOT NULL COMMENT 'カテゴリコード',
  jan_code              VARCHAR(13)   NULL     COMMENT 'JANコード（8桁または13桁、NULL可、重複可）',
  supplier_product_code VARCHAR(50)   NULL     COMMENT '仕入先商品コード',
  recommended           BOOLEAN       NOT NULL DEFAULT FALSE COMMENT 'おすすめフラグ',
  image_path            VARCHAR(255)  NULL     COMMENT '商品画像1（メイン）ファイル名 uploads/products/ 固定',
  image_path2           VARCHAR(255)  NULL     COMMENT '商品画像2 ファイル名',
  image_path3           VARCHAR(255)  NULL     COMMENT '商品画像3 ファイル名',
  description           TEXT          NULL     COMMENT '商品説明',
  is_active             BOOLEAN       NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order            INT           NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE INDEX uk_products_code (code),
  INDEX idx_products_category (category_code),
  INDEX idx_products_supplier (supplier_id),
  INDEX idx_products_recommended (recommended),
  INDEX idx_products_jan (jan_code),
  INDEX idx_products_supplier_product (supplier_product_code),
  CONSTRAINT fk_products_category FOREIGN KEY (category_code) REFERENCES categories(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='商品マスタ（備品発注用）';

-- ------------------------------------------------------------
-- product_images: 商品画像（最大3枚）
-- FK: products
-- ------------------------------------------------------------
CREATE TABLE product_images (
  id          INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  product_id  INT          NOT NULL COMMENT '商品ID',
  file_path   VARCHAR(255) NOT NULL COMMENT 'ファイルパス',
  is_main     BOOLEAN      NOT NULL DEFAULT FALSE COMMENT 'メイン画像フラグ',
  sort_order  TINYINT      NOT NULL DEFAULT 0 COMMENT '表示順',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_product_images_product (product_id),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='商品画像';

-- ------------------------------------------------------------
-- users: ユーザーマスタ
-- FK: shops
-- ------------------------------------------------------------
CREATE TABLE users (
  id                  INT           NOT NULL AUTO_INCREMENT COMMENT 'ユーザーID',
  login_id            VARCHAR(50)   NOT NULL COMMENT 'ログインID',
  password            VARCHAR(255)  NOT NULL COMMENT 'パスワード（ハッシュ）',
  zone_manager_email  VARCHAR(255)  NULL     COMMENT 'ゾーンマネージャー通知先メアド',
  area_manager_email  VARCHAR(255)  NULL     COMMENT 'エリアマネージャー通知先メアド',
  name        VARCHAR(50)   NOT NULL COMMENT 'ユーザー名',
  role        ENUM('shop','admin') NOT NULL DEFAULT 'shop' COMMENT 'ロール（shop=店舗, admin=管理者）',
  shop_code   VARCHAR(5)    NULL     COMMENT '所属店舗コード（管理者はNULL可）',
  is_active   BOOLEAN       NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT           NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE INDEX uk_users_login_id (login_id),
  INDEX idx_users_shop_code (shop_code),
  INDEX idx_users_role (role),
  CONSTRAINT fk_users_shop FOREIGN KEY (shop_code) REFERENCES shops(code)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ユーザーマスタ';

-- ------------------------------------------------------------
-- system_settings: システム設定（キーバリュー形式）
-- ------------------------------------------------------------
CREATE TABLE system_settings (
  `key`       VARCHAR(50)   NOT NULL COMMENT '設定キー',
  value       VARCHAR(255)  NOT NULL COMMENT '設定値',
  description VARCHAR(255)  NULL     COMMENT '設定の説明',
  is_active   BOOLEAN       NOT NULL DEFAULT TRUE  COMMENT '有効フラグ',
  sort_order  INT           NOT NULL DEFAULT 0     COMMENT '表示順',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='システム設定';

-- ============================================================
-- トランザクションテーブル
-- ============================================================

-- ------------------------------------------------------------
-- orders: 発注ヘッダ
-- FK: shops, categories, users
-- ------------------------------------------------------------
CREATE TABLE orders (
  id                    VARCHAR(30)  NOT NULL COMMENT '発注番号（REP-S01-20260301-0001 等）',
  type                  ENUM('repair','equipment','parts') NOT NULL COMMENT '発注種別',
  category_code         VARCHAR(20)  NOT NULL COMMENT 'カテゴリコード',
  status                TINYINT      NOT NULL DEFAULT 0 COMMENT 'ステータス（0:依頼中〜4:完了）',
  shop_code             VARCHAR(5)   NOT NULL COMMENT '発注元店舗コード',
  date                  DATE         NOT NULL COMMENT '発注日',
  estimate_amount       INT          NULL     COMMENT '見積金額',
  final_amount          INT          NULL     COMMENT '最終金額',
  delivery_date         DATE         NULL     COMMENT '納品予定日',
  actual_delivery_date  DATE         NULL     COMMENT '実納品日（備品のみ）',
  created_by            INT          NULL     COMMENT '作成者ユーザーID',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_orders_type (type),
  INDEX idx_orders_status (status),
  INDEX idx_orders_shop_code (shop_code),
  INDEX idx_orders_category_code (category_code),
  INDEX idx_orders_date (date),
  INDEX idx_orders_created_by (created_by),
  INDEX idx_orders_type_status_date (type, status, date),
  CONSTRAINT fk_orders_shop FOREIGN KEY (shop_code) REFERENCES shops(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_orders_category FOREIGN KEY (category_code) REFERENCES categories(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='発注ヘッダ';

-- ------------------------------------------------------------
-- order_repair_details: 修理発注の詳細（1:1）
-- FK: orders
-- ------------------------------------------------------------
CREATE TABLE order_repair_details (
  order_id              VARCHAR(30)  NOT NULL COMMENT '発注番号',
  equipment_name        VARCHAR(100) NOT NULL COMMENT '故障機材名',
  issue                 TEXT         NOT NULL COMMENT '不具合内容',
  repair_schedule_date  DATE         NULL     COMMENT '修理予定日',
  repair_completed_date DATE         NULL     COMMENT '修理完了日',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_repair_details_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='修理発注詳細';

-- ------------------------------------------------------------
-- order_repair_unavail_dates: 修理の対応不可日時
-- FK: orders
-- ------------------------------------------------------------
CREATE TABLE order_repair_unavail_dates (
  id          INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  order_id    VARCHAR(30)  NOT NULL COMMENT '発注番号',
  date        DATE         NOT NULL COMMENT '対応不可日',
  is_all_day  BOOLEAN      NOT NULL DEFAULT TRUE COMMENT '終日かどうか',
  time_start  TIME         NULL     COMMENT '不可開始時刻',
  time_end    TIME         NULL     COMMENT '不可終了時刻',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_repair_unavail_dates_order (order_id),
  CONSTRAINT fk_repair_unavail_dates_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='修理対応不可日時';

-- ------------------------------------------------------------
-- order_repair_unavail_days: 修理の対応不可曜日
-- FK: orders
-- ------------------------------------------------------------
CREATE TABLE order_repair_unavail_days (
  id           INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  order_id     VARCHAR(30)  NOT NULL COMMENT '発注番号',
  day_of_week  VARCHAR(10)  NOT NULL COMMENT '曜日（火曜日, 木曜日 等）',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_repair_unavail_days_order (order_id),
  CONSTRAINT fk_repair_unavail_days_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='修理対応不可曜日';

-- ------------------------------------------------------------
-- order_equipment_items: 備品発注の明細行
-- FK: orders, products
-- ------------------------------------------------------------
CREATE TABLE order_equipment_items (
  id            INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  order_id      VARCHAR(30)  NOT NULL COMMENT '発注番号',
  product_id    INT          NOT NULL COMMENT '商品ID',
  product_name  VARCHAR(100) NOT NULL COMMENT '商品名（スナップショット）',
  product_code  VARCHAR(20)  NOT NULL COMMENT '商品コード（スナップショット）',
  price         INT          NOT NULL COMMENT '発注時単価（スナップショット）',
  qty           INT          NOT NULL COMMENT '数量',
  supplier      VARCHAR(100) NULL     COMMENT '仕入先名（スナップショット）',
  arrival_date  DATE         NULL     COMMENT '入荷予定日',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_equipment_items_order (order_id),
  INDEX idx_equipment_items_product (product_id),
  CONSTRAINT fk_equipment_items_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_equipment_items_product FOREIGN KEY (product_id) REFERENCES products(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='備品発注明細';

-- ------------------------------------------------------------
-- order_parts_details: 部品発注の詳細（1:1）
-- FK: orders
-- ------------------------------------------------------------
CREATE TABLE order_parts_details (
  order_id          VARCHAR(30)  NOT NULL COMMENT '発注番号',
  parts_name        VARCHAR(100) NOT NULL COMMENT '部品名・品番',
  target_equipment  VARCHAR(100) NOT NULL COMMENT '対象機材',
  reason            TEXT         NULL     COMMENT '発注理由・備考',
  quantity          INT          NOT NULL DEFAULT 1 COMMENT '数量',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_parts_details_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='部品発注詳細';

-- ------------------------------------------------------------
-- order_photos: 発注写真（修理・部品で使用、最大3枚）
-- FK: orders
-- ------------------------------------------------------------
CREATE TABLE order_photos (
  id                INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  order_id          VARCHAR(30)  NOT NULL COMMENT '発注番号',
  file_path         VARCHAR(255) NOT NULL COMMENT 'ファイルパス',
  original_filename VARCHAR(255) NULL     COMMENT '元ファイル名',
  mime_type         VARCHAR(50)  NULL     COMMENT 'MIMEタイプ',
  file_size         INT          NULL     COMMENT 'ファイルサイズ（バイト）',
  sort_order        TINYINT      NOT NULL DEFAULT 0 COMMENT '表示順',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_order_photos_order (order_id),
  CONSTRAINT fk_order_photos_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='発注写真';

-- ------------------------------------------------------------
-- order_status_history: ステータス変更履歴
-- FK: orders
-- changed_by は VARCHAR（'system' 等の文字列も格納するため）
-- ------------------------------------------------------------
CREATE TABLE order_status_history (
  id          INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  order_id    VARCHAR(30)  NOT NULL COMMENT '発注番号',
  status      TINYINT      NOT NULL COMMENT 'ステータス（0〜4）',
  changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '変更日時',
  changed_by  VARCHAR(50)  NULL     COMMENT '変更者（ユーザー名 or system）',
  memo        TEXT         NULL     COMMENT 'メモ',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_status_history_order (order_id),
  INDEX idx_status_history_changed_at (changed_at),
  CONSTRAINT fk_status_history_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ステータス変更履歴';

-- ------------------------------------------------------------
-- procurement_requests: 自店調達申請
-- FK: shops, categories, users
-- ------------------------------------------------------------
CREATE TABLE procurement_requests (
  id             VARCHAR(30)  NOT NULL COMMENT '申請番号（REQ-S01-20260226-0001 等）',
  shop_code      VARCHAR(5)   NOT NULL COMMENT '申請店舗コード',
  category_code  VARCHAR(20)  NOT NULL COMMENT 'カテゴリコード',
  amount         INT          NOT NULL COMMENT '金額',
  reason         TEXT         NULL     COMMENT '理由',
  date           DATE         NOT NULL COMMENT '申請日',
  status         VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT '承認ステータス',
  created_by     INT          NULL     COMMENT '作成者ユーザーID',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_procurement_shop (shop_code),
  INDEX idx_procurement_category (category_code),
  INDEX idx_procurement_date (date),
  INDEX idx_procurement_status (status),
  INDEX idx_procurement_created_by (created_by),
  CONSTRAINT fk_procurement_shop FOREIGN KEY (shop_code) REFERENCES shops(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_procurement_category FOREIGN KEY (category_code) REFERENCES categories(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_procurement_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='自店調達申請';

-- ------------------------------------------------------------
-- budgets: 予算（店舗×年度×月×部門）
-- FK: shops
-- ------------------------------------------------------------
CREATE TABLE budgets (
  id             INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  shop_code      VARCHAR(5)   NOT NULL COMMENT '店舗コード',
  fiscal_year    INT          NOT NULL COMMENT '年度（2026 = 2026年4月〜2027年3月）',
  month          TINYINT      NOT NULL COMMENT '月（1〜12）※暦月',
  department     VARCHAR(20)  NOT NULL COMMENT '部門コード（categories.code を参照）。「全体」は SUM で動的計算するため行を持たない',
  budget_amount  INT          NOT NULL DEFAULT 0 COMMENT '予算額',
  actual_amount  INT          NOT NULL DEFAULT 0 COMMENT '実績額',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE INDEX uk_budgets_shop_year_month_dept (shop_code, fiscal_year, month, department),
  INDEX idx_budgets_fiscal_year (fiscal_year),
  INDEX idx_budgets_shop_code (shop_code),
  CONSTRAINT fk_budgets_shop FOREIGN KEY (shop_code) REFERENCES shops(code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_budgets_category FOREIGN KEY (department) REFERENCES categories(code)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='予算（店舗×年度×月×部門コード）';

-- ============================================================
-- ユーティリティテーブル
-- ============================================================

-- ------------------------------------------------------------
-- order_sequences: 発注番号の採番管理
-- 種別×店舗×日付ごとに連番を管理
-- ------------------------------------------------------------
CREATE TABLE order_sequences (
  prefix      VARCHAR(3)  NOT NULL COMMENT '種別プレフィクス（REP, EQU, PTS, REQ）',
  shop_code   VARCHAR(5)  NOT NULL COMMENT '店舗コード',
  date        DATE        NOT NULL COMMENT '対象日',
  current_seq INT         NOT NULL DEFAULT 0 COMMENT '現在の連番',
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (prefix, shop_code, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='発注番号の採番管理';

-- ------------------------------------------------------------
-- master_scheduled_changes: マスタ予約更新
-- CSVアップロード時の予約反映などに使用
-- FK: users
-- ------------------------------------------------------------
CREATE TABLE master_scheduled_changes (
  id              INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  target_table    VARCHAR(50)  NOT NULL COMMENT '対象テーブル名',
  operation       ENUM('insert','update','delete') NOT NULL COMMENT '操作種別',
  record_key      VARCHAR(100) NOT NULL COMMENT '対象レコードのキー',
  change_data     JSON         NULL     COMMENT '変更内容（JSON）',
  scheduled_at    DATETIME     NOT NULL COMMENT '反映予定日時',
  applied_at      DATETIME     NULL     COMMENT '反映実行日時',
  status          ENUM('pending','applied','cancelled','error') NOT NULL DEFAULT 'pending' COMMENT '状態',
  created_by_id   INT          NULL     COMMENT '登録者ユーザーID',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_scheduled_changes_status (status),
  INDEX idx_scheduled_changes_scheduled_at (scheduled_at),
  INDEX idx_scheduled_changes_target (target_table),
  INDEX idx_scheduled_changes_created_by (created_by_id),
  CONSTRAINT fk_scheduled_changes_user FOREIGN KEY (created_by_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='マスタ予約更新';

-- ------------------------------------------------------------
-- master_change_log: マスタ変更履歴
-- FK: users
-- ------------------------------------------------------------
CREATE TABLE master_change_log (
  id               INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  target_table     VARCHAR(50)  NOT NULL COMMENT '対象テーブル名 (zones/areas/shops/suppliers/users/products)',
  operation        ENUM('insert','update','delete') NOT NULL COMMENT '操作種別',
  record_key       VARCHAR(100) NOT NULL COMMENT '対象レコードのキー（code, login_id等）',
  change_data      JSON         NULL     COMMENT '変更内容（before/afterのJSON、passwordはマスク）',
  changed_by_id    INT          NULL     COMMENT '変更者ユーザーID',
  changed_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '変更日時',
  upload_filename  VARCHAR(255) NULL     COMMENT 'アップロード元ファイル名',
  upload_batch_id  VARCHAR(40)  NULL     COMMENT '同一アップロードのバッチID（UUID）',
  PRIMARY KEY (id),
  INDEX idx_master_change_log_table (target_table),
  INDEX idx_master_change_log_changed_at (changed_at),
  INDEX idx_master_change_log_changed_by (changed_by_id),
  INDEX idx_master_change_log_batch (upload_batch_id),
  CONSTRAINT fk_master_change_log_user FOREIGN KEY (changed_by_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='マスタ変更履歴';

-- ============================================================
-- 初期データ投入
-- ============================================================

-- システム設定: 期の開始月（初期値: 4月）
INSERT INTO system_settings (`key`, value, description)
VALUES ('fiscal_start_month', '4', '期の開始月（1-12）');
-- 備品発注の締めルールは categories.closing_type / closing_day で管理（カテゴリ別）
