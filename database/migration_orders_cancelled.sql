-- ============================================================
-- orders テーブル: 論理削除（取消）対応
-- 2026-05-28
-- ============================================================
-- 誤発注時の取消（status=0 依頼中のみ）に対応。
--   cancelled_at:   取消日時 (NULL=未取消)
--   cancelled_by:   取消者のユーザー名 (snapshot)
--   cancel_reason:  取消理由 (必須入力)
-- 取消発注は API レイヤで cancelled_at IS NULL でフィルタし、
-- 一覧/詳細/Excel から完全に非表示にする (履歴は DB に保持)。
-- ============================================================

ALTER TABLE orders
  ADD COLUMN cancelled_at  DATETIME      NULL DEFAULT NULL COMMENT '取消日時 (NULL=未取消)' AFTER updated_at,
  ADD COLUMN cancelled_by  VARCHAR(50)   NULL DEFAULT NULL COMMENT '取消者のユーザー名 (snapshot)' AFTER cancelled_at,
  ADD COLUMN cancel_reason TEXT          NULL DEFAULT NULL COMMENT '取消理由 (必須入力)' AFTER cancelled_by,
  ADD INDEX idx_orders_cancelled (cancelled_at);
