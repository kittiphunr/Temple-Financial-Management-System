-- แยกเงินตามวัตถุประสงค์ (กองทุน/โครงการ): ผูกรายการรายรับ-รายจ่ายกับกองทุนของวัด
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_funds.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS funds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  purpose VARCHAR(255) NULL,
  target_amount DECIMAL(14,2) NULL COMMENT 'เป้ายอด NULL = ไม่ตั้งเป้า',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_fund_name (temple_id, name),
  CONSTRAINT fk_fund_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_fund_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE transactions
  ADD COLUMN fund_id INT UNSIGNED NULL COMMENT 'กองทุน/โครงการที่รายการนี้เป็นของ NULL = เงินทั่วไป' AFTER account_id,
  ADD KEY idx_txn_fund (fund_id),
  ADD CONSTRAINT fk_txn_fund FOREIGN KEY (fund_id) REFERENCES funds(id);
