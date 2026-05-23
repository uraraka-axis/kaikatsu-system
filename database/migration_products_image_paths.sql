-- ============================================================
-- マイグレーション: products テーブルに画像2・画像3 列を追加
-- 実行日: 2026-05-23
-- 内容:
--   - image_path2: 商品画像2（ファイル名のみ、NULL可）
--   - image_path3: 商品画像3（ファイル名のみ、NULL可）
--   既存の image_path は「画像1（メイン画像）」として継続利用
-- 用途: 商品マスタExcelアップロード機能で最大3枚の画像を扱えるようにする
-- ============================================================

ALTER TABLE products
  ADD COLUMN image_path2 VARCHAR(255) NULL
    COMMENT '商品画像2 ファイル名 (uploads/products/ 配下、NULL可)'
    AFTER image_path,
  ADD COLUMN image_path3 VARCHAR(255) NULL
    COMMENT '商品画像3 ファイル名 (uploads/products/ 配下、NULL可)'
    AFTER image_path2;
