-- ============================================================
-- シート交換発注の追加
-- 2026-05-28
-- ============================================================
-- 修理発注と同じステータスフロー（0→1→2→3→4）を持つ「マシンシート交換」
-- 専用の発注種別を追加する。
--   - orders.type ENUM に 'seat-replacement' を追加
--   - 専用詳細テーブル order_seat_replacement_details を新設
--     （order_repair_details と同構造、コメントはシート交換用）
--   - 対応不可日時/曜日は既存の order_repair_unavail_dates / _days を流用
--     （order_id 参照で type 非依存のため重複作成しない）
--   - 発注番号 prefix: 'SHT'（例: SHT-S03-20260528-0001）
-- ============================================================

-- 1) orders.type ENUM を拡張
ALTER TABLE orders
  MODIFY COLUMN type ENUM('repair','equipment','parts','seat-replacement') NOT NULL COMMENT '発注種別';

-- 2) シート交換専用の詳細テーブル
CREATE TABLE order_seat_replacement_details (
  order_id              VARCHAR(30)  NOT NULL COMMENT '発注番号',
  equipment_name        VARCHAR(100) NOT NULL COMMENT 'マシン名・品番',
  issue                 TEXT         NOT NULL COMMENT '依頼内容（"マシンのシート交換"固定）',
  repair_schedule_date  DATE         NULL     COMMENT '作業予定日',
  repair_completed_date DATE         NULL     COMMENT '作業完了日',
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_seat_replacement_details_order FOREIGN KEY (order_id) REFERENCES orders(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='シート交換発注詳細';
