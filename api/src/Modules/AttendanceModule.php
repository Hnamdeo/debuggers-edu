<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Module, Request, Roles, Router, Services};

/** Daily attendance: the class teacher marks, everyone is "present" until tapped otherwise. */
final class AttendanceModule implements Module
{
    public const STATUSES = ['present', 'absent', 'late', 'leave'];
    private const EDIT_DAYS_BACK = 7; // teachers may fix the last week; leaders any day

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'attendance'; }

    public function permissions(): array
    {
        return [
            'attendance.mark' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER],
            'attendance.overview' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/attendance';
        $r->get("$b/divisions/{divisionId}", $this->sheet(...));
        $r->put("$b/divisions/{divisionId}", $this->mark(...), ['perm' => 'attendance.mark']);
        $r->get("$b/summary", $this->summary(...), ['perm' => 'attendance.overview']);
        $r->get("$b/students/{studentId}", $this->studentMonth(...));
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    }

    private function dateFrom(Request $req, ?string $value): string
    {
        $date = $value ?: self::today();
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) throw HttpError::badRequest('Date must look like 2026-11-07.');
        if ($date > self::today()) throw HttpError::badRequest('You cannot mark attendance for a future day.');
        return $date;
    }

    /** The class list for a day, with each child's mark (or none yet). */
    private function sheet(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $divisionId = $req->intParam('divisionId');
        $division = (new Access($this->s->db))->requireDivision($req, $divisionId);
        if ($req->tenant->hasRole(Roles::PARENT) && !$req->tenant->hasRole(...Roles::EMPLOYEES)) throw HttpError::forbidden();
        $date = $this->dateFrom($req, $req->query['date'] ?? null);
        $rows = $this->s->db->all(
            'SELECT s.id, s.first_name, s.last_name, s.gender, e.roll_no, a.status, a.marked_at
               FROM enrolments e JOIN students s ON s.id = e.student_id AND s.status = "active"
               LEFT JOIN student_attendance a ON a.student_id = s.id AND a.on_date = ?
              WHERE e.school_id = ? AND e.division_id = ?
              ORDER BY e.roll_no IS NULL, e.roll_no, s.first_name',
            [$date, $schoolId, $divisionId],
        );
        $marked = array_filter($rows, fn($r) => $r['status'] !== null);
        return [
            'division' => ['id' => $divisionId, 'label' => $division['grade_name'] . ' ' . $division['name']],
            'date' => $date,
            'marked' => count($marked) > 0,
            'canEdit' => $this->canEdit($req, $divisionId, $date),
            'students' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
                'rollNo' => $r['roll_no'] !== null ? (int)$r['roll_no'] : null, 'gender' => $r['gender'], 'status' => $r['status'],
            ], $rows),
        ];
    }

    private function canEdit(Request $req, int $divisionId, string $date): bool
    {
        $t = $req->tenant;
        if ($t->hasRole(Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD)) return true;
        if (!$t->hasRole(Roles::TEACHER)) return false;
        $oldest = (new \DateTimeImmutable(self::today()))->modify('-' . self::EDIT_DAYS_BACK . ' days')->format('Y-m-d');
        return $date >= $oldest && in_array($divisionId, (new Access($this->s->db))->teacherDivisionIds($req), true);
    }

    /** Save the whole class in one go. Body: { date, marks: [{studentId, status}] }. Children not listed are present. */
    private function mark(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $divisionId = $req->intParam('divisionId');
        $in = $req->input();
        $date = $this->dateFrom($req, $in->str('date', 10, false));
        (new Access($this->s->db))->requireDivision($req, $divisionId, write: true);
        if (!$this->canEdit($req, $divisionId, $date)) throw HttpError::forbidden('Only the class teacher can mark this class, up to 7 days back.');
        $db = $this->s->db;
        $enrolled = array_map('intval', array_column($db->all(
            'SELECT e.student_id FROM enrolments e JOIN students s ON s.id = e.student_id AND s.status = "active" WHERE e.school_id = ? AND e.division_id = ?',
            [$schoolId, $divisionId],
        ), 'student_id'));
        if (!$enrolled) throw HttpError::badRequest('This class has no children yet.');
        $statuses = array_fill_keys($enrolled, 'present');
        foreach ($in->list('marks') as $mark) {
            $sid = (int)($mark['studentId'] ?? 0);
            $status = (string)($mark['status'] ?? '');
            if (!isset($statuses[$sid])) throw HttpError::badRequest('A child in the list is not in this class.');
            if (!in_array($status, self::STATUSES, true)) throw HttpError::badRequest('Unknown attendance mark.');
            $statuses[$sid] = $status;
        }
        $db->tx(function () use ($db, $statuses, $schoolId, $divisionId, $date, $req) {
            $values = [];
            $args = [];
            foreach ($statuses as $sid => $status) {
                $values[] = '(?, ?, ?, ?, ?, ?)';
                array_push($args, $schoolId, $divisionId, $sid, $date, $status, $req->userId());
            }
            $db->exec(
                'INSERT INTO student_attendance (school_id, division_id, student_id, on_date, status, marked_by) VALUES ' . implode(', ', $values) . '
                 ON DUPLICATE KEY UPDATE status = VALUES(status), division_id = VALUES(division_id), marked_by = VALUES(marked_by)',
                $args,
            );
        });
        $absent = array_keys(array_filter($statuses, fn($s) => $s === 'absent'));
        $counts = array_count_values($statuses);
        // A future Messages module listens to this to tell parents of absent children
        $this->s->events->emit('attendance.marked', [
            'id' => $divisionId, 'date' => $date, 'absent_student_ids' => $absent, 'counts' => $counts,
        ], $req);
        return ['ok' => true, 'date' => $date, 'counts' => $counts + array_fill_keys(self::STATUSES, 0)];
    }

    /** Whole-school view for the principal: which classes are marked today and how many are absent. */
    private function summary(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $date = $this->dateFrom($req, $req->query['date'] ?? null);
        $yearId = (new Access($this->s->db))->currentYearId($schoolId);
        $rows = $yearId ? $this->s->db->all(
            'SELECT d.id, CONCAT(g.name, " ", d.name) AS label, sf.name AS teacher,
                    (SELECT COUNT(*) FROM enrolments e JOIN students s ON s.id = e.student_id AND s.status = "active" WHERE e.division_id = d.id) AS total,
                    SUM(a.status = "present") AS present, SUM(a.status = "absent") AS absent,
                    SUM(a.status = "late") AS late, SUM(a.status = "leave") AS on_leave
               FROM divisions d JOIN grades g ON g.id = d.grade_id
               LEFT JOIN staff sf ON sf.id = d.class_teacher_staff_id
               LEFT JOIN student_attendance a ON a.division_id = d.id AND a.on_date = ?
              WHERE d.school_id = ? AND d.academic_year_id = ?
              GROUP BY d.id, g.sort_order, g.name, d.name, sf.name ORDER BY g.sort_order, d.name',
            [$date, $schoolId, $yearId],
        ) : [];
        $classes = array_map(fn($r) => [
            'divisionId' => (int)$r['id'], 'label' => $r['label'], 'teacher' => $r['teacher'], 'total' => (int)$r['total'],
            'marked' => $r['present'] !== null, 'present' => (int)$r['present'] + (int)$r['late'],
            'absent' => (int)$r['absent'], 'leave' => (int)$r['on_leave'],
        ], $rows);
        $withKids = array_filter($classes, fn($c) => $c['total'] > 0);
        $marked = array_filter($withKids, fn($c) => $c['marked']);
        $present = array_sum(array_column($marked, 'present'));
        $total = array_sum(array_column($marked, 'total'));
        return [
            'date' => $date,
            'classes' => $classes,
            'classesMarked' => count($marked),
            'classesToMark' => count($withKids) - count($marked),
            'presentPercent' => $total ? (int)round($present * 100 / $total) : null,
        ];
    }

    /** A child's month, for parents and teachers. ?month=2026-11 */
    private function studentMonth(Request $req): array
    {
        $student = (new Access($this->s->db))->requireStudent($req, $req->intParam('studentId'));
        $month = (string)($req->query['month'] ?? substr(self::today(), 0, 7));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw HttpError::badRequest('Month must look like 2026-11.');
        $rows = $this->s->db->all(
            'SELECT on_date, status FROM student_attendance WHERE school_id = ? AND student_id = ? AND on_date BETWEEN ? AND LAST_DAY(?) ORDER BY on_date',
            [$req->tenant->schoolId, $student['id'], "$month-01", "$month-01"],
        );
        $counts = array_count_values(array_column($rows, 'status')) + array_fill_keys(self::STATUSES, 0);
        $days = count($rows);
        return [
            'month' => $month,
            'days' => array_map(fn($r) => ['date' => $r['on_date'], 'status' => $r['status']], $rows),
            'counts' => $counts,
            'presentPercent' => $days ? (int)round(($counts['present'] + $counts['late']) * 100 / $days) : null,
        ];
    }
}
