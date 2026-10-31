-- รายการประจำ: ตั้งแม่แบบรายรับ-รายจ่ายที่เกิดซ้ำทุกเดือน แล้วกดยืนยันบันทึกเมื่อถึงกำหนด (ระบบไม่บันทึกเอง)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_recurring.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS recurring_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  type ENUM('income','expense') NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NULL COMMENT 'บัญชีที่รายการเกิดขึ้น NULL = เงินสด',
  fund_id INT UNSIGNED NULL COMMENT 'กองทุน/โครงการ (ตรวจในโปรแกรม ไม่ผูกคีย์ต่างประเทศ เพื่อให้ใช้ได้แม้ยังไม่รัน migration กองทุน)',
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(14,2) NOT NULL COMMENT 'จำนวนเงินตั้งต้น แก้ได้ตอนยืนยัน',
  day_of_month TINYINT UNSIGNED NOT NULL COMMENT 'วันที่ของเดือน 1-31 ถ้าเดือนนั้นไม่มีวันนั้นใช้วันสุดท้ายของเดือน',
  start_month DATE NOT NULL COMMENT 'เดือนแรกที่เริ่มนับ (วันที่ 1 ของเดือน)',
  end_month DATE NULL COMMENT 'เดือนสุดท้าย NULL = ไม่มีกำหนดสิ้นสุด',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_recurring_temple (temple_id, is_active),
  CONSTRAINT fk_recurring_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_recurring_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_recurring_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_recurring_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- เดือนที่เลือก "ข้าม" (ไม่บันทึก) ของรายการประจำ
CREATE TABLE IF NOT EXISTS recurring_skips (
  recurring_id INT UNSIGNED NOT NULL,
  period DATE NOT NULL COMMENT 'วันที่ 1 ของเดือนที่ข้าม',
  skipped_by INT UNSIGNED NULL,
  skipped_at DATETIME NOT NULL,
  PRIMARY KEY (recurring_id, period),
  CONSTRAINT fk_rskip_item FOREIGN KEY (recurring_id) REFERENCES recurring_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_rskip_user FOREIGN KEY (skipped_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE transactions
  ADD COLUMN recurring_id INT UNSIGNED NULL COMMENT 'มาจากรายการประจำใด' AFTER asset_kind,
  ADD COLUMN recurring_period DATE NULL COMMENT 'เดือนของรายการประจำที่บันทึก (วันที่ 1)' AFTER recurring_id,
  ADD KEY idx_txn_recurring (recurring_id, recurring_period),
  ADD CONSTRAINT fk_txn_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_items(id) ON DELETE SET NULL;
