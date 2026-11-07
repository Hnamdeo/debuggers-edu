-- Fees: what each class pays in a year (one row per head and instalment), per-child changes
-- (concession, transport, late fee) and payments with receipt numbers. Amounts are whole rupees.
-- A child's balance is simply everything due minus everything paid, so part payments just work.

CREATE TABLE fee_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  head VARCHAR(60) NOT NULL,
  amount INT UNSIGNED NOT NULL,
  due_on DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fee_items_grade (academic_year_id, grade_id, due_on),
  CONSTRAINT fk_fee_item_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_item_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_item_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_adjustments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  amount INT NOT NULL,
  due_on DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fee_adj_student (student_id, academic_year_id),
  CONSTRAINT fk_fee_adj_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_adj_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_adj_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  receipt_no VARCHAR(20) NOT NULL,
  amount INT UNSIGNED NOT NULL,
  mode ENUM('cash','upi','cheque','bank','card','online') NOT NULL DEFAULT 'cash',
  reference VARCHAR(80) NULL,
  paid_on DATE NOT NULL,
  note VARCHAR(200) NULL,
  received_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fee_receipt (school_id, receipt_no),
  KEY ix_fee_pay_student (student_id, academic_year_id),
  KEY ix_fee_pay_day (school_id, paid_on),
  CONSTRAINT fk_fee_pay_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_pay_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_fee_pay_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
