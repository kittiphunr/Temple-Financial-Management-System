-- รับรองรายงานประจำเดือนเป็นลำดับชั้น: เจ้าคณะตำบลรับรอง แล้วอำเภอรับรอง หรือส่งกลับแก้ไขพร้อมเหตุผล (ต้องรัน 2026_monthly_reports.sql ก่อน)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_report_approval.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE monthly_reports
  ADD COLUMN sub_approved_at DATETIME NULL COMMENT 'เจ้าคณะตำบลรับรองรอบส่งปัจจุบันเมื่อ' AFTER note,
  ADD COLUMN sub_approved_by INT UNSIGNED NULL AFTER sub_approved_at,
  ADD COLUMN dist_approved_at DATETIME NULL COMMENT 'อำเภอรับรองรอบส่งปัจจุบันเมื่อ' AFTER sub_approved_by,
  ADD COLUMN dist_approved_by INT UNSIGNED NULL AFTER dist_approved_at,
  ADD COLUMN returned_at DATETIME NULL COMMENT 'ถูกส่งกลับแก้ไขเมื่อ (ล้างเมื่อวัดส่งใหม่)' AFTER dist_approved_by,
  ADD COLUMN returned_by INT UNSIGNED NULL AFTER returned_at,
  ADD COLUMN return_reason VARCHAR(255) NULL AFTER returned_by,
  ADD CONSTRAINT fk_report_sub_appr FOREIGN KEY (sub_approved_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_report_dist_appr FOREIGN KEY (dist_approved_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_report_returner FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL;

-- ประวัติการรับรอง/ส่งกลับ (เก็บถาวร ไม่ถูกล้างเมื่อวัดส่งใหม่)
CREATE TABLE IF NOT EXISTS report_review_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL,
  report_month TINYINT UNSIGNED NOT NULL,
  revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  action ENUM('sub_approve','dist_approve','return') NOT NULL,
  reason VARCHAR(255) NULL,
  by_user INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_review_temple (temple_id, report_year, report_month),
  CONSTRAINT fk_review_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_review_user FOREIGN KEY (by_user) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
