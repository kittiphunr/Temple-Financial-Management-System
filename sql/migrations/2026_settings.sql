-- ตารางค่าตั้งของระบบ (ชื่อจังหวัด/คำบรรยายระบบ ตั้งอัตโนมัติจากการนำเข้าทะเบียนวัดของจังหวัดอื่น)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_settings.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;
