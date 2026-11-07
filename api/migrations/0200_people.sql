-- Students, their parents, staff and class divisions.

CREATE TABLE staff (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  employee_code VARCHAR(30) NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NULL,
  email VARCHAR(190) NULL,
  designation VARCHAR(80) NULL,
  department VARCHAR(80) NULL,
  role ENUM('admin','principal','academic_head','teacher','accounts','staff') NOT NULL DEFAULT 'teacher',
  joined_on DATE NULL,
  status ENUM('active','left') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_staff_code (school_id, employee_code),
  UNIQUE KEY uq_staff_user (school_id, user_id),
  CONSTRAINT fk_staff_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE divisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL,
  class_teacher_staff_id INT UNSIGNED NULL,
  UNIQUE KEY uq_division (academic_year_id, grade_id, name),
  KEY ix_division_school (school_id),
  CONSTRAINT fk_division_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_division_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_division_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_division_teacher FOREIGN KEY (class_teacher_staff_id) REFERENCES staff (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  admission_no VARCHAR(30) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NULL,
  gender ENUM('girl','boy','other') NULL,
  dob DATE NULL,
  admitted_on DATE NULL,
  status ENUM('active','left') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_admission (school_id, admission_no),
  KEY ix_student_name (school_id, first_name),
  CONSTRAINT fk_student_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrolments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  division_id INT UNSIGNED NOT NULL,
  roll_no SMALLINT UNSIGNED NULL,
  UNIQUE KEY uq_enrolment (academic_year_id, student_id),
  KEY ix_enrolment_division (division_id),
  CONSTRAINT fk_enrolment_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_enrolment_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_enrolment_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_enrolment_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_guardians (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  relation ENUM('mother','father','guardian') NOT NULL DEFAULT 'guardian',
  UNIQUE KEY uq_guardian (student_id, user_id),
  KEY ix_guardian_user (school_id, user_id),
  CONSTRAINT fk_guardian_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_guardian_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_guardian_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
