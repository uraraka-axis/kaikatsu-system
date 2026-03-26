-- ============================================================
-- 快活フロンティア 発注管理・予算管理システム
-- カラムコメント修正SQL（全22テーブル）
-- 生成日: 2026-03-26
-- ============================================================

USE kaikatsu;

-- ------------------------------------------------------------
-- 1. zones: ゾーンマスタ
-- ------------------------------------------------------------
ALTER TABLE zones MODIFY code VARCHAR(3) NOT NULL COMMENT 'ゾーンコード（100, 200 等）';
ALTER TABLE zones MODIFY name VARCHAR(50) NOT NULL COMMENT 'ゾーン名';
ALTER TABLE zones MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE zones MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 2. areas: エリアマスタ
-- ------------------------------------------------------------
ALTER TABLE areas MODIFY code VARCHAR(3) NOT NULL COMMENT 'エリアコード（101, 102, 201 等）';
ALTER TABLE areas MODIFY name VARCHAR(50) NOT NULL COMMENT 'エリア名';
ALTER TABLE areas MODIFY zone_code VARCHAR(3) NOT NULL COMMENT '所属ゾーンコード';
ALTER TABLE areas MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE areas MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 3. shops: 店舗マスタ
-- ------------------------------------------------------------
ALTER TABLE shops MODIFY code VARCHAR(5) NOT NULL COMMENT '店舗コード（10301 等）';
ALTER TABLE shops MODIFY name VARCHAR(50) NOT NULL COMMENT '店舗名';
ALTER TABLE shops MODIFY short_code VARCHAR(3) NOT NULL COMMENT '短縮コード（S01 等）※採番用';
ALTER TABLE shops MODIFY area_code VARCHAR(3) NOT NULL COMMENT '所属エリアコード';
ALTER TABLE shops MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE shops MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 4. categories: カテゴリマスタ
-- ------------------------------------------------------------
ALTER TABLE categories MODIFY code VARCHAR(20) NOT NULL COMMENT 'カテゴリコード（fitness, golf 等）';
ALTER TABLE categories MODIFY name VARCHAR(50) NOT NULL COMMENT 'カテゴリ名';
ALTER TABLE categories MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE categories MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 5. shop_categories: 店舗×カテゴリ中間テーブル
-- ------------------------------------------------------------
ALTER TABLE shop_categories MODIFY shop_code VARCHAR(5) NOT NULL COMMENT '店舗コード';
ALTER TABLE shop_categories MODIFY category_code VARCHAR(20) NOT NULL COMMENT 'カテゴリコード';

