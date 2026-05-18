-- honbu_year_settings に「支払報酬」列を追加
-- 実行タイミング: 本番反映前に一度だけ実行すること
ALTER TABLE honbu_year_settings
  ADD COLUMN IF NOT EXISTS honor INT NOT NULL DEFAULT 0
  COMMENT '支払報酬（月額）';
