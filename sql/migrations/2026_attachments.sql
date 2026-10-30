-- แนบหลักฐาน (ใบเสร็จ/สลิป) กับรายการรายรับ-รายจ่าย
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_attachments.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  temple_id INT UNSIGNED NOT NULL,
  filename VARCHAR(120) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_att_txn (transaction_id),
  CONSTRAINT fk_att_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_att_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_att_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
