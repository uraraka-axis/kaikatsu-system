-- ============================================================
-- 快活フロンティア 発注管理システム - 発注サンプルデータ
-- js/order-list.js の storeOrders + adminOrders に対応
-- ============================================================

USE kaikatsu;

-- ============================================================
-- orders: 発注ヘッダ（全22件）
-- ============================================================

-- ===== 新宿東口店 (10301 / S01) - 修理 × 5ステータス =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('REP-S01-20260301-0001', 'repair',    'fitness', 0, '10301', '2026-03-01', NULL,  NULL,  NULL, NULL),
('REP-S01-20260225-0001', 'repair',    'fitness', 1, '10301', '2026-02-25', 35000, NULL,  NULL, NULL),
('REP-S01-20260220-0001', 'repair',    'golf',    2, '10301', '2026-02-20', 52000, NULL,  NULL, NULL),
('REP-S01-20260215-0001', 'repair',    'fitness', 3, '10301', '2026-02-15', 65000, NULL,  NULL, NULL),
('REP-S01-20260210-0001', 'repair',    'golf',    4, '10301', '2026-02-10', 45000, 42000, NULL, NULL);

-- ===== 新宿東口店 (10301 / S01) - 備品 × 5ステータス =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('EQU-S01-20260302-0001', 'equipment', 'fitness', 0, '10301', '2026-03-02', NULL,   NULL,  NULL,         NULL),
('EQU-S01-20260226-0001', 'equipment', 'golf',    1, '10301', '2026-02-26', 72000,  NULL,  '2026-03-05', NULL),
('EQU-S01-20260224-0001', 'equipment', 'fitness', 1, '10301', '2026-02-24', 324000, NULL,  '2026-03-10', NULL),
('EQU-S01-20260222-0001', 'equipment', 'fitness', 2, '10301', '2026-02-22', 5400,   NULL,  '2026-03-01', NULL),
('EQU-S01-20260218-0001', 'equipment', 'fitness', 3, '10301', '2026-02-18', 16800,  NULL,  '2026-02-25', NULL),
('EQU-S01-20260212-0001', 'equipment', 'golf',    4, '10301', '2026-02-12', 6000,   6000,  '2026-02-19', '2026-02-19');

-- ===== 新宿東口店 (10301 / S01) - 部品 × 5ステータス =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('PTS-S01-20260303-0001', 'parts', 'golf',    0, '10301', '2026-03-03', NULL,  NULL,  NULL,         NULL),
('PTS-S01-20260227-0001', 'parts', 'fitness', 1, '10301', '2026-02-27', 8500,  NULL,  '2026-03-15', NULL),
('PTS-S01-20260221-0001', 'parts', 'golf',    2, '10301', '2026-02-21', 12000, NULL,  '2026-03-10', NULL),
('PTS-S01-20260216-0001', 'parts', 'fitness', 3, '10301', '2026-02-16', 18000, NULL,  '2026-02-28', NULL),
('PTS-S01-20260211-0001', 'parts', 'golf',    4, '10301', '2026-02-11', 25000, 25000, '2026-02-20', NULL);

-- ===== 札幌店 (10101 / S04) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('REP-S04-20260223-0001', 'repair',    'fitness', 0, '10101', '2026-02-23', NULL,  NULL, NULL,         NULL),
('EQU-S04-20260224-0001', 'equipment', 'fitness', 1, '10101', '2026-02-24', 25200, NULL, '2026-03-05', NULL);

-- ===== 函館店 (10102 / S05) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('PTS-S05-20260222-0001', 'parts', 'fitness', 2, '10102', '2026-02-22', 8500, NULL, '2026-03-10', NULL);

-- ===== 池袋西口店 (10302 / S02) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('EQU-S02-20260225-0001', 'equipment', 'golf',    1, '10302', '2026-02-25', 38000, NULL, '2026-03-06', NULL),
('REP-S02-20260224-0001', 'repair',    'golf',    0, '10302', '2026-02-24', NULL,  NULL, NULL,         NULL);

