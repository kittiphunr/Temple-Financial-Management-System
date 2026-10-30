-- ทะเบียนผู้บริจาค (ไม่บังคับ): เก็บเฉพาะผู้ที่ให้ความยินยอมตาม PDPA ที่อยู่/เบอร์โทรเก็บแบบเข้ารหัส
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_donors.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS donors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  address_enc VARBINARY(2000) NULL COMMENT 'ที่อยู่ เข้ารหัสด้วยกุญแจระบบ',
  phone_enc VARBINARY(300) NULL COMMENT 'เบอร์โทร เข้ารหัสด้วยกุญแจระบบ',
  note VARCHAR(255) NULL,
  consent_at DATETIME NOT NULL COMMENT 'วันเวลาที่บันทึกว่าผู้บริจาคให้ความยินยอม',
  consent_channel VARCHAR(40) NOT NULL COMMENT 'ช่องทางที่ได้รับความยินยอม เช่น เป็นลายลักษณ์อักษร ด้วยวาจา',
  consent_by INT UNSIGNED NULL,
  withdrawn_at DATETIME NULL COMMENT 'ถอนความยินยอมแล้ว ข้อมูลส่วนบุคคลถูกลบ',
  withdrawn_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_donor_temple (temple_id, name),
  CONSTRAINT fk_donor_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_donor_consent_user FOREIGN KEY (consent_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_donor_withdraw_user FOREIGN KEY (withdrawn_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE donation_receipts
  ADD COLUMN donor_id INT UNSIGNED NULL COMMENT 'ผู้บริจาคในทะเบียน (ถ้ามี) ชื่อ/ที่อยู่บนใบยังเก็บสำเนาไว้ตามหลักฐานทางบัญชี' AFTER transaction_id,
  ADD KEY idx_receipt_donor (donor_id),
  ADD CONSTRAINT fk_receipt_donor FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL;
