<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Db, Events, HttpError, Module, Request, Response, Roles, Router, Services};

/** Academic years, classes (grades), sections (divisions), subjects and the school's own look. */
final class SetupModule implements Module
{
    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'setup'; }

    public function permissions(): array
    {
        return [
            'setup.view' => Roles::ALL,
            'setup.manage' => [Roles::ADMIN, Roles::PRINCIPAL],
        ];
    }

    public function listen(Events $events): void
    {
        $events->on('school.created', fn(array $e) => $this->applyTemplate((int)$e['school_id'], (string)$e['template']));
    }

    public function routes(Router $r): void
    {
        $base = '/schools/{schoolId}';
        $r->get("$base/setup", $this->overview(...), ['perm' => 'setup.view']);
        $r->patch("$base/settings", $this->updateSettings(...), ['perm' => 'setup.manage']);
        $r->post("$base/academic-years", $this->addYear(...), ['perm' => 'setup.manage']);
        $r->post("$base/academic-years/{yearId}/current", $this->makeCurrent(...), ['perm' => 'setup.manage']);
        $r->post("$base/grades", $this->addGrade(...), ['perm' => 'setup.manage']);
        $r->patch("$base/grades/{gradeId}", $this->updateGrade(...), ['perm' => 'setup.manage']);
        $r->delete("$base/grades/{gradeId}", $this->deleteGrade(...), ['perm' => 'setup.manage']);
        $r->post("$base/divisions", $this->addDivision(...), ['perm' => 'setup.manage']);
        $r->patch("$base/divisions/{divisionId}", $this->updateDivision(...), ['perm' => 'setup.manage']);
        $r->delete("$base/divisions/{divisionId}", $this->deleteDivision(...), ['perm' => 'setup.manage']);
        $r->post("$base/subjects", $this->addSubject(...), ['perm' => 'setup.manage']);
        $r->delete("$base/subjects/{subjectId}", $this->deleteSubject(...), ['perm' => 'setup.manage']);
    }

    /** Indian school year: April to March. */
    public static function yearFor(\DateTimeImmutable $d): array
    {
        $y = (int)$d->format('n') >= 4 ? (int)$d->format('Y') : (int)$d->format('Y') - 1;
        return ['name' => sprintf('%d-%02d', $y, ($y + 1) % 100), 'starts_on' => "$y-04-01", 'ends_on' => ($y + 1) . '-03-31'];
    }

    public static function templateGrades(string $template): array
    {
        $pre = ['Nursery', 'Jr KG', 'Sr KG'];
        $classes = fn(int $to) => array_map(fn($n) => "Class $n", range(1, $to));
        return match ($template) {
            'preschool' => ['Playgroup', ...$pre],
            'primary' => [...$pre, ...$classes(5)],
            'k10' => [...$pre, ...$classes(10)],
            'k12' => [...$pre, ...$classes(12)],
            default => [],
        };
    }

    public const STAGES = ['pre_primary', 'primary', 'middle', 'secondary', 'higher_secondary'];

    /** Class group from a class name: "Jr KG" -> pre_primary, "Class 7" -> middle. Null if unknown. */
    public static function stageFor(string $gradeName): ?string
    {
        $n = strtolower($gradeName);
        if (preg_match('/play|nursery|pre|kg|lkg|ukg/', $n)) return 'pre_primary';
        if (!preg_match('/(\d+)/', $n, $m)) return null;
        $c = (int)$m[1];
        return match (true) {
            $c >= 1 && $c <= 5 => 'primary',
            $c >= 6 && $c <= 8 => 'middle',
            $c >= 9 && $c <= 10 => 'secondary',
            $c >= 11 && $c <= 12 => 'higher_secondary',
            default => null,
        };
    }

    public static function templateSubjects(string $template): array
    {
        if ($template === 'empty') return [];
        if ($template === 'preschool') return ['English', 'Hindi', 'Numbers', 'Rhymes and Stories', 'Art and Craft'];
        $s = ['English', 'Hindi', 'Mathematics', 'EVS', 'Computer', 'Art and Craft', 'Physical Education'];
        if ($template !== 'primary') array_push($s, 'Science', 'Social Science', 'Sanskrit');
        return $s;
    }

    private function applyTemplate(int $schoolId, string $template): void
    {
        $db = $this->s->db;
        $year = self::yearFor(new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')));
        $yearId = $db->insert('academic_years', ['school_id' => $schoolId, 'is_current' => 1] + $year);
        foreach (self::templateGrades($template) as $i => $name) {
            $gradeId = $db->insert('grades', ['school_id' => $schoolId, 'name' => $name, 'stage' => self::stageFor($name), 'sort_order' => ($i + 1) * 10]);
            $db->insert('divisions', ['school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_id' => $gradeId, 'name' => 'A']);
        }
        foreach (self::templateSubjects($template) as $name) {
            $db->insert('subjects', ['school_id' => $schoolId, 'name' => $name]);
        }
    }

    private function overview(Request $req): array
    {
        $id = $req->tenant->schoolId;
        $db = $this->s->db;
        $yearId = (new Access($db))->currentYearId($id);
        return [
            'school' => PlatformModule::schoolOut($req->tenant->school),
            'years' => array_map(fn($y) => [
                'id' => (int)$y['id'], 'name' => $y['name'], 'startsOn' => $y['starts_on'], 'endsOn' => $y['ends_on'], 'isCurrent' => (bool)$y['is_current'],
            ], $db->all('SELECT * FROM academic_years WHERE school_id = ? ORDER BY starts_on DESC', [$id])),
            'grades' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name'], 'stage' => $g['stage'], 'sortOrder' => (int)$g['sort_order']],
                $db->all('SELECT * FROM grades WHERE school_id = ? ORDER BY sort_order, name', [$id])),
            'divisions' => self::divisions($db, $id, $yearId),
            'subjects' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']],
                $db->all('SELECT * FROM subjects WHERE school_id = ? ORDER BY name', [$id])),
        ];
    }

    public static function divisions(Db $db, int $schoolId, ?int $yearId): array
    {
        if (!$yearId) return [];
        return array_map(fn($d) => [
            'id' => (int)$d['id'], 'gradeId' => (int)$d['grade_id'], 'name' => $d['name'], 'stage' => $d['stage'],
            'label' => $d['grade_name'] . ' ' . $d['name'],
            'classTeacher' => $d['teacher_id'] ? ['id' => (int)$d['teacher_id'], 'name' => $d['teacher_name']] : null,
            'students' => (int)$d['students'],
        ], $db->all(
            'SELECT d.*, g.name AS grade_name, g.stage, s.id AS teacher_id, s.name AS teacher_name,
                    (SELECT COUNT(*) FROM enrolments e JOIN students st ON st.id = e.student_id AND st.status = "active"
                      WHERE e.division_id = d.id) AS students
               FROM divisions d JOIN grades g ON g.id = d.grade_id LEFT JOIN staff s ON s.id = d.class_teacher_staff_id
              WHERE d.school_id = ? AND d.academic_year_id = ? ORDER BY g.sort_order, g.name, d.name',
            [$schoolId, $yearId],
        ));
    }

    private function updateSettings(Request $req): array
    {
        $in = $req->input();
        $row = array_filter([
            'name' => $in->str('name', 160, false),
            'address' => $in->str('address', 255, false),
            'phone' => $in->phone('phone', false),
            'logo_url' => $in->str('logoUrl', 255, false),
            'color' => $in->str('color', 7, false),
        ], fn($v) => $v !== null);
        if (isset($row['color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $row['color'])) throw HttpError::badRequest('Colour must look like #6C4CF1.', ['field' => 'color']);
        if (isset($row['logo_url']) && !preg_match('#^https://#', $row['logo_url'])) throw HttpError::badRequest('Logo link must start with https://', ['field' => 'logoUrl']);
        $id = $req->tenant->schoolId;
        if ($row) {
            $this->s->db->exec('UPDATE schools SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
            $this->s->events->emit('school.settings_changed', ['id' => $id] + $row, $req);
        }
        return ['school' => PlatformModule::schoolOut($this->s->db->one('SELECT * FROM schools WHERE id = ?', [$id]))];
    }

    private function addYear(Request $req): Response
    {
        $in = $req->input();
        $start = (string)$in->date('startsOn');
        $end = (string)$in->date('endsOn');
        if ($end <= $start) throw HttpError::badRequest('The year must end after it starts.', ['field' => 'endsOn']);
        $id = $this->s->db->insert('academic_years', [
            'school_id' => $req->tenant->schoolId, 'name' => $in->str('name', 20), 'starts_on' => $start, 'ends_on' => $end,
        ]);
        return Response::created(['id' => $id]);
    }

    /** Switch to a new year. Classes are copied from the last year (sections and class teachers), students are promoted separately. */
    private function makeCurrent(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $yearId = $req->intParam('yearId');
        $db = $this->s->db;
        $db->one('SELECT id FROM academic_years WHERE school_id = ? AND id = ?', [$schoolId, $yearId]) ?? throw HttpError::notFound();
        $previous = (new Access($db))->currentYearId($schoolId);
        $db->tx(function () use ($db, $schoolId, $yearId, $previous) {
            $db->exec('UPDATE academic_years SET is_current = (id = ?) WHERE school_id = ?', [$yearId, $schoolId]);
            if ($previous && $previous !== $yearId && !$db->value('SELECT COUNT(*) FROM divisions WHERE academic_year_id = ?', [$yearId])) {
                $db->exec(
                    'INSERT INTO divisions (school_id, academic_year_id, grade_id, name, class_teacher_staff_id)
                     SELECT school_id, ?, grade_id, name, class_teacher_staff_id FROM divisions WHERE academic_year_id = ?',
                    [$yearId, $previous],
                );
            }
        });
        $this->s->events->emit('year.changed', ['id' => $yearId, 'previous' => $previous], $req);
        return ['ok' => true];
    }

    private function addGrade(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $db = $this->s->db;
        $sort = $in->int('sortOrder', false) ?? (int)$db->value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM grades WHERE school_id = ?', [$schoolId]);
        $id = $db->tx(function () use ($db, $schoolId, $in, $sort) {
            $name = (string)$in->str('name', 40);
            $stage = $in->oneOf('stage', self::STAGES, false) ?? self::stageFor($name);
            $id = $db->insert('grades', ['school_id' => $schoolId, 'name' => $name, 'stage' => $stage, 'sort_order' => $sort]);
            $yearId = (new Access($db))->currentYearId($schoolId);
            if ($yearId) $db->insert('divisions', ['school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_id' => $id, 'name' => 'A']);
            return $id;
        });
        return Response::created(['id' => $id]);
    }

    /** Rename a class ("LKG" -> "Jr KG") or move it to another group. */
    private function updateGrade(Request $req): array
    {
        $in = $req->input();
        $row = array_filter([
            'name' => $in->str('name', 40, false),
            'stage' => $in->oneOf('stage', self::STAGES, false),
            'sort_order' => $in->int('sortOrder', false),
        ], fn($v) => $v !== null);
        $id = $req->intParam('gradeId');
        $this->s->db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $id]) ?? throw HttpError::notFound();
        $this->s->db->update('grades', $req->tenant->schoolId, $id, $row);
        $this->s->events->emit('grade.updated', ['id' => $id] + $row, $req);
        return ['ok' => true];
    }

    private function deleteGrade(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('gradeId');
        $db = $this->s->db;
        $used = $db->value('SELECT COUNT(*) FROM enrolments e JOIN divisions d ON d.id = e.division_id WHERE d.school_id = ? AND d.grade_id = ?', [$schoolId, $id]);
        if ($used) throw HttpError::conflict('This class has students. Move them first.');
        $db->exec('DELETE FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?: throw HttpError::notFound();
        return ['ok' => true];
    }

    private function addDivision(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $db = $this->s->db;
        $gradeId = (int)$in->int('gradeId');
        $db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $gradeId]) ?? throw HttpError::notFound('Class not found.');
        $yearId = (new Access($db))->currentYearId($schoolId) ?? throw HttpError::badRequest('Please add an academic year first.');
        $id = $db->insert('divisions', [
            'school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_id' => $gradeId,
            'name' => strtoupper((string)$in->str('name', 20)),
            'class_teacher_staff_id' => $this->staffId($req, $in->int('classTeacherId', false)),
        ]);
        return Response::created(['id' => $id]);
    }

    private function updateDivision(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('divisionId');
        $in = $req->input();
        $row = [];
        if ($in->has('name')) $row['name'] = strtoupper((string)$in->str('name', 20));
        if (array_key_exists('classTeacherId', $req->body)) $row['class_teacher_staff_id'] = $this->staffId($req, $in->int('classTeacherId', false));
        (new Access($this->s->db))->division($schoolId, $id);
        $this->s->db->update('divisions', $schoolId, $id, $row);
        $this->s->events->emit('division.updated', ['id' => $id] + $row, $req);
        return ['ok' => true];
    }

    private function deleteDivision(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('divisionId');
        $db = $this->s->db;
        if ($db->value('SELECT COUNT(*) FROM enrolments WHERE school_id = ? AND division_id = ?', [$schoolId, $id])) throw HttpError::conflict('This section has students. Move them first.');
        $db->exec('DELETE FROM divisions WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?: throw HttpError::notFound();
        return ['ok' => true];
    }

    private function addSubject(Request $req): Response
    {
        return Response::created(['id' => $this->s->db->insert('subjects', ['school_id' => $req->tenant->schoolId, 'name' => $req->input()->str('name', 60)])]);
    }

    private function deleteSubject(Request $req): array
    {
        $this->s->db->exec('DELETE FROM subjects WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $req->intParam('subjectId')]) ?: throw HttpError::notFound();
        return ['ok' => true];
    }

    private function staffId(Request $req, ?int $staffId): ?int
    {
        if ($staffId === null) return null;
        $this->s->db->one('SELECT id FROM staff WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $staffId]) ?? throw HttpError::notFound('Teacher not found.');
        return $staffId;
    }
}
