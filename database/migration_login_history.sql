-- ============================================================
-- ログイン履歴テーブル新規作成
-- ============================================================
-- 作成日: 2026-05-25
-- 目的:
--   ユーザーのログイン試行を記録する。成功/失敗ともに記録し、
--   system 管理者が監査ログ UI から閲覧できるようにする。
--
-- ポイント:
--   - 失敗時 user_id は NULL (ログインIDのみ記録)
--   - IP アドレス・User-Agent も記録 (将来の不正アクセス検知用)
--   - 保存期間は無制限 (運用で不要分は手動削除 or 別途バッチ)
-- ============================================================

CREATE TABLE IF NOT EXISTS login_history (
  id           INT          NOT NULL AUTO_INCREMENT COMMENT 'ID',
  user_id      INT          NULL     COMMENT '成功時のユーザーID(失敗時NULL)',
  login_id     VARCHAR(50)  NOT NULL COMMENT '入力されたログインID',
  ip_address   VARCHAR(45)  NULL     COMMENT 'IPv4/IPv6 両対応',
  user_agent   VARCHAR(500) NULL     COMMENT 'ブラウザのUser-Agent',
  success      TINYINT(1)   NOT NULL COMMENT '1=成功 / 0=失敗',
  failure_reason VARCHAR(100) NULL   COMMENT '失敗理由(invalid_password / user_not_found 等)',
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '試行日時',
  PRIMARY KEY (id),
  INDEX idx_login_history_user (user_id),
  INDEX idx_login_history_login_id (login_id),
  INDEX idx_login_history_attempted (attempted_at),
  INDEX idx_login_history_success (success),
  CONSTRAINT fk_login_history_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='ユーザーログイン履歴';
