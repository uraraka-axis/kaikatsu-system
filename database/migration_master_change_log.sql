-- ============================================================
-- マイグレーション: マスタ変更履歴テーブル (master_change_log) 作成
-- 実行日: 2026-05-21
-- 内容:
--   - マスタCRUDで追加/変更/削除された1レコード = 1行
--   - 同一アップロードで生成された行に同じ upload_batch_id (UUID) を付与
--   - change_data には before/after をJSON保存（passwordはマスク済み）
-- 用途: マスタCRUDの監査ログ (master-crud-spec.md)
-- ============================================================

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