-- ===== 横浜店 (10303 / S03) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('EQU-S03-20260220-0001', 'equipment', 'fitness', 3, '10303', '2026-02-20', 16800, NULL, '2026-02-28', NULL);

-- ===== 仙台店 (10201 / S06) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('REP-S06-20260219-0001', 'repair', 'fitness', 2, '10201', '2026-02-19', 65000, NULL, NULL, NULL);

-- ===== 梅田店 (20101 / S07) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('EQU-S07-20260217-0001', 'equipment', 'golf', 4, '20101', '2026-02-17', 6000, 6000, '2026-02-21', '2026-02-21'),
('PTS-S07-20260226-0001', 'parts',     'golf', 0, '20101', '2026-02-26', NULL, NULL, NULL, NULL);

-- ===== 難波店 (20102 / S08) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('REP-S08-20260226-0001', 'repair', 'fitness', 0, '20102', '2026-02-26', NULL, NULL, NULL, NULL);

-- ===== 広島店 (20201 / S09) =====

INSERT INTO orders (id, type, category_code, status, shop_code, date, estimate_amount, final_amount, delivery_date, actual_delivery_date) VALUES
('EQU-S09-20260225-0001', 'equipment', 'fitness', 1, '20201', '2026-02-25', 15000, NULL, '2026-03-06', NULL);


-- ============================================================
-- order_repair_details: 修理発注詳細
-- ============================================================

INSERT INTO order_repair_details (order_id, equipment_name, issue, repair_schedule_date, repair_completed_date) VALUES
('REP-S01-20260301-0001', 'ランニングマシン TR-800',       'ベルトが滑る。異音が発生。',       NULL,         NULL),
('REP-S01-20260225-0001', 'エアロバイク AB-200',           '液晶パネルが表示されない',         '2026-03-15', NULL),
('REP-S01-20260220-0001', 'パッティングマシン PM-300',      'モーターが回転しない',             '2026-03-05', NULL),
('REP-S01-20260215-0001', 'レッグプレス LP-400',           '油圧シリンダーから微量の漏れ',      '2026-02-28', '2026-02-28'),
('REP-S01-20260210-0001', 'ゴルフシミュレーター GS-Pro',    'プロジェクターの映像がちらつく',    '2026-02-20', '2026-02-19'),
('REP-S04-20260223-0001', 'トレッドミル TM-500',           '動作時に異音が発生',               NULL,         NULL),
('REP-S02-20260224-0001', 'ゴルフシミュレーター GS-Pro',    'プロジェクターの映像がちらつく',    NULL,         NULL),
('REP-S06-20260219-0001', 'レッグプレス LP-400',           '油圧シリンダーから微量の漏れ',      '2026-03-15', NULL),
('REP-S08-20260226-0001', 'ランニングマシン TR-900',       '速度が安定しない',                  NULL,         NULL);


-- ============================================================
-- order_repair_unavail_dates: 修理対応不可日時
-- ============================================================

-- REP-S01-20260301-0001: 2026-03-10（終日）, 2026-03-17（午前）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S01-20260301-0001', '2026-03-10', TRUE,  NULL,    NULL),
('REP-S01-20260301-0001', '2026-03-17', FALSE, '09:00', '12:00');

-- REP-S01-20260225-0001: 2026-03-05（午前）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S01-20260225-0001', '2026-03-05', FALSE, '09:00', '12:00');

-- REP-S01-20260215-0001: 2026-02-28（終日）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S01-20260215-0001', '2026-02-28', TRUE, NULL, NULL);

-- REP-S04-20260223-0001: 2026-03-07（終日）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S04-20260223-0001', '2026-03-07', TRUE, NULL, NULL);

-- REP-S02-20260224-0001: 2026-03-10（午後）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S02-20260224-0001', '2026-03-10', FALSE, '13:00', '18:00');

-- REP-S06-20260219-0001: 2026-03-20（終日）
INSERT INTO order_repair_unavail_dates (order_id, date, is_all_day, time_start, time_end) VALUES
('REP-S06-20260219-0001', '2026-03-20', TRUE, NULL, NULL);


-- ============================================================
-- order_repair_unavail_days: 修理対応不可曜日
-- ============================================================

