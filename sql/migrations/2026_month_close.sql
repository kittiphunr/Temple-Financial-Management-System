-- ปิดงวดรายเดือน: ล็อกการเพิ่ม/แก้ไข/ลบรายการของเดือนที่ปิดแล้ว (ต้องรัน 2026_monthly_reports.sql ก่อน)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_month_close.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE monthly_reports
  ADD COLUMN closed_at DATETIME NULL COMMENT 'ปิดงวดแล้ว (ล็อกการแก้ไขรายการของเดือนนี้) NULL = ยังเปิดอยู่' AFTER note,
  ADD COLUMN closed_by INT UNSIGNED NULL AFTER closed_at,
  ADD CONSTRAINT fk_report_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS month_close_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL,
  report_month TINYINT UNSIGNED NOT NULL,
  action ENUM('close','reopen') NOT NULL,
  reason VARCHAR(255) NULL,
  by_user INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_close_temple (temple_id, report_year, report_month),
  CONSTRAINT fk_close_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_close_user FOREIGN KEY (by_user) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
