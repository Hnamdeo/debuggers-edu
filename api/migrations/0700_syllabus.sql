-- Syllabus: per class and subject, chapters (units) holding lessons in teaching order.
-- Each lesson can be planned for a day, carry a Thinkle Tots lesson code (ABC-S1-E01) or
-- video link, and is ticked off per section when taught.

CREATE TABLE syllabus_units (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  grade_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  month VARCHAR(20) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_unit_class (school_id, academic_year_id, grade_id, subject_id, sort_order),
  CONSTRAINT fk_unit_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_grade FOREIGN KEY (grade_id) REFERENCES grades (id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE syllabus_lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  details TEXT NULL,
  planned_on DATE NULL,
  lesson_code VARCHAR(40) NULL,
  link_url VARCHAR(500) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_lesson_unit (unit_id, sort_order),
  KEY ix_lesson_day (school_id, planned_on),
  CONSTRAINT fk_lesson_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_lesson_unit FOREIGN KEY (unit_id) REFERENCES syllabus_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE syllabus_progress (
  school_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  division_id INT UNSIGNED NOT NULL,
  done_on DATE NOT NULL,
  done_by INT UNSIGNED NULL,
  PRIMARY KEY (lesson_id, division_id),
  KEY ix_progress_division (division_id),
  CONSTRAINT fk_progress_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES syllabus_lessons (id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
