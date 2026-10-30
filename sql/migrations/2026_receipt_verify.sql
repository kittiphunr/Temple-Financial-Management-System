-- ระบบตรวจสอบใบอนุโมทนาด้วย QR code: รหัสตรวจสอบรายใบ (ใบเก่าจะได้รหัสอัตโนมัติเมื่อเปิดดูครั้งแรก)
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_receipt_verify.sql
USE tfms_phrae;
SET NAMES utf8mb4;

ALTER TABLE donation_receipts
  ADD COLUMN verify_code CHAR(16) NULL AFTER transaction_id,
  ADD UNIQUE KEY uq_receipt_verify (verify_code);
