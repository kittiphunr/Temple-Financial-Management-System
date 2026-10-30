-- ทะเบียนทรัพย์สินของวัด (ที่ดิน อาคาร ครุภัณฑ์ ยานพาหนะ) และการผูกรายจ่ายซื้อ/ซ่อมกับทรัพย์สิน
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_assets.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  asset_no VARCHAR(30) NULL COMMENT 'เลขทะเบียนทรัพย์สิน กรอกเอง ไม่ซ้ำภายในวัด',
  name VARCHAR(200) NOT NULL,
  category ENUM('land','building','equipment','vehicle','other') NOT NULL,
  acquired_date DATE NULL,
  acquired_how VARCHAR(100) NULL COMMENT 'ได้มาโดย เช่น ซื้อ ผู้มีจิตศรัทธาถวาย',
  value DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'มูลค่า/ราคาทุน',
  status ENUM('good','fair','poor','disposed') NOT NULL DEFAULT 'good',
  location VARCHAR(150) NULL,
  holder VARCHAR(150) NULL COMMENT 'ผู้รับผิดชอบดูแล',
  detail VARCHAR(500) NULL COMMENT 'รายละเอียด เช่น เลขโฉนดและเนื้อที่ ทะเบียนรถ ยี่ห้อรุ่น',
  photo_media_id INT UNSIGNED NULL,
  disposed_date DATE NULL,
  disposed_note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_asset_no (temple_id, asset_no),
  KEY idx_asset_temple (temple_id, status),
  CONSTRAINT fk_asset_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_asset_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_asset_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE transactions
  ADD COLUMN asset_id INT UNSIGNED NULL COMMENT 'ทรัพย์สินที่รายจ่ายนี้ซื้อหรือซ่อม' AFTER account_id,
  ADD COLUMN asset_kind ENUM('purchase','repair') NULL COMMENT 'ซื้อ หรือ ซ่อมบำรุง' AFTER asset_id,
  ADD KEY idx_txn_asset (asset_id),
  ADD CONSTRAINT fk_txn_asset FOREIGN KEY (asset_id) REFERENCES assets(id);
