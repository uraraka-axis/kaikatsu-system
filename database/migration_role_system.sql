-- ============================================================
-- system 役割の追加 (フェーズ: system アカウント基盤)
-- ============================================================
-- 作成日: 2026-05-25
-- 目的:
--   users.role ENUM に 'system' を追加 (IT管理者/上位管理者用)
--   system は admin の上位互換 (admin の全権限 + 監査ログ閲覧等)
--
-- 安全性:
--   - ENUM 拡張のみ (既存値 shop/admin はそのまま動作)
--   - 既存データへの影響なし
--   - 適用前に backup_users_20260525.sql 取得済み
-- ============================================================

ALTER TABLE users
  MODIFY COLUMN role ENUM('shop','admin','system') NOT NULL DEFAULT 'shop'
    COMMENT 'shop=店舗ユーザー / admin=商品部 / system=システム管理者(上位)';
