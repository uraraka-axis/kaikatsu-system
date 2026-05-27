-- ============================================================
-- マイグレーション: 未使用の product_images テーブルを削除
-- 実行日: 2026-05-27
-- 内容:
--   - 初期設計では product_images で画像複数枚を管理する想定だったが、
--     2026-05-23 の migration_products_image_paths.sql で
--     products テーブルに image_path / image_path2 / image_path3 を直接追加
--     する方式に切り替えた。
--   - 以降、product_images テーブルはアプリケーションコードから一切参照されず
--     dead table 化していたため、本マイグレーションで削除する。
--
--   実画像ファイルは uploads/products/ 配下に保管され、products.image_path[123]
--   で参照されているため本マイグレーションでは触らない。
-- ============================================================

DROP TABLE IF EXISTS product_images;
