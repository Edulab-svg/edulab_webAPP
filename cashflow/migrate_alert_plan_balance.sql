-- ============================================================
--  アラート設定マイグレーション
--  「預金残高下限」「日次CF収支マイナス」を廃止し、
--  「予定残高 下限アラート」を新設します。
--  ※ すでにデータが存在する環境で追加実行するSQLです。
-- ============================================================

-- Step 1: ENUM に plan_balance_low を追加
ALTER TABLE alert_settings
  MODIFY COLUMN alert_type
    ENUM('balance_low', 'cashflow_negative', 'plan_balance_low') NOT NULL;

-- Step 2: 旧アラート設定を削除
DELETE FROM alert_settings
  WHERE alert_type IN ('balance_low', 'cashflow_negative');

-- Step 3: 予定残高下限アラートを挿入（存在しない場合のみ）
INSERT INTO alert_settings (alert_type, threshold, label, is_active)
SELECT 'plan_balance_low', 1000000, '予定残高 下限アラート', 1
WHERE NOT EXISTS (
  SELECT 1 FROM alert_settings WHERE alert_type = 'plan_balance_low'
);
