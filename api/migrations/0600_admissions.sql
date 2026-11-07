-- Admissions: an application moves enquiry -> registered -> admitted (or not_admitted).
-- Parents can apply online from the school's link; the office can add walk-ins.
-- Less common form fields (blood group, category, mother tongue, transport...) live in details JSON
-- so a school can ask for more without a database change.

CREATE TABLE admissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  application_no VARCHAR(20) NOT NULL,
  status ENUM('enquiry','registered','admitted','not_admitted') NOT NULL DEFAULT 'enquiry',
  source ENUM('online','office') NOT NULL DEFAULT 'office',
  academic_year VARCHAR(20) NULL,
  grade_id INT UNSIGNED NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NULL,
  gender ENUM('girl','boy','other') NULL,
  dob DATE NULL,
  father_name VARCHAR(120) NULL,
  father_phone VARCHAR(15) NULL,
  mother_name VARCHAR(120) NULL,
  mother_phone VARCHAR(15) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  previous_school VARCHAR(160) NULL,
  details JSON NULL,
  registration_fee INT UNSIGNED NULL,
  receipt_no VARCHAR(30) NULL,
  follow_up_on DATE NULL,
  notes TEXT NULL,
  student_id INT UNSIGNED NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admission_no (school_id, application_no),
  KEY ix_admission_status (school_id, status, created_at),
  KEY ix_admission_ip (ip, created_at),
  CONSTRAINT fk_adm_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_adm_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE SET NULL,
  CONSTRAINT fk_adm_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra admission form details on the student record (same keys as admissions.details)
ALTER TABLE students ADD COLUMN details JSON NULL AFTER admitted_on;
