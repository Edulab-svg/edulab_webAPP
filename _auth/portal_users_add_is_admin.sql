-- 既存の portal_users に is_admin 列を追加（初回 1 回、phpMyAdmin 等で実行）
-- 実行後、最初の管理者にするアカウントの login_id を指定して 1 行を実行してください:
--   UPDATE portal_users SET is_admin = 1 WHERE login_id = 'あなたのID' LIMIT 1;

ALTER TABLE `portal_users`
  ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1=ユーザー管理画面にアクセス可'
  AFTER `is_active`;