-- ------------------------------------------------------------
-- 6. suppliers: 仕入先マスタ
-- ------------------------------------------------------------
ALTER TABLE suppliers MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT '仕入先ID';
ALTER TABLE suppliers MODIFY name VARCHAR(100) NOT NULL COMMENT '仕入先名';
ALTER TABLE suppliers MODIFY code VARCHAR(20) NULL COMMENT '仕入先コード';
ALTER TABLE suppliers MODIFY contact VARCHAR(100) NULL COMMENT '担当者名';
ALTER TABLE suppliers MODIFY phone VARCHAR(20) NULL COMMENT '電話番号';
ALTER TABLE suppliers MODIFY email VARCHAR(100) NULL COMMENT 'メールアドレス';
ALTER TABLE suppliers MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE suppliers MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 7. products: 商品マスタ
-- ------------------------------------------------------------
ALTER TABLE products MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT '商品ID';
ALTER TABLE products MODIFY name VARCHAR(100) NOT NULL COMMENT '商品名';
ALTER TABLE products MODIFY code VARCHAR(20) NOT NULL COMMENT '商品コード（MAT-001 等）';
ALTER TABLE products MODIFY price INT NOT NULL COMMENT '単価（税込・円）';
ALTER TABLE products MODIFY supplier_id INT NULL COMMENT '仕入先ID';
ALTER TABLE products MODIFY category_code VARCHAR(20) NOT NULL COMMENT 'カテゴリコード';
ALTER TABLE products MODIFY recommended BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'おすすめフラグ';
ALTER TABLE products MODIFY image_path VARCHAR(255) NULL COMMENT '商品画像パス';
ALTER TABLE products MODIFY description TEXT NULL COMMENT '商品説明';
ALTER TABLE products MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE products MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 8. product_images: 商品画像
-- ------------------------------------------------------------
ALTER TABLE product_images MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE product_images MODIFY product_id INT NOT NULL COMMENT '商品ID';
ALTER TABLE product_images MODIFY file_path VARCHAR(255) NOT NULL COMMENT 'ファイルパス';
ALTER TABLE product_images MODIFY is_main BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'メイン画像フラグ';
ALTER TABLE product_images MODIFY sort_order TINYINT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 9. users: ユーザーマスタ
-- ------------------------------------------------------------
ALTER TABLE users MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ユーザーID';
ALTER TABLE users MODIFY login_id VARCHAR(50) NOT NULL COMMENT 'ログインID';
ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL COMMENT 'パスワード（ハッシュ）';
ALTER TABLE users MODIFY name VARCHAR(50) NOT NULL COMMENT 'ユーザー名';
ALTER TABLE users MODIFY role ENUM('shop','admin') NOT NULL DEFAULT 'shop' COMMENT 'ロール（shop=店舗, admin=管理者）';
ALTER TABLE users MODIFY shop_code VARCHAR(5) NULL COMMENT '所属店舗コード（管理者はNULL可）';
ALTER TABLE users MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE users MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 10. system_settings: システム設定
-- ------------------------------------------------------------
ALTER TABLE system_settings MODIFY `key` VARCHAR(50) NOT NULL COMMENT '設定キー';
ALTER TABLE system_settings MODIFY value VARCHAR(255) NOT NULL COMMENT '設定値';
ALTER TABLE system_settings MODIFY description VARCHAR(255) NULL COMMENT '設定の説明';
ALTER TABLE system_settings MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE COMMENT '有効フラグ';
ALTER TABLE system_settings MODIFY sort_order INT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 11. orders: 発注ヘッダ
-- ------------------------------------------------------------
ALTER TABLE orders MODIFY id VARCHAR(30) NOT NULL COMMENT '発注番号（REP-S01-20260301-0001 等）';
ALTER TABLE orders MODIFY type ENUM('repair','equipment','parts') NOT NULL COMMENT '発注種別';
ALTER TABLE orders MODIFY category_code VARCHAR(20) NOT NULL COMMENT 'カテゴリコード';
ALTER TABLE orders MODIFY status TINYINT NOT NULL DEFAULT 0 COMMENT 'ステータス（0:依頼中〜4:完了）';
ALTER TABLE orders MODIFY shop_code VARCHAR(5) NOT NULL COMMENT '発注元店舗コード';
ALTER TABLE orders MODIFY date DATE NOT NULL COMMENT '発注日';
ALTER TABLE orders MODIFY estimate_amount INT NULL COMMENT '見積金額';
ALTER TABLE orders MODIFY final_amount INT NULL COMMENT '最終金額';
ALTER TABLE orders MODIFY delivery_date DATE NULL COMMENT '納品予定日';
ALTER TABLE orders MODIFY actual_delivery_date DATE NULL COMMENT '実納品日（備品のみ）';
ALTER TABLE orders MODIFY created_by INT NULL COMMENT '作成者ユーザーID';

-- ------------------------------------------------------------
-- 12. order_repair_details: 修理発注詳細
-- ------------------------------------------------------------
ALTER TABLE order_repair_details MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_repair_details MODIFY equipment_name VARCHAR(100) NOT NULL COMMENT '故障機材名';
ALTER TABLE order_repair_details MODIFY issue TEXT NOT NULL COMMENT '不具合内容';
ALTER TABLE order_repair_details MODIFY repair_schedule_date DATE NULL COMMENT '修理予定日';
ALTER TABLE order_repair_details MODIFY repair_completed_date DATE NULL COMMENT '修理完了日';