-- REP-S01-20260301-0001: 火曜日, 木曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S01-20260301-0001', '火曜日'),
('REP-S01-20260301-0001', '木曜日');

-- REP-S01-20260220-0001: 日曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S01-20260220-0001', '日曜日');

-- REP-S01-20260210-0001: 月曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S01-20260210-0001', '月曜日');

-- REP-S04-20260223-0001: 土曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S04-20260223-0001', '土曜日');

-- REP-S02-20260224-0001: 月曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S02-20260224-0001', '月曜日');

-- REP-S08-20260226-0001: 水曜日, 金曜日
INSERT INTO order_repair_unavail_days (order_id, day_of_week) VALUES
('REP-S08-20260226-0001', '水曜日'),
('REP-S08-20260226-0001', '金曜日');


-- ============================================================
-- order_equipment_items: 備品発注明細
-- ============================================================

-- EQU-S01-20260302-0001: トレーニングマット × 5
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260302-0001', 1, 'トレーニングマット', 'MAT-001', 3500, 5, 'フィットネスジャパン', NULL);

-- EQU-S01-20260226-0001: ゴルフボール 他1商品
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260226-0001', 5, 'ゴルフボール 1ダース', 'GB-012', 4200, 10, 'ゴルフサプライ', '2026-03-05'),
('EQU-S01-20260226-0001', 7, 'グローブ Lサイズ',     'GL-L01', 1500, 20, 'ゴルフサプライ', '2026-03-05');

-- EQU-S01-20260224-0001: エアロバイク 他2商品
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260224-0001', 11, 'エアロバイク AB-300',   'AB-300', 128000, 2, 'フィットネスジャパン', '2026-03-10'),
('EQU-S01-20260224-0001', 12, 'フロアマット（大）',    'FM-L01', 12000,  4, 'フィットネスジャパン', '2026-03-10'),
('EQU-S01-20260224-0001', 13, '心拍計アームバンド',    'HR-AB1', 4000,   5, 'スポーツ用品販売',     '2026-03-10');

-- EQU-S01-20260222-0001: バランスボール × 3
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260222-0001', 3, 'バランスボール 65cm', 'BB-065', 1800, 3, 'スポーツ用品販売', '2026-03-01');

-- EQU-S01-20260218-0001: タオル（大）10枚セット × 3
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260218-0001', 8, 'タオル（大）10枚セット', 'TW-L10', 5600, 3, 'リネンサービス', '2026-02-25');

-- EQU-S01-20260212-0001: スコアカード 100枚 × 5
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S01-20260212-0001', 10, 'スコアカード 100枚', 'SC-100', 1200, 5, 'ゴルフサプライ', '2026-02-19');

-- EQU-S04-20260224-0001: ダンベルセット 10kg × 3
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S04-20260224-0001', 14, 'ダンベルセット 10kg', 'DB-010', 8400, 3, 'フィットネスジャパン', '2026-02-28');

-- EQU-S02-20260225-0001: グローブ Lサイズ 他1商品
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S02-20260225-0001', 7,  'グローブ Lサイズ',       'GL-L01', 1500, 20, 'ゴルフサプライ', '2026-02-28'),
('EQU-S02-20260225-0001', 6, 'ゴルフティー 100本入り', 'GT-100', 800,  10, 'ゴルフサプライ', '2026-02-28');

-- EQU-S03-20260220-0001: タオル（大）10枚セット × 3
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S03-20260220-0001', 8, 'タオル（大）10枚セット', 'TW-L10', 5600, 3, 'リネンサービス', '2026-02-28');

-- EQU-S07-20260217-0001: スコアカード 100枚 × 5
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S07-20260217-0001', 10, 'スコアカード 100枚', 'SC-100', 1200, 5, 'ゴルフサプライ', '2026-02-21');

-- EQU-S09-20260225-0001: ヨガマット × 10
INSERT INTO order_equipment_items (order_id, product_id, product_name, product_code, price, qty, supplier, arrival_date) VALUES
('EQU-S09-20260225-0001', 15, 'ヨガマット', 'YM-001', 1500, 10, 'フィットネスジャパン', '2026-02-28');


