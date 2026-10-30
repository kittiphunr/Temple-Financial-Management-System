-- กระทบยอดบัญชีธนาคาร: เทียบยอดตามสมุดบัญชีของวัดกับยอดตาม statement สิ้นเดือน
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_bank_reconcile.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_reconciliations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  recon_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
  recon_month TINYINT UNSIGNED NOT NULL,
  statement_balance DECIMAL(14,2) NOT NULL COMMENT 'ยอดคงเหลือตาม statement ธนาคาร สิ้นเดือน',
  book_balance DECIMAL(14,2) NOT NULL COMMENT 'ยอดคงเหลือตามสมุดบัญชีของวัด สิ้นเดือน ณ เวลาที่บันทึก',
  outstanding_in DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'เงินฝากระหว่างทาง (วัดบันทึกรับแล้ว ธนาคารยังไม่ขึ้น)',
  outstanding_out DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'รายจ่ายที่ธนาคารยังไม่หักหรือเช็คที่ยังไม่ขึ้นเงิน',
  difference DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'ส่วนต่างที่อธิบายไม่ได้ 0 = ตรงกัน',
  note VARCHAR(255) NULL,
  reconciled_by INT UNSIGNED NULL,
  reconciled_at DATETIME NOT NULL,
  UNIQUE KEY uq_recon (account_id, recon_year, recon_month),
  KEY idx_recon_temple (temple_id),
  CONSTRAINT fk_recon_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_recon_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_recon_user FOREIGN KEY (reconciled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bank_cleared (
  transaction_id INT UNSIGNED NOT NULL PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  recon_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ. ของเดือนที่ติ๊กว่าตรงกับ statement',
  recon_month TINYINT UNSIGNED NOT NULL,
  marked_by INT UNSIGNED NULL,
  marked_at DATETIME NOT NULL,
  KEY idx_cleared_account (account_id, recon_year, recon_month),
  CONSTRAINT fk_cleared_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_cleared_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_cleared_user FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
