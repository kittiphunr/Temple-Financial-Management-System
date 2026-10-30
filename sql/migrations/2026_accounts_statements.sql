-- บัญชีเงินสด/ธนาคาร, ยอดยกมา, รายการโอน/ฝาก-ถอนระหว่างบัญชี สำหรับรายงานแบบที่ 1-3 (เงินสด / เงินฝากธนาคาร / เงินคงเหลือ)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_accounts_statements.sql
-- รายการเดิมทั้งหมดถือเป็น "เงินสด" (ระบบสร้างบัญชีเงินสดให้วัดที่มีรายการ) — ยอดยกมาให้วัดกรอกที่ บัญชีเงินสด/ธนาคาร
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE categories ADD COLUMN is_transfer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_donation;
INSERT IGNORE INTO categories (type, name, sort_order, is_donation, is_transfer) VALUES
('income','รับโอน/ถอนจากบัญชีอื่น (ระบบ)',90,0,1),
('expense','โอน/นำฝากไปบัญชีอื่น (ระบบ)',90,0,1);

CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  kind ENUM('cash','bank') NOT NULL,
  cash_flag TINYINT NULL,
  name VARCHAR(150) NOT NULL,
  bank_name VARCHAR(100) NULL,
  branch VARCHAR(100) NULL,
  account_no VARCHAR(30) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  opening_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cash (temple_id, cash_flag),
  KEY idx_account_temple (temple_id),
  CONSTRAINT fk_account_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;

ALTER TABLE transactions
  ADD COLUMN account_id INT UNSIGNED NULL AFTER amount,
  ADD COLUMN transfer_id INT UNSIGNED NULL AFTER account_id,
  ADD KEY idx_txn_account (account_id, txn_date),
  ADD KEY idx_txn_transfer (transfer_id),
  ADD CONSTRAINT fk_txn_account FOREIGN KEY (account_id) REFERENCES accounts(id);

-- สร้างบัญชีเงินสดให้วัดที่มีรายการอยู่แล้ว และผูกรายการเดิมเข้าบัญชีนั้น
INSERT INTO accounts (temple_id, kind, cash_flag, name)
  SELECT DISTINCT temple_id, 'cash', 1, 'เงินสด' FROM transactions;
UPDATE transactions x JOIN accounts a ON a.temple_id = x.temple_id AND a.cash_flag = 1 SET x.account_id = a.id WHERE x.account_id IS NULL;