-- ============================================================
-- order_parts_details: 部品発注詳細
-- ============================================================

INSERT INTO order_parts_details (order_id, parts_name, target_equipment, reason, quantity) VALUES
('PTS-S01-20260303-0001', 'センサーユニット SU-100', 'スイング診断機 GST-7',     'センサー応答が遅くなっている',   1),
('PTS-S01-20260227-0001', 'ペダルユニット PD-200',   'エアロバイク AB-150',       'ペダル軸の摩耗',                 2),
('PTS-S01-20260221-0001', 'レンズユニット LC-300',    'スイングカメラ SC-200',     'レンズに傷。映像にノイズ',       1),
('PTS-S01-20260216-0001', 'ベルトユニット BT-100',    'トレッドミル TM-500',       'ベルトの摩耗が進行',             1),
('PTS-S01-20260211-0001', 'モーターユニット MU-200',  'パッティングマシン PM-300', 'モーター回転不良の予防交換',     1),
('PTS-S05-20260222-0001', 'ペダルユニット PD-200',   'エアロバイク AB-150',       'ペダル軸の摩耗',                 2),
('PTS-S07-20260226-0001', 'レンズユニット LC-300',    'スイングカメラ SC-200',     'レンズに傷。映像にノイズ',       1);


-- ============================================================
-- order_status_history: ステータス変更履歴
-- ============================================================

-- ===== REP-S01-20260301-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S01-20260301-0001', 0, '2026-03-01 09:15:00', '新宿東口店', '');

-- ===== REP-S01-20260225-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S01-20260225-0001', 0, '2026-02-25 09:00:00', '新宿東口店', ''),
('REP-S01-20260225-0001', 1, '2026-02-27 15:00:00', '商品部',     '見積もり回答あり。35,000円。修理日は3/15で調整中。');

-- ===== REP-S01-20260220-0001 (status 2) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S01-20260220-0001', 0, '2026-02-20 08:30:00', '新宿東口店', ''),
('REP-S01-20260220-0001', 1, '2026-02-23 14:00:00', '商品部',     '見積52,000円。修理予定3/5。'),
('REP-S01-20260220-0001', 2, '2026-02-25 10:00:00', '商品部',     '修理業者手配完了。3/5に訪問予定。');

-- ===== REP-S01-20260215-0001 (status 3) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S01-20260215-0001', 0, '2026-02-15 08:00:00', '新宿東口店', ''),
('REP-S01-20260215-0001', 1, '2026-02-18 14:00:00', '商品部',     '見積65,000円。2/28で修理予定。'),
('REP-S01-20260215-0001', 2, '2026-02-20 10:00:00', '商品部',     '修理業者手配完了。'),
('REP-S01-20260215-0001', 3, '2026-02-28 17:00:00', '新宿東口店', '修理完了。正常稼働を確認しました。');

-- ===== REP-S01-20260210-0001 (status 4) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S01-20260210-0001', 0, '2026-02-10 10:15:00', '新宿東口店', ''),
('REP-S01-20260210-0001', 1, '2026-02-13 11:00:00', '商品部',     '見積45,000円。2/20で修理予定。'),
('REP-S01-20260210-0001', 2, '2026-02-14 10:00:00', '商品部',     '修理業者手配完了。'),
('REP-S01-20260210-0001', 3, '2026-02-19 16:00:00', '新宿東口店', '修理完了。映像の乱れ解消を確認。'),
('REP-S01-20260210-0001', 4, '2026-02-20 10:00:00', '商品部',     '最終金額42,000円で確定。部品代差引。');

-- ===== EQU-S01-20260302-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260302-0001', 0, '2026-03-02 14:30:00', '新宿東口店', '');

-- ===== EQU-S01-20260226-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260226-0001', 0, '2026-02-26 10:00:00', '新宿東口店',             ''),
('EQU-S01-20260226-0001', 1, '2026-02-27 00:00:00', 'システム（自動:締め日）', '締め日により自動発注。見積金額: ¥72,000');

