-- บัญชีธนาคาร: เลือกธนาคารจากรายชื่อพร้อมโลโก้ (bank_code) และแนบภาพหน้าบุ๊คแบงก์ (bookbank_media_id)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_bank_book.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE accounts
  ADD COLUMN bank_code VARCHAR(16) NULL COMMENT 'รหัสธนาคารในรายชื่อ (data/thai_banks.json) NULL = พิมพ์ชื่อเอง' AFTER bank_name,
  ADD COLUMN bookbank_media_id INT UNSIGNED NULL COMMENT 'ภาพหน้าบุ๊คแบงก์' AFTER account_no,
  ADD CONSTRAINT fk_account_bookbank FOREIGN KEY (bookbank_media_id) REFERENCES media(id) ON DELETE SET NULL;
