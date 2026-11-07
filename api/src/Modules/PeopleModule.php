<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Auth, Events, HttpError, Input, Module, Request, Response, Roles, Router, Services, Users};

/** Admissions and enrolment, parents, and staff (teachers and other employees). */
final class PeopleModule implements Module
{
    private const MAX_IMPORT = 2000;

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'people'; }

    public function permissions(): array
    {
        return [
            'students.view' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER, Roles::ACCOUNTS],
            'students.manage' => [Roles::ADMIN, Roles::PRINCIPAL],
            'staff.view' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::ACCOUNTS],
            'staff.manage' => [Roles::ADMIN, Roles::PRINCIPAL],
            'children.view' => [Roles::PARENT],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}';
        $r->get("$b/students", $this->listStudents(...), ['perm' => 'students.view']);
        $r->post("$b/students", $this->addStudent(...), ['perm' => 'students.manage']);
        $r->post("$b/students/import", $this->importStudents(...), ['perm' => 'students.manage']);
        $r->get("$b/students/{studentId}", $this->getStudent(...));
        $r->patch("$b/students/{studentId}", $this->updateStudent(...), ['perm' => 'students.manage']);
        $r->post("$b/students/{studentId}/guardians", $this->addGuardian(...), ['perm' => 'students.manage']);
        $r->post("$b/students/{studentId}/guardians/{userId}/link", $this->guardianLink(...), ['perm' => 'students.manage']);
        $r->get("$b/my-children", $this->myChildren(...), ['perm' => 'children.view']);
        $r->get("$b/staff", $this->listStaff(...), ['perm' => 'staff.view']);
        $r->post("$b/staff", $this->addStaff(...), ['perm' => 'staff.manage']);
        $r->patch("$b/staff/{staffId}", $this->updateStaff(...), ['perm' => 'staff.manage']);
        $r->post("$b/staff/{staffId}/link", $this->staffLink(...), ['perm' => 'staff.manage']);
    }

    // ---------- students ----------

    private function listStudents(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $access = new Access($this->s->db);
        $yearId = $access->currentYearId($schoolId);
        $where = ['s.school_id = ?'];
        $args = [$yearId, $schoolId];
        $status = $req->query['status'] ?? 'active';
        if ($status !== 'all') { $where[] = 's.status = ?'; $args[] = $status === 'left' ? 'left' : 'active'; }
        if (!empty($req->query['divisionId'])) { $where[] = 'e.division_id = ?'; $args[] = (int)$req->query['divisionId']; }
        if (($q = trim((string)($req->query['q'] ?? ''))) !== '') {
            $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no = ?)';
            array_push($args, "$q%", "$q%", $q);
        }
        $rows = $this->s->db->all(
            'SELECT s.*, e.division_id, e.roll_no, CONCAT(g.name, " ", d.name) AS division_label
               FROM students s
               LEFT JOIN enrolments e ON e.student_id = s.id AND e.academic_year_id = ?
               LEFT JOIN divisions d ON d.id = e.division_id LEFT JOIN grades g ON g.id = d.grade_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY g.sort_order, d.name, e.roll_no, s.first_name LIMIT 1000',
            $args,
        );
        return ['students' => array_map(self::studentOut(...), $rows)];
    }

    public static function studentOut(array $s): array
    {
        return [
            'id' => (int)$s['id'], 'admissionNo' => $s['admission_no'],
            'firstName' => $s['first_name'], 'lastName' => $s['last_name'],
            'name' => trim($s['first_name'] . ' ' . ($s['last_name'] ?? '')),
            'gender' => $s['gender'], 'dob' => $s['dob'], 'admittedOn' => $s['admitted_on'], 'status' => $s['status'],
            'divisionId' => isset($s['division_id']) ? (int)$s['division_id'] : null,
            'division' => $s['division_label'] ?? null,
            'rollNo' => isset($s['roll_no']) ? (int)$s['roll_no'] : null,
        ];
    }

    private function studentRow(Input $in, bool $partial = false): array
    {
        $row = [
            'admission_no' => $in->str('admissionNo', 30, !$partial),
            'first_name' => $in->str('firstName', 80, !$partial),
            'last_name' => $in->str('lastName', 80, false),
            'gender' => $in->oneOf('gender', ['girl', 'boy', 'other'], false),
            'dob' => $in->date('dob', false),
            'admitted_on' => $in->date('admittedOn', false),
        ];
        if ($partial) {
            $row['status'] = $in->oneOf('status', ['active', 'left'], false);
            $row = array_filter($row, fn($v) => $v !== null);
        }
        return $row;
    }

    /** Put a student in a section for the current year (or move them). */
    private function enrol(int $schoolId, int $studentId, int $divisionId, ?int $rollNo): void
    {
        $db = $this->s->db;
        $access = new Access($db);
        $division = $access->division($schoolId, $divisionId);
        $db->exec(
            'INSERT INTO enrolments (school_id, academic_year_id, student_id, division_id, roll_no) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE division_id = VALUES(division_id), roll_no = VALUES(roll_no)',
            [$schoolId, $division['academic_year_id'], $studentId, $divisionId, $rollNo],
        );
    }

    private function addStudent(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $db = $this->s->db;
        $result = $db->tx(function () use ($db, $schoolId, $in, $req) {
            $row = $this->studentRow($in) + ['school_id' => $schoolId];
            $row['admitted_on'] ??= date('Y-m-d');
            $id = $db->insert('students', $row);
            if ($in->has('divisionId')) $this->enrol($schoolId, $id, (int)$in->int('divisionId'), $in->int('rollNo', false, 1));
            $link = null;
            if ($in->has('parentName') && ($in->has('parentPhone') || $in->has('parentEmail'))) {
                $link = $this->linkGuardian($req, $id, (string)$in->str('parentName', 120), $in->phone('parentPhone', false), $in->email('parentEmail', false), $in->oneOf('relation', ['mother', 'father', 'guardian'], false) ?? 'guardian');
            }
            $this->s->events->emit('student.admitted', ['id' => $id, 'admission_no' => $row['admission_no']], $req);
            return ['id' => $id, 'inviteLink' => $link];
        });
        return Response::created($result);
    }

    /**
     * Bulk admission from the Excel template (the app reads the sheet and sends rows).
     * Each row: admissionNo, firstName, lastName?, gender?, dob?, class ("Class 1"), section ("A"),
     * rollNo?, parentName?, parentPhone?, relation?. Rows that fail are reported, not half-saved.
     */
    private function importStudents(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $rows = $req->input()->list('rows');
        if (count($rows) > self::MAX_IMPORT) throw HttpError::badRequest('Please import at most ' . self::MAX_IMPORT . ' children at a time.');
        $db = $this->s->db;
        $yearId = (new Access($db))->currentYearId($schoolId) ?? throw HttpError::badRequest('Please add an academic year first.');
        $divisions = [];
        foreach ($db->all('SELECT d.id, LOWER(g.name) AS grade, UPPER(d.name) AS name FROM divisions d JOIN grades g ON g.id = d.grade_id WHERE d.school_id = ? AND d.academic_year_id = ?', [$schoolId, $yearId]) as $d) {
            $divisions[$d['grade'] . '|' . $d['name']] = (int)$d['id'];
        }
        $added = 0;
        $errors = [];
        foreach ($rows as $i => $raw) {
            $line = $i + 2; // row 1 of the sheet is the header
            try {
                $in = new Input(is_array($raw) ? $raw : []);
                $db->tx(function () use ($db, $in, $schoolId, $divisions, $req) {
                    $row = $this->studentRow($in) + ['school_id' => $schoolId];
                    $id = $db->insert('students', $row);
                    $grade = strtolower((string)$in->str('class', 40, false));
                    if ($grade !== '') {
                        $grade = preg_match('/^\d+$/', $grade) ? "class $grade" : $grade;
                        $key = $grade . '|' . strtoupper($in->str('section', 20, false) ?? 'A');
                        $divisionId = $divisions[$key] ?? throw HttpError::badRequest('Class/section "' . $in->str('class', 40) . ' ' . ($in->str('section', 20, false) ?? 'A') . '" not found in setup.');
                        $this->enrol($schoolId, $id, $divisionId, $in->int('rollNo', false, 1));
                    }
                    if ($in->has('parentName') && ($in->has('parentPhone') || $in->has('parentEmail'))) {
                        $this->linkGuardian($req, $id, (string)$in->str('parentName', 120), $in->phone('parentPhone', false), $in->email('parentEmail', false), $in->oneOf('relation', ['mother', 'father', 'guardian'], false) ?? 'guardian');
                    }
                });
                $added++;
            } catch (HttpError $e) {
                $errors[] = ['row' => $line, 'error' => $e->getMessage()];
            } catch (\PDOException $e) {
                $errors[] = ['row' => $line, 'error' => ($e->errorInfo[1] ?? 0) === 1062 ? 'Admission number already exists.' : 'Could not save this row.'];
            }
        }
        $this->s->events->emit('students.imported', ['added' => $added, 'failed' => count($errors)], $req);
        return ['added' => $added, 'errors' => $errors];
    }

    private function getStudent(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $access = new Access($this->s->db);
        $student = $access->requireStudent($req, $req->intParam('studentId'));
        $db = $this->s->db;
        $enrolment = $db->one(
            'SELECT e.division_id, e.roll_no, CONCAT(g.name, " ", d.name) AS division_label FROM enrolments e
               JOIN divisions d ON d.id = e.division_id JOIN grades g ON g.id = d.grade_id
               JOIN academic_years y ON y.id = e.academic_year_id AND y.is_current = 1
              WHERE e.student_id = ?',
            [$student['id']],
        ) ?? [];
        $guardians = $db->all(
            'SELECT u.id, u.name, u.phone, u.email, sg.relation, u.password_hash IS NOT NULL AS joined
               FROM student_guardians sg JOIN users u ON u.id = sg.user_id WHERE sg.school_id = ? AND sg.student_id = ?',
            [$schoolId, $student['id']],
        );
        $canSeeContacts = $req->tenant->hasRole(...Roles::EMPLOYEES);
        return [
            'student' => self::studentOut($student + $enrolment),
            'guardians' => array_map(fn($g) => [
                'userId' => (int)$g['id'], 'name' => $g['name'], 'relation' => $g['relation'], 'joined' => (bool)$g['joined'],
                'phone' => $canSeeContacts ? $g['phone'] : null, 'email' => $canSeeContacts ? $g['email'] : null,
            ], $guardians),
        ];
    }

    private function updateStudent(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('studentId');
        (new Access($this->s->db))->requireStudent($req, $id);
        $in = $req->input();
        $row = $this->studentRow($in, true);
        $this->s->db->tx(function () use ($schoolId, $id, $row, $in, $req) {
            $this->s->db->update('students', $schoolId, $id, $row);
            if ($in->has('divisionId')) $this->enrol($schoolId, $id, (int)$in->int('divisionId'), $in->int('rollNo', false, 1));
            $this->s->events->emit('student.updated', ['id' => $id] + $row + ($in->has('divisionId') ? ['division_id' => $in->int('divisionId')] : []), $req);
        });
        return $this->getStudent($req);
    }

    /** Adds a parent to a child; returns a one-time sign-up link to send on WhatsApp (null if they already have a password). */
    private function linkGuardian(Request $req, int $studentId, string $name, ?string $phone, ?string $email, string $relation): ?string
    {
        $schoolId = $req->tenant->schoolId;
        $users = new Users($this->s->db);
        $userId = $users->findOrCreate($name, $phone, $email);
        $users->addRole($schoolId, $userId, Roles::PARENT);
        $this->s->db->exec(
            'INSERT INTO student_guardians (school_id, student_id, user_id, relation) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE relation = VALUES(relation)',
            [$schoolId, $studentId, $userId, $relation],
        );
        if ($users->hasPassword($userId)) return null;
        return Auth::inviteLink($this->s->auth->createInvite($userId, $schoolId, $req->userId()), $req->tenant->school['slug']);
    }

    private function addGuardian(Request $req): Response
    {
        $studentId = $req->intParam('studentId');
        (new Access($this->s->db))->requireStudent($req, $studentId);
        $in = $req->input();
        $link = $this->s->db->tx(fn() => $this->linkGuardian(
            $req, $studentId, (string)$in->str('name', 120), $in->phone('phone', false), $in->email('email', false),
            $in->oneOf('relation', ['mother', 'father', 'guardian'], false) ?? 'guardian',
        ));
        $this->s->events->emit('guardian.added', ['id' => $studentId], $req);
        return Response::created(['inviteLink' => $link]);
    }

    /** A fresh sign-up link for a parent who lost theirs. */
    private function guardianLink(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $userId = $req->intParam('userId');
        $this->s->db->one('SELECT id FROM student_guardians WHERE school_id = ? AND student_id = ? AND user_id = ?', [$schoolId, $req->intParam('studentId'), $userId])
            ?? throw HttpError::notFound();
        return ['inviteLink' => $this->freshLink($req, $userId)];
    }

    private function freshLink(Request $req, int $userId): ?string
    {
        if ((new Users($this->s->db))->hasPassword($userId)) return null;
        return Auth::inviteLink($this->s->auth->createInvite($userId, $req->tenant->schoolId, $req->userId()), $req->tenant->school['slug']);
    }

    private function myChildren(Request $req): array
    {
        $ids = (new Access($this->s->db))->childIds($req);
        if (!$ids) return ['children' => []];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->s->db->all(
            "SELECT s.*, e.division_id, e.roll_no, CONCAT(g.name, ' ', d.name) AS division_label
               FROM students s
               LEFT JOIN enrolments e ON e.student_id = s.id AND e.academic_year_id = (SELECT id FROM academic_years WHERE school_id = s.school_id AND is_current = 1)
               LEFT JOIN divisions d ON d.id = e.division_id LEFT JOIN grades g ON g.id = d.grade_id
              WHERE s.school_id = ? AND s.id IN ($in) ORDER BY s.first_name",
            [$req->tenant->schoolId, ...$ids],
        );
        return ['children' => array_map(self::studentOut(...), $rows)];
    }

    // ---------- staff ----------

    private function listStaff(Request $req): array
    {
        $rows = $this->s->db->all(
            'SELECT sf.*, u.password_hash IS NOT NULL AS joined FROM staff sf LEFT JOIN users u ON u.id = sf.user_id
              WHERE sf.school_id = ? AND (sf.status = ? OR ? = "all") ORDER BY sf.name',
            [$req->tenant->schoolId, ($req->query['status'] ?? 'active') === 'left' ? 'left' : 'active', $req->query['status'] ?? 'active'],
        );
        return ['staff' => array_map(self::staffOut(...), $rows)];
    }

    public static function staffOut(array $s): array
    {
        return [
            'id' => (int)$s['id'], 'name' => $s['name'], 'phone' => $s['phone'], 'email' => $s['email'],
            'employeeCode' => $s['employee_code'], 'designation' => $s['designation'], 'department' => $s['department'],
            'role' => $s['role'], 'joinedOn' => $s['joined_on'], 'status' => $s['status'],
            'canSignIn' => $s['user_id'] !== null, 'joined' => (bool)($s['joined'] ?? false),
        ];
    }

    private function addStaff(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $db = $this->s->db;
        $role = $in->oneOf('role', [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER, Roles::ACCOUNTS, Roles::STAFF], false) ?? Roles::TEACHER;
        if (in_array($role, [Roles::ADMIN, Roles::PRINCIPAL], true) && !$req->tenant->hasRole(Roles::ADMIN, Roles::PRINCIPAL)) throw HttpError::forbidden();
        $phone = $in->phone('phone', false);
        $email = $in->email('email', false);
        $canSignIn = $in->has('canSignIn') ? $in->bool('canSignIn') : ($phone || $email);
        $result = $db->tx(function () use ($db, $schoolId, $in, $role, $phone, $email, $canSignIn, $req) {
            $userId = null;
            $name = (string)$in->str('name', 120);
            if ($canSignIn) {
                $users = new Users($db);
                $userId = $users->findOrCreate($name, $phone, $email);
                $users->addRole($schoolId, $userId, $role);
            }
            $id = $db->insert('staff', [
                'school_id' => $schoolId, 'user_id' => $userId, 'name' => $name, 'phone' => $phone, 'email' => $email,
                'employee_code' => $in->str('employeeCode', 30, false), 'designation' => $in->str('designation', 80, false),
                'department' => $in->str('department', 80, false), 'role' => $role, 'joined_on' => $in->date('joinedOn', false),
            ]);
            $this->s->events->emit('staff.added', ['id' => $id, 'role' => $role], $req);
            return ['id' => $id, 'inviteLink' => $userId ? $this->freshLink($req, $userId) : null];
        });
        return Response::created($result);
    }

    private function updateStaff(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('staffId');
        $db = $this->s->db;
        $staff = $db->one('SELECT * FROM staff WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?? throw HttpError::notFound();
        $in = $req->input();
        $row = array_filter([
            'name' => $in->str('name', 120, false), 'designation' => $in->str('designation', 80, false),
            'department' => $in->str('department', 80, false), 'employee_code' => $in->str('employeeCode', 30, false),
            'joined_on' => $in->date('joinedOn', false), 'status' => $in->oneOf('status', ['active', 'left'], false),
            'role' => $in->oneOf('role', [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER, Roles::ACCOUNTS, Roles::STAFF], false),
        ], fn($v) => $v !== null);
        $db->tx(function () use ($db, $schoolId, $id, $row, $staff) {
            $db->update('staff', $schoolId, $id, $row);
            if ($staff['user_id']) {
                $users = new Users($db);
                $userId = (int)$staff['user_id'];
                if (isset($row['role']) && $row['role'] !== $staff['role']) {
                    $users->removeRole($schoolId, $userId, $staff['role']);
                    $users->addRole($schoolId, $userId, $row['role']);
                }
                // Someone who left loses access the same moment
                if (($row['status'] ?? '') === 'left') $users->removeRole($schoolId, $userId, $row['role'] ?? $staff['role']);
                if (($row['status'] ?? '') === 'active' && $staff['status'] === 'left') $users->addRole($schoolId, $userId, $row['role'] ?? $staff['role']);
            }
        });
        $this->s->events->emit('staff.updated', ['id' => $id] + $row, $req);
        return ['staff' => self::staffOut($db->one('SELECT * FROM staff WHERE id = ?', [$id]))];
    }

    private function staffLink(Request $req): array
    {
        $staff = $this->s->db->one('SELECT user_id FROM staff WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $req->intParam('staffId')]) ?? throw HttpError::notFound();
        if (!$staff['user_id']) throw HttpError::badRequest('Add a mobile number or email first.');
        return ['inviteLink' => $this->freshLink($req, (int)$staff['user_id'])];
    }
}
