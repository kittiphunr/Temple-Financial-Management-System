-- แจ้งเตือนอัตโนมัติ (cron): บันทึกว่าส่งอีเมลแจ้งเตือนอะไรให้ใครไปแล้ว เพื่อไม่ส่งซ้ำ (ต้องรัน 2026_monthly_reports.sql ก่อน)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_report_notices.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS report_notices (
  recipient VARCHAR(20) NOT NULL COMMENT 'ผู้รับ t+รหัสวัด หรือ u+รหัสผู้ใช้',
  report_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ. ของเดือนรายงาน',
  report_month TINYINT UNSIGNED NOT NULL,
  kind VARCHAR(40) NOT NULL COMMENT 'due_soon overdue unclosed returned_nudge digest',
  sent_at DATETIME NOT NULL,
  PRIMARY KEY (recipient, report_year, report_month, kind)
) ENGINE=InnoDB;
