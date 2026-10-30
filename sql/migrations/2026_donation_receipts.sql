-- อนุโมทนาบัตร: หมวดรับบริจาค, เลขผู้เสียภาษีของวัด, ตารางอนุโมทนาบัตร
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_donation_receipts.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE categories ADD COLUMN is_donation TINYINT(1) NOT NULL DEFAULT 0;
UPDATE categories SET is_donation = 1 WHERE type = 'income' AND name IN ('เงินบริจาคทั่วไป','เงินทำบุญ/ตู้รับบริจาค','เงินกฐิน/ผ้าป่า');
ALTER TABLE temples ADD COLUMN tax_id CHAR(13) NULL;


-- อนุโมทนาบัตร (ใบอนุโมทนาบัตรสำหรับรายรับประเภทบริจาค) — เก็บสำเนาข้อมูลตอนออก ไม่ลบ ยกเลิกได้โดยระบุเหตุผล
CREATE TABLE IF NOT EXISTS donation_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NOT NULL,
  receipt_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ. ที่ออก (เลขที่รันใหม่ทุกปี)',
  book_no VARCHAR(20) NOT NULL DEFAULT '1',
  receipt_no INT UNSIGNED NOT NULL,
  receipt_date DATE NOT NULL,
  donor_name VARCHAR(200) NOT NULL,
  donor_address VARCHAR(500) NULL,
  purpose VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  receiver_name VARCHAR(200) NULL,
  receiver_position VARCHAR(100) NULL,
  abbot_name VARCHAR(200) NULL,
  status ENUM('issued','void') NOT NULL DEFAULT 'issued',
  void_reason VARCHAR(255) NULL,
  voided_at DATETIME NULL,
  issued_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_no (temple_id, receipt_year, receipt_no),
  KEY idx_receipt_txn (transaction_id),
  CONSTRAINT fk_receipt_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_receipt_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_receipt_user FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;
