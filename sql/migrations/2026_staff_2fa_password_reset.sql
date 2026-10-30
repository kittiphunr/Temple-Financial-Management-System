-- 2FA สำหรับแอดมินหลัก/แอดมินอำเภอ/เจ้าคณะจังหวัด + ลืมรหัสผ่านทางอีเมล
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_staff_2fa_password_reset.sql
-- หลังรัน: แอดมินหลักจะถูกให้ยืนยันอีเมลตอนเข้าสู่ระบบครั้งถัดไป ส่วนแอดมินอำเภอ/เจ้าคณะจังหวัดต้องให้แอดมินหลักลงอีเมลให้
-- ที่ จัดการระบบ → ผู้ใช้ ก่อน จึงจะเข้าสู่ระบบได้ (ปิดชั่วคราวได้ด้วย 'staff_2fa' => false ใน config.local.php)
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN email VARCHAR(190) NULL AFTER full_name,
  ADD COLUMN email_verified_at DATETIME NULL AFTER email;

ALTER TABLE login_otps
  ADD COLUMN purpose ENUM('login','reset','enroll') NOT NULL DEFAULT 'login' AFTER user_id;
