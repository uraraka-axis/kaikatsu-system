-- ============================================================
-- マイグレーション: categoriesテーブルに締めルール追加
-- 実行日: 2026-04-29
-- 内容:
--   - closing_type: 締めタイプ（none/monthly/weekly）
--   - closing_day: 月次なら日(1-31)、週次なら曜日(0=日〜6=土)
-- 適用対象: 備品発注のみ。修理・部品は常に都度発注。
-- ============================================================

ALTER TABLE categories
  ADD COLUMN closing_type ENUM('none','monthly','weekly') NOT NULL DEFAULT 'none'
    COMMENT '締めタイプ（none=都度, monthly=月次, weekly=週次）'
    AFTER name,
  ADD COLUMN closing_day TINYINT NOT NULL DEFAULT 0
    COMMENT '月次=日(1-31), 週次=曜日(0=日,1=月,2=火,3=水,4=木,5=金,6=土)'
    AFTER closing_type;

-- 初期値設定
UPDATE categories SET closing_type = 'monthly', closing_day = 8  WHERE code = 'fitness';
UPDATE categories SET closing_type = 'weekly',  closing_day = 2  WHERE code = 'golf';

-- 不要になった旧設定を削除（カテゴリ別管理に置き換え）
DELETE FROM system_settings WHERE `key` = 'equipment_deadline_weekday';

-- 期の開始月（既存DBで未登録なら初期値4月で投入）
INSERT IGNORE INTO system_settings (`key`, value, description)
VALUES ('fiscal_start_month', '4', '期の開始月（1-12）');
