-- ============================================================
-- migration_users_email_and_notifications.sql
-- ============================================================
-- 目的:
--   1. users テーブルにゾンマネ/エリマネの通知先メアド列を追加
--   2. system_settings に商品部メアド（全店共通）を追加
--
-- 背景: 予算超過アラート（四半期）／店舗発注時の上長・商品部通知
--      の宛先を保持する。
--      エリマネ・ゾンマネ・商品部本人はシステムにログインしない前提のため、
--      ロール拡張ではなく "通知先メアドを文字列で保持" の方針を採用。
--
-- 適用日: 2026-05-23
-- ============================================================

-- 1) users にメアド列追加
ALTER TABLE users
  ADD COLUMN zone_manager_email VARCHAR(255) NULL COMMENT 'ゾーンマネージャー通知先メアド' AFTER password,
  ADD COLUMN area_manager_email VARCHAR(255) NULL COMMENT 'エリアマネージャー通知先メアド' AFTER zone_manager_email;

-- 2) system_settings に商品部メアド（全店共通）追加
INSERT INTO system_settings (`key`, `value`, description, is_active, sort_order)
VALUES (
  'product_dept_email',
  'shohinbu@example.com',
  '商品部メール通知先（全店舗共通／備品・修理・部品発注時に通知）',
  1,
  10
);
