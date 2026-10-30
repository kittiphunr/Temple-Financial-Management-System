-- ย้ายฐานข้อมูลเดิม (ที่ติดตั้งก่อนมีระบบบัญชีวัด/2FA) ให้รองรับ 1 วัด = 1 บัญชี + อีเมล + 2FA
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_temple_accounts.sql
-- ก่อนรัน ตรวจว่าไม่มีวัดใดมี user role=temple มากกว่า 1 บัญชี:
--   SELECT temple_id, COUNT(*) FROM users WHERE temple_id IS NOT NULL GROUP BY temple_id HAVING COUNT(*) > 1;
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE temples
  ADD COLUMN email VARCHAR(190) NULL AFTER phone,
  ADD COLUMN email_updated_at TIMESTAMP NULL AFTER email;

ALTER TABLE users
  ADD COLUMN failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN locked_until DATETIME NULL,
  ADD COLUMN last_login_at DATETIME NULL,
  ADD COLUMN password_changed_at DATETIME NULL,
  ADD UNIQUE KEY uq_user_temple (temple_id);

CREATE TABLE IF NOT EXISTS login_otps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  salt CHAR(16) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- จากนั้นรัน: php setup.php หรือกดปุ่ม "สร้างบัญชีให้วัดที่ยังไม่มี" ในหน้า จัดการระบบ → บัญชีวัด
