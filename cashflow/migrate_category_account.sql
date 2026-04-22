-- ============================================================
--  migrate_category_account.sql
--  categories テーブルに「支払口座」カラムを追加する
--  phpMyAdmin の「SQL」タブに貼り付けて実行してください
-- ============================================================

-- account_id カラムを追加（既に存在する場合はスキップされます）
ALTER TABLE `categories`
  ADD COLUMN `account_id` INT UNSIGNED DEFAULT NULL
    COMMENT '支払口座（支出科目の出金元口座 / 収入科目の受取口座）'
    AFTER `sort_order`;

-- 外部キー制約（口座削除時は NULL にセット）
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_account`
    FOREIGN KEY (`account_id`)
    REFERENCES `bank_accounts`(`id`)
    ON DELETE SET NULL;
