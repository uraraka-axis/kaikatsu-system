-- ============================================================
-- マイグレーション: products テーブルに JANコード・仕入先商品コードを追加
-- 実行日: 2026-05-21
-- 内容:
--   - jan_code: JANコード (8桁または13桁、NULL可、重複可)
--   - supplier_product_code: 仕入先商品コード (NULL可)
-- 用途: 商品マスタExcelアップロード機能 (master-crud-spec.md)
-- ============================================================

ALTER TABLE products
  ADD COLUMN jan_code VARCHAR(13) NULL
    COMMENT 'JANコード（8桁または13桁、NULL可、重複可）'
    AFTER category_code,
  ADD COLUMN supplier_product_code VARCHAR(50) NULL
    COMMENT '仕入先商品コード'
    AFTER jan_code,
  ADD INDEX idx_products_jan (jan_code),
  ADD INDEX idx_products_supplier_product (supplier_product_code);
