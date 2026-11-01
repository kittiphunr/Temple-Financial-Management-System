-- ชุดกรองที่บันทึกไว้ใช้ซ้ำของหน้า ค้นหารายการ (เก็บต่อผู้ใช้ ไม่แชร์ข้ามผู้ใช้)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_saved_searches.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS saved_searches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  params TEXT NOT NULL COMMENT 'เงื่อนไขกรองแบบ JSON',
  created_at DATETIME NOT NULL,
  KEY idx_saved_user (user_id),
  CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