-- ===== EQU-S01-20260224-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260224-0001', 0, '2026-02-24 11:00:00', '新宿東口店',             ''),
('EQU-S01-20260224-0001', 1, '2026-02-27 00:00:00', 'システム（自動:締め日）', '締め日により自動発注。見積金額: ¥324,000');

-- ===== EQU-S01-20260222-0001 (status 2) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260222-0001', 0, '2026-02-22 10:00:00', '新宿東口店',                   ''),
('EQU-S01-20260222-0001', 1, '2026-02-23 00:00:00', 'システム（自動:締め日）',       '締め日により自動発注。'),
('EQU-S01-20260222-0001', 2, '2026-02-24 00:00:00', 'システム（自動:締め日翌日）',   '締め日翌日により自動遷移。納品予定日: 3/1');

-- ===== EQU-S01-20260218-0001 (status 3) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260218-0001', 0, '2026-02-18 09:00:00', '新宿東口店',                   ''),
('EQU-S01-20260218-0001', 1, '2026-02-19 00:00:00', 'システム（自動:締め日）',       '締め日により自動発注。'),
('EQU-S01-20260218-0001', 2, '2026-02-20 00:00:00', 'システム（自動:締め日翌日）',   '自動遷移。'),
('EQU-S01-20260218-0001', 3, '2026-02-25 00:00:00', 'システム（自動:納品予定日）',   '納品予定日により自動遷移。');

-- ===== EQU-S01-20260212-0001 (status 4) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S01-20260212-0001', 0, '2026-02-12 09:00:00', '新宿東口店',                       ''),
('EQU-S01-20260212-0001', 1, '2026-02-13 00:00:00', 'システム（自動:締め日）',           '締め日により自動発注。'),
('EQU-S01-20260212-0001', 2, '2026-02-14 00:00:00', 'システム（自動:締め日翌日）',       '自動遷移。'),
('EQU-S01-20260212-0001', 3, '2026-02-19 00:00:00', 'システム（自動:納品予定日）',       '納品予定日により自動遷移。'),
('EQU-S01-20260212-0001', 4, '2026-02-20 00:00:00', 'システム（自動:納品予定日翌日）',   '納品予定日翌日により自動完了。見積金額を最終金額に適用。');

-- ===== PTS-S01-20260303-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S01-20260303-0001', 0, '2026-03-03 11:20:00', '新宿東口店', '');

-- ===== PTS-S01-20260227-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S01-20260227-0001', 0, '2026-02-27 09:00:00', '新宿東口店', ''),
('PTS-S01-20260227-0001', 1, '2026-03-01 11:00:00', '商品部',     '8,500円で発注確定。3/15納品予定。');

-- ===== PTS-S01-20260221-0001 (status 2) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S01-20260221-0001', 0, '2026-02-21 11:00:00', '新宿東口店', ''),
('PTS-S01-20260221-0001', 1, '2026-02-24 14:00:00', '商品部',     '12,000円で発注確定。'),
('PTS-S01-20260221-0001', 2, '2026-02-26 10:00:00', '商品部',     '配達手配完了。3/10納品予定。');

-- ===== PTS-S01-20260216-0001 (status 3) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S01-20260216-0001', 0, '2026-02-16 10:00:00', '新宿東口店', ''),
('PTS-S01-20260216-0001', 1, '2026-02-19 11:00:00', '商品部',     '18,000円で発注確定。'),
('PTS-S01-20260216-0001', 2, '2026-02-21 10:00:00', '商品部',     '配達手配完了。2/28納品予定。'),
('PTS-S01-20260216-0001', 3, '2026-02-28 14:00:00', '新宿東口店', '部品受領。問題なし。');

-- ===== PTS-S01-20260211-0001 (status 4) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S01-20260211-0001', 0, '2026-02-11 09:30:00', '新宿東口店', ''),
('PTS-S01-20260211-0001', 1, '2026-02-14 10:00:00', '商品部',     '25,000円で発注確定。'),
('PTS-S01-20260211-0001', 2, '2026-02-16 10:00:00', '商品部',     '配達手配完了。2/20納品予定。'),
('PTS-S01-20260211-0001', 3, '2026-02-20 15:00:00', '新宿東口店', '部品受領。'),
('PTS-S01-20260211-0001', 4, '2026-02-21 10:00:00', '商品部',     '最終金額25,000円で確定。');

