-- ============================================================
--  資金繰り・預金残高管理システム  setup.sql
--  phpMyAdmin の「SQL」タブに貼り付けて実行してください
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- 1. 科目マスタ（収入/支出 の1階層のみ）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       ENUM('income','expense') NOT NULL COMMENT '収入/支出',
  `cf_section` ENUM('operating','investing','financing') NOT NULL DEFAULT 'operating'
               COMMENT 'CF区分: 営業/投資/財務',
  `name`       VARCHAR(100) NOT NULL COMMENT '科目名',
  `sort_order` SMALLINT    NOT NULL DEFAULT 0,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='科目マスタ';

-- ------------------------------------------------------------
-- 2. 資金繰り明細（予定・実績）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cashflow_entries` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `entry_date`  DATE          NOT NULL COMMENT '対象年月 (各月1日で記録)',
  `plan_amount`   DECIMAL(15,0) NOT NULL DEFAULT 0  COMMENT '予定金額',
  `actual_amount` DECIMAL(15,0)          DEFAULT NULL COMMENT '実績金額 (NULL=未入力)',
  `memo`        VARCHAR(255)             DEFAULT NULL,
  `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_date` (`category_id`, `entry_date`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='資金繰り明細';

-- ------------------------------------------------------------
-- 3. 預金口座
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL COMMENT '口座名（例: ○○銀行 普通）',
  `bank_name`  VARCHAR(100)           DEFAULT NULL,
  `sort_order` SMALLINT    NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='預金口座マスタ';

-- ------------------------------------------------------------
-- 4. 預金残高
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_balances` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`   INT UNSIGNED NOT NULL,
  `balance_date` DATE          NOT NULL COMMENT '残高確定日',
  `balance`      DECIMAL(15,0) NOT NULL DEFAULT 0,
  `memo`         VARCHAR(255)           DEFAULT NULL,
  `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acc_date` (`account_id`, `balance_date`),
  FOREIGN KEY (`account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='預金残高';

-- ------------------------------------------------------------
-- 5. アラート設定
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alert_settings` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_type` ENUM('balance_low','cashflow_negative') NOT NULL,
  `threshold`  DECIMAL(15,0) NOT NULL DEFAULT 0 COMMENT '閾値（円）',
  `label`      VARCHAR(100)           DEFAULT NULL,
  `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='アラート設定';

SET foreign_key_checks = 1;

-- ============================================================
--  初期データ
-- ============================================================

-- 科目マスタ（シンプル版サンプル）
INSERT IGNORE INTO `categories` (`id`, `type`, `cf_section`, `name`, `sort_order`) VALUES
  -- 収入
  ( 1, 'income',  'operating',  '売上収入',       10),
  ( 2, 'income',  'operating',  '売掛金回収',     20),
  ( 3, 'income',  'operating',  '雑収入',         30),
  ( 4, 'income',  'financing',  '借入収入',       40),
  ( 5, 'income',  'investing',  '資産売却収入',   50),
  -- 支出
  ( 6, 'expense', 'operating',  '仕入支出',       60),
  ( 7, 'expense', 'operating',  '給与・賞与',     70),
  ( 8, 'expense', 'operating',  '役員報酬',       80),
  ( 9, 'expense', 'operating',  '家賃・地代',     90),
  (10, 'expense', 'operating',  '水道光熱費',    100),
  (11, 'expense', 'operating',  '広告宣伝費',    110),
  (12, 'expense', 'operating',  '通信費',        120),
  (13, 'expense', 'operating',  'その他経費',    130),
  (14, 'expense', 'operating',  '源泉・社保',    140),
  (15, 'expense', 'financing',  '借入返済',      150),
  (16, 'expense', 'investing',  '設備投資',      160);

-- 預金口座（サンプル）
INSERT IGNORE INTO `bank_accounts` (`id`, `name`, `bank_name`, `sort_order`) VALUES
  (1, 'メイン口座',   '○○銀行 本店 普通', 10),
  (2, '給与振込口座', '△△銀行 支店 普通', 20);

-- アラート設定（デフォルト）
INSERT IGNORE INTO `alert_settings` (`id`, `alert_type`, `threshold`, `label`, `is_active`) VALUES
  (1, 'balance_low',        1000000, '預金残高 100万円以下', 1),
  (2, 'cashflow_negative',        0, '月次CF マイナス',      1);
