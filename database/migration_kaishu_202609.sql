-- ============================================================
-- 2026年9月 画面改修一式 マイグレーション（Phase 0: DB基盤）
--
-- 対象: 代替ゴルフクラブ発送依頼 / マッサージチェア備品発注 /
--       マッサージチェア修理依頼 / 修理発注フォーム改修（写真枠分離・コメント欄）
--
-- 適用先: ローカル(MariaDB) / 本番(MySQL 8.0) 両対応の構文のみ使用
-- 適用方法: mysql -u <user> <db> < migration_kaishu_202609.sql
--           （本番は phpMyAdmin のSQLタブから実行。schema.sql は使わないこと）
-- ============================================================

-- ------------------------------------------------------------
-- 1) 発注種別の追加
--    club-replacement = 代替ゴルフクラブ発送依頼（プレフィクス ALT）
--    chair-equipment  = マッサージチェア備品発注（プレフィクス MCE）
--    chair-repair     = マッサージチェア修理依頼（プレフィクス MCR）
-- ------------------------------------------------------------
ALTER TABLE orders
  MODIFY COLUMN type ENUM('repair','equipment','parts','seat-replacement',
                          'club-replacement','chair-equipment','chair-repair')
  NOT NULL COMMENT '発注種別';

-- ------------------------------------------------------------
-- 2) 店舗マスタ: 連絡先3列を追加（最新参照方式）
--    代替ゴルフの報告書・チェア備品発注書PDFに出力する
-- ------------------------------------------------------------
ALTER TABLE shops
  ADD COLUMN phone       VARCHAR(20)  DEFAULT NULL COMMENT '電話番号'  AFTER area_code,
  ADD COLUMN postal_code VARCHAR(8)   DEFAULT NULL COMMENT '郵便番号'  AFTER phone,
  ADD COLUMN address     VARCHAR(200) DEFAULT NULL COMMENT '住所'      AFTER postal_code;

-- ------------------------------------------------------------
-- 3) 商品マスタ: チェア備品フラグ
--    TRUE の商品だけをチェア備品発注画面に表示する（先方指定の方式）
-- ------------------------------------------------------------
ALTER TABLE products
  ADD COLUMN is_chair_item TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'チェア備品フラグ（1=チェア備品発注画面に表示）' AFTER recommended;

ALTER TABLE products
  ADD INDEX idx_products_chair_item (is_chair_item);

-- ------------------------------------------------------------
-- 4) 発注写真: 写真種別を追加
--    damage = 故障箇所・全体写真（既存写真はすべてこちら扱い）
--    serial = シリアルナンバー写真（修理・チェア修理で必須1枚）
-- ------------------------------------------------------------
ALTER TABLE order_photos
  ADD COLUMN photo_kind VARCHAR(20) NOT NULL DEFAULT 'damage'
    COMMENT '写真種別（damage=故障箇所/全体, serial=シリアルナンバー）' AFTER order_id;

ALTER TABLE order_photos
  ADD INDEX idx_order_photos_kind (order_id, photo_kind);

-- ------------------------------------------------------------
-- 5) 修理発注詳細: コメント欄（任意）を追加
-- ------------------------------------------------------------
ALTER TABLE order_repair_details
  ADD COLUMN comment TEXT DEFAULT NULL
    COMMENT 'コメント（店舗の任意記入。備品手配不要・訪問希望など）' AFTER issue;

-- ------------------------------------------------------------
-- 6) 代替ゴルフクラブ発送依頼 詳細テーブル
--    店舗情報（電話・住所等）はスナップショットせず shops を最新参照する
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_club_replacement_details (
  order_id      VARCHAR(30) NOT NULL COMMENT '発注番号（ALT-...）',
  club          VARCHAR(30) NOT NULL COMMENT '破損クラブ（1W/3W/5UT/7UT/6AI〜9AI/PW/SW/AW/PT）',
  shaft         VARCHAR(5)  NOT NULL COMMENT 'シャフト（S/R/L）',
  damage        TEXT        NOT NULL COMMENT '破損状況',
  returned_date DATE        DEFAULT NULL COMMENT '破損クラブ返送日（完了報告時に入力）',
  report_printed_at DATETIME DEFAULT NULL COMMENT '報告書PDFを最初に出力した日時（完了報告の未印刷警告に使用）',
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_club_replacement_details_order
    FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代替ゴルフクラブ発送依頼詳細';

-- ------------------------------------------------------------
-- 7) マッサージチェア修理依頼 詳細テーブル
--    修理ライク種別（0→4の同一ステータスフロー）。
--    対応不可日時/曜日は既存 order_repair_unavail_*（order_id参照・type非依存）を流用
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_chair_repair_details (
  order_id              VARCHAR(30)  NOT NULL COMMENT '発注番号（MCR-...）',
  serial_no             VARCHAR(50)  NOT NULL COMMENT '製造番号（本体背面部ラベル）',
  applicant             VARCHAR(50)  NOT NULL COMMENT '申請者名',
  issue                 TEXT         NOT NULL COMMENT '不具合内容',
  repair_schedule_date  DATE         DEFAULT NULL COMMENT '修理予定日',
  repair_completed_date DATE         DEFAULT NULL COMMENT '修理完了日',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_chair_repair_details_order
    FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='マッサージチェア修理依頼詳細';
