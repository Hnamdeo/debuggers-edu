-- Payroll: each employee's monthly salary (basic + allowances - deductions, optional PF),
-- a salary sheet per month with unpaid leave days and one-off extras per person, and
-- payslips that staff can see. Amounts are whole rupees.

CREATE TABLE staff_salaries (
  staff_id INT UNSIGNED NOT NULL PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  basic INT UNSIGNED NOT NULL DEFAULT 0,
  allowances JSON NULL,
  deductions JSON NULL,
  pf TINYINT(1) NOT NULL DEFAULT 0,
  bank_name VARCHAR(80) NULL,
  account_last4 VARCHAR(4) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_salary_school (school_id),
  CONSTRAINT fk_salary_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_salary_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  month CHAR(7) NOT NULL,
  status ENUM('draft','final') NOT NULL DEFAULT 'draft',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalised_at DATETIME NULL,
  UNIQUE KEY uq_payroll_month (school_id, month),
  CONSTRAINT fk_run_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payslips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  run_id INT UNSIGNED NOT NULL,
  staff_id INT UNSIGNED NOT NULL,
  days_in_month TINYINT UNSIGNED NOT NULL,
  unpaid_days DECIMAL(4,1) NOT NULL DEFAULT 0,
  bonus INT UNSIGNED NOT NULL DEFAULT 0,
  advance INT UNSIGNED NOT NULL DEFAULT 0,
  earnings JSON NOT NULL,
  deductions JSON NOT NULL,
  gross INT NOT NULL,
  total_deductions INT NOT NULL,
  net INT NOT NULL,
  paid_on DATE NULL,
  pay_mode ENUM('bank','cash','upi','cheque') NULL,
  pay_reference VARCHAR(80) NULL,
  UNIQUE KEY uq_payslip (run_id, staff_id),
  KEY ix_payslip_staff (staff_id),
  CONSTRAINT fk_slip_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_slip_run FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_slip_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
