-- xs047468_mantan 上に追加する認証専用テーブル
-- phpMyAdmin 等で 1 回実行してください

CREATE TABLE IF NOT EXISTS `portal_users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `login_id`      VARCHAR(64)      NOT NULL COMMENT 'ログインID',
  `password_hash` VARCHAR(255)     NOT NULL COMMENT 'password_hash() 結果',
  `display_name`  VARCHAR(100)     NULL DEFAULT NULL COMMENT '表示名',
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
  `is_admin`      TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1=ユーザー管理画面にアクセス可',
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_login_id` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初回ユーザーの入れ方:
-- 1) 下でハッシュを生成: php -r "echo password_hash('あなたのパス', PASSWORD_DEFAULT), PHP_EOL;"
-- 2) その文字列を password_hash に入れた INSERT（または _auth/create_user_cli.php 利用）
