-- ============================================================
-- マイグレーション: budgets.department 正規化（ENUM→VARCHAR, all 行廃止）
-- 実行日: 2026-05-22
-- 内容:
--   1. budgets.department ENUM('all','fit','ig') → VARCHAR(20) 化
--   2. 値変換: 'fit' → 'fitness', 'ig' → 'golf'
--   3. department='all' の行は廃止（必要時は SUM で動的計算）
--   4. FK追加: budgets.department → categories.code
-- 目的:
--   - 新カテゴリ追加時にスキーマ変更不要
--   - department と categories.code の二重命名を解消
--   - all 値の更新異常リスク除去（fit/ig を変えたら all も更新せねば、を撤廃）
-- 影響: api/budgets.php, api/export/budgets.php, master系, js/budget-management.js, js/admin-menu.js
-- ============================================================

START TRANSACTION;

-- 1) ユニーク制約一旦削除（カラム型変更のため）
ALTER TABLE budgets DROP INDEX uk_budgets_shop_year_month_dept;

-- 2) ENUM → VARCHAR
ALTER TABLE budgets
  MODIFY COLUMN department VARCHAR(20) NOT NULL
    COMMENT '部門コード（categories.code を参照）';

-- 3) データ変換
UPDATE budgets SET department='fitness' WHERE department='fit';
UPDATE budgets SET department='golf'    WHERE department='ig';

-- 4) all 行削除（SUM動的計算に置き換わるため不要）
DELETE FROM budgets WHERE department='all';

-- 5) ユニーク制約を再付与
ALTER TABLE budgets
  ADD UNIQUE KEY uk_budgets_shop_year_month_dept (shop_code, fiscal_year, month, department);

-- 6) FK制約追加: categories マスタへの参照整合性
ALTER TABLE budgets
  ADD CONSTRAINT fk_budgets_category
    FOREIGN KEY (department) REFERENCES categories(code)
    ON UPDATE CASCADE;

COMMIT;
