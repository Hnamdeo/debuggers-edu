<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Input, Module, Request, Response, Roles, Router, Services, Students};

/**
 * Admissions: parents apply online from the school's link (or the office adds a walk-in),
 * the office registers the application (form + registration fee), then admits the child
 * into a section. Admitting creates the student and links both parents in one step.
 */
final class AdmissionsModule implements Module
{
    public const STATUSES = ['enquiry', 'registered', 'admitted', 'not_admitted'];
    /** Optional form fields kept in the details JSON (same keys on students.details). */
    public const DETAIL_FIELDS = [
        'bloodGroup' => 5, 'category' => 20, 'religion' => 40, 'motherTongue' => 40, 'nationality' => 40,
        'previousClass' => 40, 'tcNumber' => 40, 'fatherOccupation' => 80, 'motherOccupation' => 80,
        'guardianName' => 120, 'guardianPhone' => 15, 'transport' => 3, 'siblingInSchool' => 120,
        'medicalNotes' => 500, 'rte' => 3, 'howHeard' => 80,
    ];
    private const ONLINE_PER_IP_PER_HOUR = 10;

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'admissions'; }

    public function permissions(): array
    {
        return [
            'admissions.view' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS],
            'admissions.manage' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACCOUNTS],
            'admissions.admit' => [Roles::ADMIN, Roles::PRINCIPAL],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $r->get('/public/schools/{slug}/admission-form', $this->publicForm(...), ['auth' => 'none']);
        $r->post('/public/schools/{slug}/admissions', $this->apply(...), ['auth' => 'none']);
        $b = '/schools/{schoolId}/admissions';
        $r->get($b, $this->list(...), ['perm' => 'admissions.view']);
        $r->post($b, $this->create(...), ['perm' => 'admissions.manage']);
        $r->get("$b/{admissionId}", $this->get(...), ['perm' => 'admissions.view']);
        $r->patch("$b/{admissionId}", $this->update(...), ['perm' => 'admissions.manage']);
        $r->post("$b/{admissionId}/admit", $this->admit(...), ['perm' => 'admissions.admit']);
    }

    // ---------- public (parents) ----------

    private function publicSchool(Request $req): array
    {
        $school = $this->s->db->one('SELECT * FROM schools WHERE slug = ?', [strtolower($req->param('slug'))])
            ?? throw HttpError::notFound('We could not find this school. Please check the link.');
        return $school;
    }

    private function publicForm(Request $req): array
    {
        $school = $this->publicSchool($req);
        $open = PlatformModule::settings($school)['admissionsOpen'] ?? false;
        return [
            'school' => ['name' => $school['name'], 'slug' => $school['slug'], 'logoUrl' => $school['logo_url'], 'color' => $school['color'], 'city' => $school['city'], 'phone' => $school['phone']],
            'open' => $open && $school['status'] !== 'paused',
            'grades' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'stage' => $g['stage']],
                $this->s->db->all('SELECT id, name, stage FROM grades WHERE school_id = ? ORDER BY sort_order, name', [$school['id']])),
            'years' => $this->yearChoices((int)$school['id']),
        ];
    }

    /** This year and next, e.g. ["2026-27", "2027-28"] (admissions for next year start mid-year). */
    private function yearChoices(int $schoolId): array
    {
        $current = $this->s->db->value('SELECT name FROM academic_years WHERE school_id = ? AND is_current = 1', [$schoolId]);
        $start = $current && preg_match('/^(\d{4})/', (string)$current, $m) ? (int)$m[1] : (int)SetupModule::yearFor(new \DateTimeImmutable('now'))['name'];
        return [sprintf('%d-%02d', $start, ($start + 1) % 100), sprintf('%d-%02d', $start + 1, ($start + 2) % 100)];
    }

    /** Online application from a parent. No sign-in; protected by a hidden field and a per-IP limit. */
    private function apply(Request $req): Response
    {
        $school = $this->publicSchool($req);
        if ($school['status'] === 'paused' || !(PlatformModule::settings($school)['admissionsOpen'] ?? false)) {
            throw HttpError::forbidden('Online admission is closed right now. Please contact the school office.');
        }
        $in = $req->input();
        $ip = $this->ip($req);
        if ($in->has('website')) return Response::created(['applicationNo' => 'ok']); // bots fill hidden fields
        $recent = (int)$this->s->db->value('SELECT COUNT(*) FROM admissions WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]);
        if ($recent >= self::ONLINE_PER_IP_PER_HOUR) throw new HttpError(429, 'Too many forms from this connection. Please try again later.');
        $row = $this->row($in, (int)$school['id'], false);
        if (!$row['father_phone'] && !$row['mother_phone']) throw HttpError::badRequest("Please give the father's or mother's mobile number.", ['field' => 'fatherPhone']);
        $id = $this->insert((int)$school['id'], $row + ['source' => 'online', 'ip' => $ip]);
        $this->s->events->emit('admission.applied', ['id' => $id, 'school_id' => (int)$school['id'], 'source' => 'online'], $req);
        $no = $this->s->db->value('SELECT application_no FROM admissions WHERE id = ?', [$id]);
        return Response::created(['applicationNo' => $no]);
    }

    // ---------- office ----------

    private function list(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $where = ['a.school_id = ?'];
        $args = [$schoolId];
        $status = $req->query['status'] ?? 'open';
        if ($status === 'open') $where[] = "a.status IN ('enquiry','registered')";
        elseif (in_array($status, self::STATUSES, true)) { $where[] = 'a.status = ?'; $args[] = $status; }
        if (!empty($req->query['gradeId'])) { $where[] = 'a.grade_id = ?'; $args[] = (int)$req->query['gradeId']; }
        if (($q = trim((string)($req->query['q'] ?? ''))) !== '') {
            $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.application_no = ? OR a.father_phone = ? OR a.mother_phone = ?)';
            array_push($args, "$q%", "$q%", strtoupper($q), $q, $q);
        }
        $rows = $this->s->db->all(
            'SELECT a.*, g.name AS grade FROM admissions a LEFT JOIN grades g ON g.id = a.grade_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY a.created_at DESC LIMIT 500',
            $args,
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->s->db->all('SELECT status, COUNT(*) AS n FROM admissions WHERE school_id = ? GROUP BY status', [$schoolId]) as $c) {
            $counts[$c['status']] = (int)$c['n'];
        }
        return ['admissions' => array_map(self::out(...), $rows), 'counts' => $counts];
    }

    private function create(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $row = $this->row($in, $schoolId, false) + ['source' => 'office'];
        foreach ($this->officeFields($in) as $k => $v) $row[$k] = $v;
        $id = $this->insert($schoolId, $row);
        $this->s->events->emit('admission.applied', ['id' => $id, 'source' => 'office'], $req);
        return Response::created(['admission' => $this->load($schoolId, $id)]);
    }

    private function get(Request $req): array
    {
        return ['admission' => $this->load($req->tenant->schoolId, $req->intParam('admissionId'))];
    }

    private function update(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('admissionId');
        $current = $this->load($schoolId, $id);
        $in = $req->input();
        $row = array_filter($this->row($in, $schoolId, true), fn($v) => $v !== null);
        if (isset($row['details'])) {
            $row['details'] = json_encode(array_filter(json_decode($row['details'], true) + $current['details'], fn($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE);
        }
        $row += $this->officeFields($in);
        $status = $in->oneOf('status', ['enquiry', 'registered', 'not_admitted'], false);
        if ($status) {
            if ($current['status'] === 'admitted') throw HttpError::conflict('This child is already admitted.');
            $row['status'] = $status;
        }
        $this->s->db->update('admissions', $schoolId, $id, $row);
        $this->s->events->emit('admission.updated', ['id' => $id] + ($status ? ['status' => $status] : []), $req);
        return ['admission' => $this->load($schoolId, $id)];
    }

    /** Body: { divisionId, admissionNo?, rollNo? }. Creates the student, enrols them, links the parents. */
    private function admit(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('admissionId');
        $a = $this->load($schoolId, $id);
        if ($a['status'] === 'admitted') throw HttpError::conflict('This child is already admitted.');
        $in = $req->input();
        $divisionId = (int)$in->int('divisionId');
        $students = new Students($this->s);
        $db = $this->s->db;
        $result = $db->tx(function () use ($db, $students, $schoolId, $id, $a, $in, $divisionId, $req) {
            $admissionNo = $in->str('admissionNo', 30, false) ?? $students->nextAdmissionNo($schoolId);
            $studentId = $students->create($schoolId, [
                'admission_no' => $admissionNo, 'first_name' => $a['firstName'], 'last_name' => $a['lastName'],
                'gender' => $a['gender'], 'dob' => $a['dob'], 'admitted_on' => date('Y-m-d'),
                'details' => json_encode($a['details'] + array_filter([
                    'address' => $a['address'], 'previousSchool' => $a['previousSchool'], 'applicationNo' => $a['applicationNo'],
                ]), JSON_UNESCAPED_UNICODE),
            ]);
            $students->enrol($schoolId, $studentId, $divisionId, $in->int('rollNo', false, 1));
            $links = [];
            foreach ([['father', $a['fatherName'], $a['fatherPhone']], ['mother', $a['motherName'], $a['motherPhone']]] as [$relation, $name, $phone]) {
                $email = $relation === 'father' && !$a['motherPhone'] ? $a['email'] : null;
                if (!$phone && !$email) continue;
                $link = $students->linkGuardian($req, $studentId, $name ?: ucfirst($relation), $phone, $email, $relation);
                $links[] = ['relation' => $relation, 'name' => $name ?: ucfirst($relation), 'phone' => $phone, 'inviteLink' => $link];
            }
            $db->update('admissions', $schoolId, $id, ['status' => 'admitted', 'student_id' => $studentId]);
            $this->s->events->emit('student.admitted', ['id' => $studentId, 'admission_no' => $admissionNo, 'from_application' => $id], $req);
            return ['studentId' => $studentId, 'admissionNo' => $admissionNo, 'parents' => $links];
        });
        return Response::created($result);
    }

    // ---------- helpers ----------

    /** Form fields shared by the online form and the office. $partial: only what was sent. */
    private function row(Input $in, int $schoolId, bool $partial): array
    {
        $gradeId = $in->int('gradeId', false);
        if ($gradeId) $this->s->db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $gradeId]) ?? throw HttpError::badRequest('Please choose a class.', ['field' => 'gradeId']);
        $year = $in->str('academicYear', 20, false);
        if ($year && !preg_match('/^\d{4}-\d{2}$/', $year)) throw HttpError::badRequest('Year looks like 2027-28.', ['field' => 'academicYear']);
        $details = [];
        foreach (self::DETAIL_FIELDS as $key => $max) {
            $v = $in->str($key, $max, false);
            if ($v !== null) $details[$key] = $v;
        }
        $dob = $in->date('dob', false);
        if ($dob && ($dob > date('Y-m-d') || $dob < date('Y-m-d', strtotime('-20 years')))) throw HttpError::badRequest('Please check the date of birth.', ['field' => 'dob']);
        return [
            'grade_id' => $gradeId, 'academic_year' => $year,
            'first_name' => $in->str('firstName', 80, !$partial), 'last_name' => $in->str('lastName', 80, false),
            'gender' => $in->oneOf('gender', ['girl', 'boy', 'other'], false), 'dob' => $dob,
            'father_name' => $in->str('fatherName', 120, false), 'father_phone' => $in->phone('fatherPhone', false),
            'mother_name' => $in->str('motherName', 120, false), 'mother_phone' => $in->phone('motherPhone', false),
            'email' => $in->email('email', false), 'address' => $in->str('address', 255, false),
            'previous_school' => $in->str('previousSchool', 160, false),
            'details' => $details || !$partial ? json_encode((object)$details, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /** Fields only the office fills: fee, receipt, follow-up date, notes. */
    private function officeFields(Input $in): array
    {
        return array_filter([
            'registration_fee' => $in->int('registrationFee', false), 'receipt_no' => $in->str('receiptNo', 30, false),
            'follow_up_on' => $in->date('followUpOn', false), 'notes' => $in->str('notes', 3000, false),
        ], fn($v) => $v !== null);
    }

    /** Inserts with the next application number for the year, e.g. ADM-27-0001. */
    private function insert(int $schoolId, array $row): int
    {
        $db = $this->s->db;
        return $db->tx(function () use ($db, $schoolId, $row) {
            $db->one('SELECT id FROM schools WHERE id = ? FOR UPDATE', [$schoolId]); // one number at a time per school
            $prefix = 'ADM-' . date('y') . '-';
            $last = (string)$db->value('SELECT MAX(application_no) FROM admissions WHERE school_id = ? AND application_no LIKE ?', [$schoolId, "$prefix%"]);
            $next = $last ? (int)substr($last, strlen($prefix)) + 1 : 1;
            return $db->insert('admissions', ['school_id' => $schoolId, 'application_no' => $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT)] + $row);
        });
    }

    private function load(int $schoolId, int $id): array
    {
        $a = $this->s->db->one('SELECT a.*, g.name AS grade FROM admissions a LEFT JOIN grades g ON g.id = a.grade_id WHERE a.school_id = ? AND a.id = ?', [$schoolId, $id])
            ?? throw HttpError::notFound('Application not found.');
        return self::out($a);
    }

    public static function out(array $a): array
    {
        return [
            'id' => (int)$a['id'], 'applicationNo' => $a['application_no'], 'status' => $a['status'], 'source' => $a['source'],
            'academicYear' => $a['academic_year'], 'gradeId' => $a['grade_id'] ? (int)$a['grade_id'] : null, 'grade' => $a['grade'] ?? null,
            'firstName' => $a['first_name'], 'lastName' => $a['last_name'], 'name' => trim($a['first_name'] . ' ' . ($a['last_name'] ?? '')),
            'gender' => $a['gender'], 'dob' => $a['dob'],
            'fatherName' => $a['father_name'], 'fatherPhone' => $a['father_phone'],
            'motherName' => $a['mother_name'], 'motherPhone' => $a['mother_phone'],
            'email' => $a['email'], 'address' => $a['address'], 'previousSchool' => $a['previous_school'],
            'details' => $a['details'] ? (json_decode($a['details'], true) ?: []) : [],
            'registrationFee' => $a['registration_fee'] !== null ? (int)$a['registration_fee'] : null, 'receiptNo' => $a['receipt_no'],
            'followUpOn' => $a['follow_up_on'], 'notes' => $a['notes'],
            'studentId' => $a['student_id'] ? (int)$a['student_id'] : null, 'createdAt' => $a['created_at'],
        ];
    }

    private function ip(Request $req): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? $req->header('x-test-ip') ?: 'cli'), 0, 45);
    }
}
