<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Creating a child record, placing them in a section and linking parents. Shared by People
 * (direct add and Excel import) and Admissions (admit an applicant), so both do it the same way.
 */
final class Students
{
    public function __construct(private readonly Services $s) {}

    /** Inserts the student row (school_id added here) and returns the new id. */
    public function create(int $schoolId, array $row): int
    {
        $row['admitted_on'] ??= date('Y-m-d');
        return $this->s->db->insert('students', ['school_id' => $schoolId] + $row);
    }

    /** Put a student in a section for that section's year (or move them). */
    public function enrol(int $schoolId, int $studentId, int $divisionId, ?int $rollNo): void
    {
        $division = (new Access($this->s->db))->division($schoolId, $divisionId);
        $this->s->db->exec(
            'INSERT INTO enrolments (school_id, academic_year_id, student_id, division_id, roll_no) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE division_id = VALUES(division_id), roll_no = VALUES(roll_no)',
            [$schoolId, $division['academic_year_id'], $studentId, $divisionId, $rollNo],
        );
    }

    /** Adds a parent to a child; returns a one-time sign-up link to send on WhatsApp (null if they already have a password). */
    public function linkGuardian(Request $req, int $studentId, string $name, ?string $phone, ?string $email, string $relation): ?string
    {
        $schoolId = $req->tenant->schoolId;
        $users = new Users($this->s->db);
        $userId = $users->findOrCreate($name, $phone, $email);
        $users->addRole($schoolId, $userId, Roles::PARENT);
        $this->s->db->exec(
            'INSERT INTO student_guardians (school_id, student_id, user_id, relation) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE relation = VALUES(relation)',
            [$schoolId, $studentId, $userId, $relation],
        );
        return $this->inviteLink($req, $userId);
    }

    /** A fresh sign-up link, or null when the person already set a password. */
    public function inviteLink(Request $req, int $userId): ?string
    {
        if ((new Users($this->s->db))->hasPassword($userId)) return null;
        return Auth::inviteLink($this->s->auth->createInvite($userId, $req->tenant->schoolId, $req->userId()), $req->tenant->school['slug']);
    }

    /** Next free admission number: one more than the biggest all-digit number, else 1. */
    public function nextAdmissionNo(int $schoolId): string
    {
        $max = (int)$this->s->db->value(
            "SELECT MAX(CAST(admission_no AS UNSIGNED)) FROM students WHERE school_id = ? AND admission_no REGEXP '^[0-9]+$'",
            [$schoolId],
        );
        return (string)($max + 1);
    }
}
