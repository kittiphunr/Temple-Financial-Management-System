-- ระบบบัญชีรายรับ-รายจ่ายวัด จังหวัดแพร่
-- MySQL 5.7+ / MariaDB 10.3+  (utf8mb4)

CREATE DATABASE IF NOT EXISTS tfms_phrae CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tfms_phrae;

SET NAMES utf8mb4;

-- รูปภาพ (โลโก้วัด/อำเภอ, รูปถ่ายบุคคล) เก็บในฐานข้อมูลหลังย่อ/แปลงใหม่แล้ว ไม่เก็บเป็นไฟล์บนเว็บ — เปิดได้ผ่าน media.php ที่ตรวจสิทธิ์เท่านั้น
CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('logo','photo') NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ค่าตั้งที่แอดมินหลักเปลี่ยนในระบบ (ชื่อจังหวัด ฯลฯ) ซ้อนทับ config
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS districts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  logo_media_id INT UNSIGNED NULL COMMENT 'โลโก้อำเภอ แอดมินหลักเป็นผู้ใส่',
  CONSTRAINT fk_district_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS temples (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  temple_code VARCHAR(11) NULL UNIQUE COMMENT 'รหัสวัด 11 หลักจากทะเบียน พศ.',
  name VARCHAR(150) NOT NULL,
  temple_type VARCHAR(50) NULL,
  sect VARCHAR(30) NULL,
  subdistrict VARCHAR(100) NULL,
  moo VARCHAR(20) NULL,
  abbot VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  village VARCHAR(100) NULL,
  house_no VARCHAR(50) NULL,
  soi VARCHAR(100) NULL,
  road VARCHAR(100) NULL,
  postcode VARCHAR(5) NULL,
  founded_be SMALLINT UNSIGNED NULL COMMENT 'ปี พ.ศ. ที่ตั้งวัด (จากทะเบียน)',
  logo_media_id INT UNSIGNED NULL COMMENT 'โลโก้วัด แอดมินวัดเป็นผู้ใส่ (ใช้ในเอกสาร)',
  tax_id CHAR(13) NULL COMMENT 'เลขประจำตัวผู้เสียภาษีของวัด (แสดงในอนุโมทนาบัตร)',
  email VARCHAR(190) NULL COMMENT 'อีเมลวัด ใช้ส่งรหัส 2FA ตั้ง/แก้ได้เฉพาะแอดมินหลัก',
  email_updated_at TIMESTAMP NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ตรวจสอบกับทะเบียนวัดแล้ว',
  source VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_temple (district_id, name, subdistrict),
  CONSTRAINT fk_temple_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_temple_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  role ENUM('admin','province','district','subdistrict','temple') NOT NULL DEFAULT 'temple',
  district_id INT UNSIGNED NULL,
  temple_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email VARCHAR(190) NULL COMMENT 'อีเมลรับรหัสยืนยัน/กู้รหัสผ่านของแอดมินหลัก/อำเภอ/เจ้าคณะจังหวัด (บัญชีวัดใช้อีเมลของวัด)',
  email_verified_at DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ต้องเปลี่ยนรหัสผ่านก่อนใช้งาน (หลังผ่าน 2FA)',
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_temple (temple_id), -- 1 วัด = 1 บัญชี (NULL ซ้ำได้สำหรับ admin/district)
  UNIQUE KEY uq_user_district (district_id), -- 1 อำเภอ = 1 แอดมินประจำอำเภอ
  CONSTRAINT fk_user_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_user_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;

-- ตำบลที่เจ้าคณะตำบลดูแล (1 บัญชีดูได้หลายตำบล ดูข้อมูลอย่างเดียว ไม่นับในโควตาผู้ใช้ระดับจังหวัด/อำเภอ)
CREATE TABLE IF NOT EXISTS user_subdistricts (
  user_id INT UNSIGNED NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  subdistrict VARCHAR(100) NOT NULL,
  PRIMARY KEY (user_id, district_id, subdistrict),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_us_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('income','expense') NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_donation TINYINT(1) NOT NULL DEFAULT 0,   -- หมวดรับบริจาค: ออกอนุโมทนาบัตรได้
  is_transfer TINYINT(1) NOT NULL DEFAULT 0,   -- หมวดระบบสำหรับโอน/ฝาก-ถอนระหว่างบัญชี (ไม่นับเป็นรายรับ-รายจ่ายจริง)
  UNIQUE KEY uq_cat (type, name)
) ENGINE=InnoDB;

-- บัญชีของวัด: เงินสด/เช็ค (1 วัด 1 บัญชี) และบัญชีเงินฝากธนาคาร (หลายบัญชีได้) ใช้ออกรายงานแบบที่ 1-3
CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  kind ENUM('cash','bank') NOT NULL,
  cash_flag TINYINT NULL COMMENT '1 = บัญชีเงินสด (unique ต่อวัด) · NULL = บัญชีธนาคาร',
  name VARCHAR(150) NOT NULL COMMENT 'ชื่อบัญชี',
  bank_name VARCHAR(100) NULL,
  branch VARCHAR(100) NULL,
  account_no VARCHAR(30) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'ยอดยกมา ณ วันเริ่มบัญชี (ก่อนรายการของวันนั้น)',
  opening_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cash (temple_id, cash_flag),
  KEY idx_account_temple (temple_id),
  CONSTRAINT fk_account_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  type ENUM('income','expense') NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  txn_date DATE NOT NULL,
  doc_no VARCHAR(50) NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  account_id INT UNSIGNED NULL COMMENT 'บัญชีที่รายการเกิดขึ้น (NULL = เงินสด)',
  transfer_id INT UNSIGNED NULL COMMENT 'รหัสกลุ่มรายการโอน/ฝาก-ถอนระหว่างบัญชี (ไม่นับเป็นรายรับ-รายจ่ายในสถิติ)',
  created_by INT UNSIGNED NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_temple_date (temple_id, txn_date),
  KEY idx_type_date (type, txn_date),
  KEY idx_txn_account (account_id, txn_date),
  KEY idx_txn_transfer (transfer_id),
  CONSTRAINT fk_txn_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_txn_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_txn_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_txn_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- อนุโมทนาบัตร (ใบอนุโมทนาบัตรสำหรับรายรับประเภทบริจาค) — เก็บสำเนาข้อมูลตอนออก ไม่ลบ ยกเลิกได้โดยระบุเหตุผล
CREATE TABLE IF NOT EXISTS donation_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NOT NULL,
  receipt_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ. ที่ออก (เลขที่รันใหม่ทุกปี)',
  verify_code CHAR(16) NULL COMMENT 'รหัสสุ่มสำหรับตรวจสอบใบผ่าน QR code (verify.php)',
  book_no VARCHAR(20) NOT NULL DEFAULT '1',
  receipt_no INT UNSIGNED NOT NULL,
  receipt_date DATE NOT NULL,
  donor_name VARCHAR(200) NOT NULL,
  donor_address VARCHAR(500) NULL,
  purpose VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  receiver_name VARCHAR(200) NULL,
  receiver_position VARCHAR(100) NULL,
  abbot_name VARCHAR(200) NULL,
  status ENUM('issued','void') NOT NULL DEFAULT 'issued',
  void_reason VARCHAR(255) NULL,
  voided_at DATETIME NULL,
  issued_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_no (temple_id, receipt_year, receipt_no),
  UNIQUE KEY uq_receipt_verify (verify_code),
  KEY idx_receipt_txn (transaction_id),
  CONSTRAINT fk_receipt_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_receipt_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_receipt_user FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- การส่งรายงานประจำเดือน: วัดกด "ส่งรายงาน" หลังตรวจแบบที่ 1–3 เก็บยอดรวม ณ วันที่ส่งไว้เทียบว่ามีการแก้ไขหลังส่งหรือไม่
CREATE TABLE IF NOT EXISTS monthly_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
  report_month TINYINT UNSIGNED NOT NULL,
  submitted_at DATETIME NOT NULL,
  submitted_by INT UNSIGNED NULL,
  revision SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ครั้งที่ส่ง (ส่งใหม่เพิ่มทีละ 1)',
  txn_count INT UNSIGNED NOT NULL DEFAULT 0,
  income_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  expense_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  closed_at DATETIME NULL COMMENT 'ปิดงวดแล้ว (ล็อกการแก้ไขรายการของเดือนนี้) NULL = ยังเปิดอยู่',
  closed_by INT UNSIGNED NULL,
  UNIQUE KEY uq_report (temple_id, report_year, report_month),
  KEY idx_report_period (report_year, report_month),
  CONSTRAINT fk_report_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_report_user FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ประวัติการปิด/เปิดงวด (เปิดงวดต้องระบุเหตุผล)
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

-- อีเมลเตือนวัดที่ยังไม่ส่งรายงาน (เตือนได้เดือนละครั้งต่อวัด)
CREATE TABLE IF NOT EXISTS report_reminders (
  temple_id INT UNSIGNED NOT NULL,
  report_year SMALLINT UNSIGNED NOT NULL,
  report_month TINYINT UNSIGNED NOT NULL,
  sent_at DATETIME NOT NULL,
  sent_by INT UNSIGNED NULL,
  PRIMARY KEY (temple_id, report_year, report_month),
  CONSTRAINT fk_rem_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at)
) ENGINE=InnoDB;

