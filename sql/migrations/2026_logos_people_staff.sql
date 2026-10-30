-- โลโก้วัด/อำเภอ, บุคลากรวัด, ผู้ใช้เจ้าคณะจังหวัด และข้อจำกัดจำนวนผู้ใช้ระดับอำเภอ/จังหวัด
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_logos_people_staff.sql
-- ก่อนรัน ตรวจว่าไม่มีอำเภอใดมีแอดมินประจำอำเภอมากกว่า 1 คน (ไม่เช่นนั้นขั้นสร้าง unique key จะล้มเหลว):
--   SELECT district_id, COUNT(*) FROM users WHERE district_id IS NOT NULL GROUP BY district_id HAVING COUNT(*) > 1;
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('logo','photo') NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE districts
  ADD COLUMN logo_media_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_district_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL;

ALTER TABLE temples
  ADD COLUMN village VARCHAR(100) NULL AFTER phone,
  ADD COLUMN house_no VARCHAR(50) NULL AFTER village,
  ADD COLUMN soi VARCHAR(100) NULL AFTER house_no,
  ADD COLUMN road VARCHAR(100) NULL AFTER soi,
  ADD COLUMN postcode VARCHAR(5) NULL AFTER road,
  ADD COLUMN founded_be SMALLINT UNSIGNED NULL AFTER postcode,
  ADD COLUMN logo_media_id INT UNSIGNED NULL AFTER founded_be,
  ADD CONSTRAINT fk_temple_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL;

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','province','district','temple') NOT NULL DEFAULT 'temple',
  ADD UNIQUE KEY uq_user_district (district_id);

CREATE TABLE IF NOT EXISTS temple_people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  position ENUM('abbot','monk','steward') NOT NULL,
  title VARCHAR(60) NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  dhamma_name VARCHAR(100) NULL,
  national_id_enc VARBINARY(255) NOT NULL,
  national_id_idx CHAR(64) NOT NULL,
  national_id_last4 CHAR(4) NOT NULL,
  home_address TEXT NOT NULL,
  phone VARCHAR(30) NOT NULL,
  photo_media_id INT UNSIGNED NULL,
  consent_at DATETIME NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_id (temple_id, national_id_idx),
  KEY idx_temple_pos (temple_id, position),
  CONSTRAINT fk_person_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_person_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- หลังรัน: เก็บสำรองไฟล์ storage/app.key (กุญแจเข้ารหัสเลขบัตรประชาชน — สร้างอัตโนมัติเมื่อใช้งานครั้งแรก ถ้าสูญหายจะถอดรหัสไม่ได้)
