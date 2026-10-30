-- บทบาทเจ้าคณะตำบล (ดูข้อมูลวัดในตำบลที่ดูแลได้อย่างเดียว) และตารางตำบลที่ดูแล
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_subdistrict_role.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE users MODIFY role ENUM('admin','province','district','subdistrict','temple') NOT NULL DEFAULT 'temple';

CREATE TABLE IF NOT EXISTS user_subdistricts (
  user_id INT UNSIGNED NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  subdistrict VARCHAR(100) NOT NULL,
  PRIMARY KEY (user_id, district_id, subdistrict),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB;
