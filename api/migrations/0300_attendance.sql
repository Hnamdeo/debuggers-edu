-- Daily student attendance, one row per child per day.

CREATE TABLE student_attendance (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_id INT UNSIGNED NOT NULL,
  division_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  on_date DATE NOT NULL,
  status ENUM('present','absent','late','leave') NOT NULL,
  marked_by INT UNSIGNED NULL,
  marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance (student_id, on_date),
  KEY ix_attendance_division (division_id, on_date),
  KEY ix_attendance_school (school_id, on_date),
  CONSTRAINT fk_att_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_division FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE,
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