-- ------------------------------------------------------------
-- 13. order_repair_unavail_dates: 修理対応不可日時
-- ------------------------------------------------------------
ALTER TABLE order_repair_unavail_dates MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE order_repair_unavail_dates MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_repair_unavail_dates MODIFY date DATE NOT NULL COMMENT '対応不可日';
ALTER TABLE order_repair_unavail_dates MODIFY is_all_day BOOLEAN NOT NULL DEFAULT TRUE COMMENT '終日かどうか';
ALTER TABLE order_repair_unavail_dates MODIFY time_start TIME NULL COMMENT '不可開始時刻';
ALTER TABLE order_repair_unavail_dates MODIFY time_end TIME NULL COMMENT '不可終了時刻';

-- ------------------------------------------------------------
-- 14. order_repair_unavail_days: 修理対応不可曜日
-- ------------------------------------------------------------
ALTER TABLE order_repair_unavail_days MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE order_repair_unavail_days MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_repair_unavail_days MODIFY day_of_week VARCHAR(10) NOT NULL COMMENT '曜日（火曜日, 木曜日 等）';

-- ------------------------------------------------------------
-- 15. order_equipment_items: 備品発注明細
-- ------------------------------------------------------------
ALTER TABLE order_equipment_items MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE order_equipment_items MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_equipment_items MODIFY product_id INT NOT NULL COMMENT '商品ID';
ALTER TABLE order_equipment_items MODIFY product_name VARCHAR(100) NOT NULL COMMENT '商品名（スナップショット）';
ALTER TABLE order_equipment_items MODIFY product_code VARCHAR(20) NOT NULL COMMENT '商品コード（スナップショット）';
ALTER TABLE order_equipment_items MODIFY price INT NOT NULL COMMENT '発注時単価（スナップショット）';
ALTER TABLE order_equipment_items MODIFY qty INT NOT NULL COMMENT '数量';
ALTER TABLE order_equipment_items MODIFY supplier VARCHAR(100) NULL COMMENT '仕入先名（スナップショット）';
ALTER TABLE order_equipment_items MODIFY arrival_date DATE NULL COMMENT '入荷予定日';

-- ------------------------------------------------------------
-- 16. order_parts_details: 部品発注詳細
-- ------------------------------------------------------------
ALTER TABLE order_parts_details MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_parts_details MODIFY parts_name VARCHAR(100) NOT NULL COMMENT '部品名・品番';
ALTER TABLE order_parts_details MODIFY target_equipment VARCHAR(100) NOT NULL COMMENT '対象機材';
ALTER TABLE order_parts_details MODIFY reason TEXT NULL COMMENT '発注理由・備考';
ALTER TABLE order_parts_details MODIFY quantity INT NOT NULL DEFAULT 1 COMMENT '数量';

-- ------------------------------------------------------------
-- 17. order_photos: 発注写真
-- ------------------------------------------------------------
ALTER TABLE order_photos MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE order_photos MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_photos MODIFY file_path VARCHAR(255) NOT NULL COMMENT 'ファイルパス';
ALTER TABLE order_photos MODIFY original_filename VARCHAR(255) NULL COMMENT '元ファイル名';
ALTER TABLE order_photos MODIFY mime_type VARCHAR(50) NULL COMMENT 'MIMEタイプ';
ALTER TABLE order_photos MODIFY file_size INT NULL COMMENT 'ファイルサイズ（バイト）';
ALTER TABLE order_photos MODIFY sort_order TINYINT NOT NULL DEFAULT 0 COMMENT '表示順';

-- ------------------------------------------------------------
-- 18. order_status_history: ステータス変更履歴
-- ------------------------------------------------------------
ALTER TABLE order_status_history MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE order_status_history MODIFY order_id VARCHAR(30) NOT NULL COMMENT '発注番号';
ALTER TABLE order_status_history MODIFY status TINYINT NOT NULL COMMENT 'ステータス（0〜4）';
ALTER TABLE order_status_history MODIFY changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '変更日時';
ALTER TABLE order_status_history MODIFY changed_by VARCHAR(50) NULL COMMENT '変更者（ユーザー名 or system）';
ALTER TABLE order_status_history MODIFY memo TEXT NULL COMMENT 'メモ';

