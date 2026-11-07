<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Who may see which child and class. Lives in core because Attendance, Homework and later
 * Fees and the LMS all ask the same questions, and must answer them the same way.
 */
final class Access
{
    public function __construct(private readonly Db $db) {}

    public function currentYearId(int $schoolId): ?int
    {
        $id = $this->db->value('SELECT id FROM academic_years WHERE school_id = ? AND is_current = 1 LIMIT 1', [$schoolId]);
        return $id === null ? null : (int)$id;
    }

    /** Class divisions where this user is the class teacher (current year). */
    public function teacherDivisionIds(Request $req): array
    {
        $t = $req->tenant;
        return array_map('intval', array_column($this->db->all(
            'SELECT d.id FROM divisions d
               JOIN staff s ON s.id = d.class_teacher_staff_id
               JOIN academic_years y ON y.id = d.academic_year_id AND y.is_current = 1
              WHERE d.school_id = ? AND s.user_id = ?',
            [$t->schoolId, $req->userId()],
        ), 'id'));
    }

    /** Students this parent is linked to in this school. */
    public function childIds(Request $req): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT student_id FROM student_guardians WHERE school_id = ? AND user_id = ?',
            [$req->tenant->schoolId, $req->userId()],
        ), 'student_id'));
    }

    /** Divisions of this parent's children (current year). */
    public function childDivisionIds(Request $req): array
    {
        $ids = $this->childIds($req);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column($this->db->all(
            "SELECT DISTINCT e.division_id FROM enrolments e
               JOIN academic_years y ON y.id = e.academic_year_id AND y.is_current = 1
              WHERE e.school_id = ? AND e.student_id IN ($in)",
            [$req->tenant->schoolId, ...$ids],
        ), 'division_id'));
    }

    public function division(int $schoolId, int $divisionId): array
    {
        return $this->db->one(
            'SELECT d.*, g.name AS grade_name FROM divisions d JOIN grades g ON g.id = d.grade_id
              WHERE d.school_id = ? AND d.id = ?',
            [$schoolId, $divisionId],
        ) ?? throw HttpError::notFound('Class not found.');
    }

    /** May this user see or act on this class? Leaders: any. Teachers: their own. Parents: their children's. */
    public function requireDivision(Request $req, int $divisionId, bool $write = false): array
    {
        $t = $req->tenant;
        $division = $this->division($t->schoolId, $divisionId);
        if ($t->hasRole(Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD)) return $division;
        if ($t->hasRole(Roles::TEACHER) && in_array($divisionId, $this->teacherDivisionIds($req), true)) return $division;
        if (!$write && $t->hasRole(Roles::TEACHER)) return $division;
        if (!$write && in_array($divisionId, $this->childDivisionIds($req), true)) return $division;
        throw HttpError::forbidden('This is not your class.');
    }

    public function requireStudent(Request $req, int $studentId): array
    {
        $t = $req->tenant;
        $student = $this->db->one('SELECT * FROM students WHERE school_id = ? AND id = ?', [$t->schoolId, $studentId])
            ?? throw HttpError::notFound('Student not found.');
        if ($t->hasRole(...Roles::ACADEMIC, ...[Roles::ACCOUNTS])) return $student;
        if (in_array($studentId, $this->childIds($req), true)) return $student;
        throw HttpError::forbidden();
    }
}
