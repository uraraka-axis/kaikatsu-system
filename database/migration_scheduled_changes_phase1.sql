-- ============================================================
-- マスタ予約更新 フェーズ1: master_scheduled_changes 拡張
-- ============================================================
-- 作成日: 2026-05-23
-- 目的:
--   1. cron 失敗時のエラー詳細を保存する error_message カラムを追加
--   2. 競合解決(5a)で「同一レコードの pending 予約」を高速検索するため
--      (target_table, record_key, status) の複合インデックスを追加
--
-- 安全性:
--   - DDL のみ。既存データ変更なし
--   - 既存インデックスは保持
--   - 適用前に backup_master_scheduled_20260523.sql を取得済み
-- ============================================================

-- 1) cron 失敗時のエラー詳細保存カラム
ALTER TABLE master_scheduled_changes
  ADD COLUMN error_message TEXT NULL COMMENT 'cron反映失敗時のエラー詳細' AFTER status;

-- 2) 競合解決検索用 複合インデックス
--    SELECT ... WHERE target_table=? AND record_key=? AND status='pending' FOR UPDATE
ALTER TABLE master_scheduled_changes
  ADD INDEX idx_scheduled_changes_lookup (target_table, record_key, status);
