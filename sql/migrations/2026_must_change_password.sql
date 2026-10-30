-- บังคับให้แอดมินประจำวัดเปลี่ยนรหัสผ่านในการเข้าสู่ระบบครั้งแรก (หลังยืนยันอีเมลแล้ว)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_must_change_password.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

-- บัญชีวัดที่มีอยู่แล้วทั้งหมดจะถูกขอให้ตั้งรหัสผ่านใหม่หนึ่งครั้งในการเข้าสู่ระบบครั้งถัดไป
-- (ถ้าบางวัดตั้งรหัสผ่านเองไปแล้วและไม่ต้องการบังคับ ให้ยกเว้นด้วย: UPDATE users SET must_change_password = 0 WHERE id IN (...);)
UPDATE users SET must_change_password = 1 WHERE role = 'temple';