-- รหัสยืนยัน 6 หลัก (2FA) ส่งทางอีเมลวัด เก็บเฉพาะค่าแฮช ใช้ได้ครั้งเดียว
CREATE TABLE IF NOT EXISTS login_otps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('login','reset','enroll') NOT NULL DEFAULT 'login',
  code_hash CHAR(64) NOT NULL,
  salt CHAR(16) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- บุคลากรของวัด: เจ้าอาวาส / พระลูกวัด / ไวยาวัจกร (เพิ่มได้ไม่จำกัดจำนวนต่อวัด)
-- เลขบัตรประชาชนเก็บแบบเข้ารหัส (libsodium) + ค่าแฮช HMAC สำหรับกันซ้ำ + 4 หลักท้ายสำหรับแสดงแบบปิดบัง
CREATE TABLE IF NOT EXISTS temple_people (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  position ENUM('abbot','monk','steward') NOT NULL,
  title VARCHAR(60) NULL COMMENT 'คำนำหน้า/สมณศักดิ์',
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  dhamma_name VARCHAR(100) NULL COMMENT 'ฉายา (พระภิกษุ)',
  national_id_enc VARBINARY(255) NOT NULL,
  national_id_idx CHAR(64) NOT NULL,
  national_id_last4 CHAR(4) NOT NULL,
  home_address TEXT NOT NULL COMMENT 'ที่อยู่ตามภูมิลำเนาเดิม',
  phone VARCHAR(30) NOT NULL,
  photo_media_id INT UNSIGNED NULL,
  consent_at DATETIME NOT NULL COMMENT 'วันที่ยืนยันว่าได้รับความยินยอมจากเจ้าของข้อมูล (PDPA)',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_id (temple_id, national_id_idx),
  KEY idx_temple_pos (temple_id, position),
  CONSTRAINT fk_person_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_person_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- หลักฐานแนบรายการ (ใบเสร็จ/สลิป) ภาพถูกแปลงใหม่ด้วย GD ส่วน PDF เก็บตามต้นฉบับ — เปิดได้ผ่าน attachment.php ที่ตรวจสิทธิ์เท่านั้น
CREATE TABLE IF NOT EXISTS transaction_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  temple_id INT UNSIGNED NOT NULL,
  filename VARCHAR(120) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size INT UNSIGNED NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_att_txn (transaction_id),
  CONSTRAINT fk_att_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id),
  CONSTRAINT fk_att_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_att_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
