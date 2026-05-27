-- ============================================================
-- ゾーン/エリアマネージャー ロール追加
-- ============================================================
-- 作成日: 2026-05-26
-- 目的:
--   1) users.role ENUM に 'zone' / 'area' を追加
--   2) users に zone_code / area_code カラムを追加 (NULL 許可)
--      - role='zone' のときは zone_code が管轄ゾーン
--      - role='area' のときは area_code が管轄エリア
--      - その他ロールでは原則 NULL
--
-- 設計方針:
--   - 既存の users.zone_manager_email / area_manager_email は別物
--     （shop ユーザーに紐づく通知メール先）として残す
--   - 1 ユーザー = 1 管轄（zone か area のどちらか）。
--     複数管轄を持つケースは中間テーブルではなく、複数ユーザーを
--     作って対応する運用とする
--
-- 安全性:
--   - ENUM 拡張のみ（既存値 shop/admin/system はそのまま）
--   - 既存データには影響なし（新規カラムは NULL デフォルト）
--   - 適用前に backup_users_20260526.sql を取得しておくこと
-- ============================================================

ALTER TABLE users
  MODIFY COLUMN role
    ENUM('shop','admin','system','zone','area') NOT NULL DEFAULT 'shop'
    COMMENT 'shop=店舗 / admin=商品部 / system=システム管理者 / zone=ゾーンマネージャー / area=エリアマネージャー';

ALTER TABLE users
  ADD COLUMN zone_code VARCHAR(3) NULL DEFAULT NULL
    COMMENT 'role=zone のときの管轄ゾーンコード (zones.code 参照)'
    AFTER shop_code,
  ADD COLUMN area_code VARCHAR(3) NULL DEFAULT NULL
    COMMENT 'role=area のときの管轄エリアコード (areas.code 参照)'
    AFTER zone_code;

-- 範囲フィルタの高速化用
CREATE INDEX idx_users_zone_code ON users (zone_code);
CREATE INDEX idx_users_area_code ON users (area_code);
