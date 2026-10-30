-- งบประมาณประจำปี: ตั้งงบรายหมวด (รายรับ/รายจ่าย) ต่อวัดต่อปี เทียบกับผลจริง
-- รันครั้งเดียว: mysql -u USER -p tfms_phrae < sql/migrations/2026_budget.sql
USE tfms_phrae;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS budgets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  temple_id INT UNSIGNED NOT NULL,
  budget_year SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
  category_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_budget (temple_id, budget_year, category_id),
  CONSTRAINT fk_budget_temple FOREIGN KEY (temple_id) REFERENCES temples(id),
  CONSTRAINT fk_budget_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_budget_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
