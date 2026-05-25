SET NAMES utf8mb4;

UPDATE suppliers SET email='order@fitness-japan.example.co.jp', contact='山田 太郎'   WHERE id=1;
UPDATE suppliers SET email='sales@sports-hanbai.example.co.jp', contact='佐藤 花子'   WHERE id=2;
UPDATE suppliers SET email='order@golf-supply.example.co.jp',   contact='鈴木 一郎'   WHERE id=3;
UPDATE suppliers SET email='info@linen-service.example.co.jp',  contact='高橋 次郎'   WHERE id=4;
UPDATE suppliers SET email=NULL,                                 contact='田中 三郎'   WHERE id=5;

SELECT id, name, email, contact FROM suppliers;
