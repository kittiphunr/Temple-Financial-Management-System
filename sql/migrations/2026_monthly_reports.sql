-- ติดตามการส่งรายงานประจำเดือน: ตาราง monthly_reports และ report_reminders
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_monthly_reports.sql
USE tfms_phrae;
SET NAMES utf8mb4;

-- การส่งรายงานประจำเดือน: วัดกด "ส่งรายงาน" หลังตรวจแบบที่ 1–3 เก็บยอดรวม ณ วันที่ส่งไว้เทียบว่ามีการแก้ไขหลังส่งหรือไม่
CREATE TABLE IF NOT EXISTS monthly_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
  report_month TINYINT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL,
  submitted_by INT UNSIGNED NULL,
  revision SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ครั้งที่ส่ง (ส่งใหม่เพิ่มทีละ 1)',
  txn_count INT UNSIGNED NOT NULL DEFAULT 0,
  income_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  expense_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  UNIQUE KEY uq_report (temple_id, report_year, report_month),
  KEY idx_report_period (report_year, report_month),
  CONSTRAINT fk_report_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_report_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- อีเมลเตือนวัดที่ยังไม่ส่งรายงาน (เตือนได้เดือนละครั้งต่อวัด)
CREATE TABLE IF NOT EXISTS report_reminders (
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL,
  report_month TINYINT UNSIGNED NOT NULL,
  sent_at DATETIME NOT NULL,
  sent_by INT UNSIGNED NULL,
  PRIMARY KEY (temple_id, report_year, report_month),
  CONSTRAINT fk_rem_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;