-- ------------------------------------------------------------
-- 19. procurement_requests: 自店調達申請
-- ------------------------------------------------------------
ALTER TABLE procurement_requests MODIFY id VARCHAR(30) NOT NULL COMMENT '申請番号（REQ-S01-20260226-0001 等）';
ALTER TABLE procurement_requests MODIFY shop_code VARCHAR(5) NOT NULL COMMENT '申請店舗コード';
ALTER TABLE procurement_requests MODIFY category_code VARCHAR(20) NOT NULL COMMENT 'カテゴリコード';
ALTER TABLE procurement_requests MODIFY amount INT NOT NULL COMMENT '金額';
ALTER TABLE procurement_requests MODIFY reason TEXT NULL COMMENT '理由';
ALTER TABLE procurement_requests MODIFY date DATE NOT NULL COMMENT '申請日';
ALTER TABLE procurement_requests MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '承認ステータス';
ALTER TABLE procurement_requests MODIFY created_by INT NULL COMMENT '作成者ユーザーID';

-- ------------------------------------------------------------
-- 20. budgets: 予算
-- ------------------------------------------------------------
ALTER TABLE budgets MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE budgets MODIFY shop_code VARCHAR(5) NOT NULL COMMENT '店舗コード';
ALTER TABLE budgets MODIFY fiscal_year INT NOT NULL COMMENT '年度（2026 = 2025年4月〜2026年3月）';
ALTER TABLE budgets MODIFY month TINYINT NOT NULL COMMENT '月（1〜12）※暦月';
ALTER TABLE budgets MODIFY department ENUM('all','fit','ig') NOT NULL DEFAULT 'all' COMMENT '部門（all=全体, fit=フィットネス, ig=インドアゴルフ）';
ALTER TABLE budgets MODIFY budget_amount INT NOT NULL DEFAULT 0 COMMENT '予算額';
ALTER TABLE budgets MODIFY actual_amount INT NOT NULL DEFAULT 0 COMMENT '実績額';

-- ------------------------------------------------------------
-- 21. order_sequences: 発注番号の採番管理
-- ------------------------------------------------------------
ALTER TABLE order_sequences MODIFY prefix VARCHAR(3) NOT NULL COMMENT '種別プレフィクス（REP, EQU, PTS, REQ）';
ALTER TABLE order_sequences MODIFY shop_code VARCHAR(5) NOT NULL COMMENT '店舗コード';
ALTER TABLE order_sequences MODIFY date DATE NOT NULL COMMENT '対象日';
ALTER TABLE order_sequences MODIFY current_seq INT NOT NULL DEFAULT 0 COMMENT '現在の連番';

-- ------------------------------------------------------------
-- 22. master_scheduled_changes: マスタ予約更新
-- ------------------------------------------------------------
ALTER TABLE master_scheduled_changes MODIFY id INT NOT NULL AUTO_INCREMENT COMMENT 'ID';
ALTER TABLE master_scheduled_changes MODIFY target_table VARCHAR(50) NOT NULL COMMENT '対象テーブル名';
ALTER TABLE master_scheduled_changes MODIFY operation ENUM('insert','update','delete') NOT NULL COMMENT '操作種別';
ALTER TABLE master_scheduled_changes MODIFY record_key VARCHAR(100) NOT NULL COMMENT '対象レコードのキー';
ALTER TABLE master_scheduled_changes MODIFY change_data JSON NULL COMMENT '変更内容（JSON）';
ALTER TABLE master_scheduled_changes MODIFY scheduled_at DATETIME NOT NULL COMMENT '反映予定日時';
ALTER TABLE master_scheduled_changes MODIFY applied_at DATETIME NULL COMMENT '反映実行日時';
ALTER TABLE master_scheduled_changes MODIFY status ENUM('pending','applied','cancelled','error') NOT NULL DEFAULT 'pending' COMMENT '状態';
ALTER TABLE master_scheduled_changes MODIFY created_by_id INT NULL COMMENT '登録者ユーザーID';