-- ===== REP-S04-20260223-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S04-20260223-0001', 0, '2026-02-23 13:00:00', '札幌店', '');

-- ===== EQU-S04-20260224-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S04-20260224-0001', 0, '2026-02-24 10:00:00', '札幌店',                   ''),
('EQU-S04-20260224-0001', 1, '2026-02-26 00:00:00', 'システム（自動:締め日）',   '締め日により自動発注。');

-- ===== PTS-S05-20260222-0001 (status 2) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S05-20260222-0001', 0, '2026-02-22 09:00:00', '函館店', ''),
('PTS-S05-20260222-0001', 1, '2026-02-23 11:00:00', '商品部', '8,500円で発注確定。'),
('PTS-S05-20260222-0001', 2, '2026-02-24 10:00:00', '商品部', '配達手配完了。3/10納品予定。');

-- ===== EQU-S02-20260225-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S02-20260225-0001', 0, '2026-02-25 08:30:00', '池袋西口店',               ''),
('EQU-S02-20260225-0001', 1, '2026-02-26 00:00:00', 'システム（自動:締め日）',   '締め日により自動発注。');

-- ===== REP-S02-20260224-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S02-20260224-0001', 0, '2026-02-24 10:15:00', '池袋西口店', '');

-- ===== EQU-S03-20260220-0001 (status 3) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S03-20260220-0001', 0, '2026-02-20 09:00:00', '横浜店',                         ''),
('EQU-S03-20260220-0001', 1, '2026-02-21 00:00:00', 'システム（自動:締め日）',         '締め日により自動発注。'),
('EQU-S03-20260220-0001', 2, '2026-02-22 00:00:00', 'システム（自動:締め日翌日）',     '自動遷移。'),
('EQU-S03-20260220-0001', 3, '2026-02-28 00:00:00', 'システム（自動:納品予定日）',     '納品予定日により自動遷移。');

-- ===== REP-S06-20260219-0001 (status 2) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S06-20260219-0001', 0, '2026-02-19 08:00:00', '仙台店', ''),
('REP-S06-20260219-0001', 1, '2026-02-23 14:00:00', '商品部', '見積65,000円。3/15で修理予定。'),
('REP-S06-20260219-0001', 2, '2026-02-24 10:00:00', '商品部', '修理業者手配完了。');

-- ===== EQU-S07-20260217-0001 (status 4) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S07-20260217-0001', 0, '2026-02-17 09:00:00', '梅田店',                             ''),
('EQU-S07-20260217-0001', 1, '2026-02-18 00:00:00', 'システム（自動:締め日）',             '締め日により自動発注。'),
('EQU-S07-20260217-0001', 2, '2026-02-19 00:00:00', 'システム（自動:締め日翌日）',         '自動遷移。'),
('EQU-S07-20260217-0001', 3, '2026-02-21 00:00:00', 'システム（自動:納品予定日）',         '納品予定日により自動遷移。'),
('EQU-S07-20260217-0001', 4, '2026-02-22 00:00:00', 'システム（自動:納品予定日翌日）',     '納品予定日翌日により自動完了。見積金額を最終金額に適用。');

-- ===== PTS-S07-20260226-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('PTS-S07-20260226-0001', 0, '2026-02-26 11:00:00', '梅田店', '');

-- ===== REP-S08-20260226-0001 (status 0) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('REP-S08-20260226-0001', 0, '2026-02-26 08:45:00', '難波店', '');

-- ===== EQU-S09-20260225-0001 (status 1) =====
INSERT INTO order_status_history (order_id, status, changed_at, changed_by, memo) VALUES
('EQU-S09-20260225-0001', 0, '2026-02-25 09:30:00', '広島店',                   ''),
('EQU-S09-20260225-0001', 1, '2026-02-26 00:00:00', 'システム（自動:締め日）',   '締め日により自動発注。');
