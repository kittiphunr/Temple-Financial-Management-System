-- ระบบบัญชีรายรับ-รายจ่ายวัด จังหวัดแพร่
-- MySQL 5.7+ / MariaDB 10.3+  (utf8mb4)

CREATE DATABASE IF NOT EXISTS tfms_phrae CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tfms_phrae;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS districts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
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
  verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ตรวจสอบกับทะเบียนวัดแล้ว',
  source VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_temple (district_id, name, subdistrict),
  CONSTRAINT fk_temple_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  role ENUM('admin','district','temple') NOT NULL DEFAULT 'temple',
  district_id INT UNSIGNED NULL,
  temple_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_user_temple FOREIGN KEY (temple_id) REFERENCES temples(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('income','expense') NOT NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cat (type, name)
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
  created_by INT UNSIGNED NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_temple_date (temple_id, txn_date),
  KEY idx_type_date (type, txn_date),
  CONSTRAINT fk_txn_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_txn_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_txn_user FOREIGN KEY (created_by) REFERENCES users(id)
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
