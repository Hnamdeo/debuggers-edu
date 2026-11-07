-- Homework and worksheets per class. lesson_code links a Thinkle Tots lesson
-- (for example ABC-S1-E01) so a YouTube lesson can be set as homework or revision later.

CREATE TABLE homework (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  division_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NULL,
  kind ENUM('homework','worksheet','revision','lesson') NOT NULL DEFAULT 'homework',
  title VARCHAR(160) NOT NULL,
  details TEXT NULL,
  link_url VARCHAR(500) NULL,
  lesson_code VARCHAR(40) NULL,
  due_on DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY ix_homework_division (division_id, created_at),
  KEY ix_homework_school (school_id, created_at),
  CONSTRAINT fk_hw_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_hw_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE,
  CONSTRAINT fk_hw_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
