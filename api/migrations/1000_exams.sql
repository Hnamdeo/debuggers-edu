-- Exams and report cards. An exam (Unit Test 1, Half-yearly, Annual) has papers per class and
-- subject with maximum marks; teachers enter marks per section; the school publishes results,
-- then parents see the report card. Grades follow the CBSE 8-point scale (A1 to E).

CREATE TABLE exams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  starts_on DATE NULL,
  published_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_exam_year (school_id, academic_year_id),
  CONSTRAINT fk_exam_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_papers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  exam_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  max_marks SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  UNIQUE KEY uq_paper (exam_id, grade_id, subject_id),
  CONSTRAINT fk_paper_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_paper_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
  CONSTRAINT fk_paper_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_paper_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_marks (
  paper_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  school_id INT UNSIGNED NOT NULL,
  marks DECIMAL(5,1) NULL,
  absent TINYINT(1) NOT NULL DEFAULT 0,
  entered_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (paper_id, student_id),
  KEY ix_marks_student (student_id),
  CONSTRAINT fk_marks_paper FOREIGN KEY (paper_id) REFERENCES exam_papers (id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_remarks (
  exam_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  school_id INT UNSIGNED NOT NULL,
  remark VARCHAR(300) NOT NULL,
  updated_by INT UNSIGNED NULL,
  PRIMARY KEY (exam_id, student_id),
  CONSTRAINT fk_remark_exam FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE,
  CONSTRAINT fk_remark_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_remark_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
