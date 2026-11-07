<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Input, Module, Request, Response, Roles, Router, Services};

/**
 * Syllabus per class and subject: chapters (units) with lessons in teaching order. Lessons get a
 * day (day-wise plan), can carry a Thinkle Tots lesson code or video link (so a YouTube lesson
 * is one tap from homework), and are ticked off per section when taught.
 */
final class SyllabusModule implements Module
{
    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'syllabus'; }

    public function permissions(): array
    {
        return [
            'syllabus.view' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER, Roles::PARENT],
            'syllabus.manage' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD],
            'syllabus.teach' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/syllabus';
        $r->get($b, $this->show(...), ['perm' => 'syllabus.view']);
        $r->get("$b/overview", $this->overview(...), ['perm' => 'syllabus.teach']);
        $r->get("$b/subjects", $this->subjects(...), ['perm' => 'syllabus.view']);
        $r->get("$b/day", $this->day(...), ['perm' => 'syllabus.view']);
        $r->post("$b/units", $this->addUnit(...), ['perm' => 'syllabus.manage']);
        $r->patch("$b/units/{unitId}", $this->updateUnit(...), ['perm' => 'syllabus.manage']);
        $r->delete("$b/units/{unitId}", $this->deleteUnit(...), ['perm' => 'syllabus.manage']);
        $r->post("$b/units/{unitId}/lessons", $this->addLessons(...), ['perm' => 'syllabus.manage']);
        $r->patch("$b/lessons/{lessonId}", $this->updateLesson(...), ['perm' => 'syllabus.manage']);
        $r->delete("$b/lessons/{lessonId}", $this->deleteLesson(...), ['perm' => 'syllabus.manage']);
        $r->post("$b/plan", $this->plan(...), ['perm' => 'syllabus.manage']);
        $r->put("$b/lessons/{lessonId}/done", $this->markDone(...), ['perm' => 'syllabus.teach']);
    }

    private function yearId(int $schoolId): int
    {
        return (new Access($this->s->db))->currentYearId($schoolId) ?? throw HttpError::badRequest('Please add an academic year first.');
    }

    /** Parents only see their own children's classes; staff see any class. Returns [gradeId, divisionId|null]. */
    private function scope(Request $req): array
    {
        $access = new Access($this->s->db);
        $divisionId = !empty($req->query['divisionId']) ? (int)$req->query['divisionId'] : null;
        if ($divisionId) {
            $division = $access->requireDivision($req, $divisionId);
            return [(int)$division['grade_id'], $divisionId];
        }
        if (!$req->tenant->hasRole(...Roles::ACADEMIC)) throw HttpError::badRequest('Please choose a class.', ['field' => 'divisionId']);
        $gradeId = (int)($req->query['gradeId'] ?? 0) ?: throw HttpError::badRequest('Please choose a class.', ['field' => 'gradeId']);
        return [$gradeId, null];
    }

    /** ?gradeId=&subjectId= (staff) or ?divisionId=&subjectId= (with ticks for that section). */
    private function show(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        [$gradeId, $divisionId] = $this->scope($req);
        $subjectId = (int)($req->query['subjectId'] ?? 0) ?: throw HttpError::badRequest('Please choose a subject.', ['field' => 'subjectId']);
        $db = $this->s->db;
        $units = $db->all(
            'SELECT * FROM syllabus_units WHERE school_id = ? AND academic_year_id = ? AND grade_id = ? AND subject_id = ? ORDER BY sort_order, id',
            [$schoolId, $this->yearId($schoolId), $gradeId, $subjectId],
        );
        $lessons = [];
        if ($units) {
            $ids = array_column($units, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = $db->all(
                "SELECT l.*, p.done_on FROM syllabus_lessons l
                   LEFT JOIN syllabus_progress p ON p.lesson_id = l.id AND p.division_id = ?
                  WHERE l.unit_id IN ($in) ORDER BY l.sort_order, l.id",
                [$divisionId ?? 0, ...$ids],
            );
            foreach ($rows as $l) $lessons[(int)$l['unit_id']][] = self::lessonOut($l);
        }
        $all = array_merge(...array_values($lessons ?: [[]]));
        return [
            'units' => array_map(fn($u) => ['id' => (int)$u['id'], 'title' => $u['title'], 'month' => $u['month'], 'lessons' => $lessons[(int)$u['id']] ?? []], $units),
            'total' => count($all),
            'done' => count(array_filter($all, fn($l) => $l['doneOn'] !== null)),
        ];
    }

    public static function lessonOut(array $l): array
    {
        return [
            'id' => (int)$l['id'], 'unitId' => (int)$l['unit_id'], 'title' => $l['title'], 'details' => $l['details'],
            'plannedOn' => $l['planned_on'], 'lessonCode' => $l['lesson_code'], 'linkUrl' => $l['link_url'],
            'doneOn' => $l['done_on'] ?? null,
        ];
    }

    /** Subjects, for parents (who cannot open school setup). */
    private function subjects(Request $req): array
    {
        return ['subjects' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']],
            $this->s->db->all('SELECT id, name FROM subjects WHERE school_id = ? ORDER BY name', [$req->tenant->schoolId]))];
    }

    /** Lesson counts for every class and subject, so the office sees what is still empty. */
    private function overview(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $rows = $this->s->db->all(
            'SELECT u.grade_id, u.subject_id, COUNT(DISTINCT u.id) AS units, COUNT(l.id) AS lessons, COUNT(l.planned_on) AS planned
               FROM syllabus_units u LEFT JOIN syllabus_lessons l ON l.unit_id = u.id
              WHERE u.school_id = ? AND u.academic_year_id = ? GROUP BY u.grade_id, u.subject_id',
            [$schoolId, $this->yearId($schoolId)],
        );
        return ['classes' => array_map(fn($r) => [
            'gradeId' => (int)$r['grade_id'], 'subjectId' => (int)$r['subject_id'],
            'units' => (int)$r['units'], 'lessons' => (int)$r['lessons'], 'planned' => (int)$r['planned'],
        ], $rows)];
    }

    /** What is planned for one section on one day (?divisionId=&date=), across subjects. */
    private function day(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        [$gradeId, $divisionId] = $this->scope($req);
        $date = (new Input($req->query))->date('date', false) ?? date('Y-m-d');
        $rows = $this->s->db->all(
            'SELECT l.*, sb.name AS subject, u.title AS unit, p.done_on FROM syllabus_lessons l
               JOIN syllabus_units u ON u.id = l.unit_id JOIN subjects sb ON sb.id = u.subject_id
               LEFT JOIN syllabus_progress p ON p.lesson_id = l.id AND p.division_id = ?
              WHERE l.school_id = ? AND u.academic_year_id = ? AND u.grade_id = ? AND l.planned_on = ?
              ORDER BY sb.name, l.sort_order',
            [$divisionId ?? 0, $schoolId, $this->yearId($schoolId), $gradeId, $date],
        );
        return ['date' => $date, 'lessons' => array_map(fn($l) => self::lessonOut($l) + ['subject' => $l['subject'], 'unit' => $l['unit']], $rows)];
    }

    private function addUnit(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $db = $this->s->db;
        $gradeId = (int)$in->int('gradeId');
        $subjectId = (int)$in->int('subjectId');
        $db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $gradeId]) ?? throw HttpError::badRequest('Please choose a class.', ['field' => 'gradeId']);
        $db->one('SELECT id FROM subjects WHERE school_id = ? AND id = ?', [$schoolId, $subjectId]) ?? throw HttpError::badRequest('Please choose a subject.', ['field' => 'subjectId']);
        $yearId = $this->yearId($schoolId);
        $sort = (int)$db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM syllabus_units WHERE school_id = ? AND academic_year_id = ? AND grade_id = ? AND subject_id = ?', [$schoolId, $yearId, $gradeId, $subjectId]);
        $id = $db->insert('syllabus_units', [
            'school_id' => $schoolId, 'academic_year_id' => $yearId, 'grade_id' => $gradeId, 'subject_id' => $subjectId,
            'title' => $in->str('title', 160), 'month' => $in->str('month', 20, false), 'sort_order' => $sort,
        ]);
        $this->s->events->emit('syllabus.unit_added', ['id' => $id], $req);
        return Response::created(['id' => $id]);
    }

    private function unit(int $schoolId, int $id): array
    {
        return $this->s->db->one('SELECT * FROM syllabus_units WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?? throw HttpError::notFound('Chapter not found.');
    }

    private function updateUnit(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('unitId');
        $this->unit($schoolId, $id);
        $in = $req->input();
        $row = array_filter(['title' => $in->str('title', 160, false), 'month' => $in->str('month', 20, false), 'sort_order' => $in->int('sortOrder', false)], fn($v) => $v !== null);
        $this->s->db->update('syllabus_units', $schoolId, $id, $row);
        $this->s->events->emit('syllabus.unit_updated', ['id' => $id] + $row, $req);
        return ['ok' => true];
    }

    private function deleteUnit(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('unitId');
        $this->unit($schoolId, $id);
        $this->s->db->exec('DELETE FROM syllabus_units WHERE school_id = ? AND id = ?', [$schoolId, $id]);
        $this->s->events->emit('syllabus.unit_removed', ['id' => $id], $req);
        return ['ok' => true];
    }

    /**
     * Add lessons to a chapter. Either one lesson { title, details?, plannedOn?, lessonCode?, linkUrl? }
     * or many at once { lines: ["Letter A | ABC-S1-E01", "Letter B | https://youtu.be/..", "Revision"] }
     * (one lesson per line, an optional lesson code or https link after a "|").
     */
    private function addLessons(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $unitId = $req->intParam('unitId');
        $this->unit($schoolId, $unitId);
        $in = $req->input();
        $items = [];
        if ($in->has('lines')) {
            foreach ($in->list('lines') as $i => $line) {
                if (!is_string($line) || trim($line) === '') continue;
                [$title, $extra] = array_map('trim', explode('|', $line, 2) + [1 => '']);
                $item = ['title' => $title];
                if ($extra !== '') $item[str_starts_with($extra, 'http') ? 'linkUrl' : 'lessonCode'] = $extra;
                $items[] = $item;
            }
            if (!$items) throw HttpError::badRequest('Please type at least one lesson.', ['field' => 'lines']);
            if (count($items) > 300) throw HttpError::badRequest('Please add at most 300 lessons at a time.', ['field' => 'lines']);
        } else {
            $items[] = $req->body;
        }
        $db = $this->s->db;
        $fromLines = $in->has('lines');
        $ids = $db->tx(function () use ($db, $schoolId, $unitId, $items, $fromLines) {
            $sort = (int)$db->value('SELECT COALESCE(MAX(sort_order), 0) FROM syllabus_lessons WHERE unit_id = ?', [$unitId]);
            $ids = [];
            foreach ($items as $n => $item) {
                try {
                    $ids[] = $db->insert('syllabus_lessons', $this->lessonRow(new Input($item)) + ['school_id' => $schoolId, 'unit_id' => $unitId, 'sort_order' => ++$sort]);
                } catch (HttpError $e) {
                    throw $fromLines ? HttpError::badRequest('Line ' . ($n + 1) . ': ' . $e->getMessage(), ['field' => 'lines']) : $e;
                }
            }
            return $ids;
        });
        $this->s->events->emit('syllabus.lessons_added', ['unit_id' => $unitId, 'count' => count($ids)], $req);
        return Response::created(['ids' => $ids]);
    }

    private function lessonRow(Input $in, bool $partial = false): array
    {
        $link = $in->str('linkUrl', 500, false);
        if ($link && !preg_match('#^https://#', $link)) throw HttpError::badRequest('Link must start with https://', ['field' => 'linkUrl']);
        $code = $in->str('lessonCode', 40, false);
        if ($code && !preg_match('/^[A-Z]{2,5}-[A-Z0-9]{1,4}-[A-Z0-9]{1,5}$/', strtoupper($code))) throw HttpError::badRequest('Lesson code looks like ABC-S1-E01.', ['field' => 'lessonCode']);
        return [
            'title' => $in->str('title', 200, !$partial), 'details' => $in->str('details', 3000, false),
            'planned_on' => $in->date('plannedOn', false), 'lesson_code' => $code ? strtoupper($code) : null, 'link_url' => $link,
        ];
    }

    private function lesson(int $schoolId, int $id): array
    {
        return $this->s->db->one('SELECT * FROM syllabus_lessons WHERE school_id = ? AND id = ?', [$schoolId, $id]) ?? throw HttpError::notFound('Lesson not found.');
    }

    private function updateLesson(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('lessonId');
        $this->lesson($schoolId, $id);
        $in = $req->input();
        $row = array_filter($this->lessonRow($in, true), fn($v) => $v !== null);
        // Sending an empty value clears it
        foreach (['plannedOn' => 'planned_on', 'lessonCode' => 'lesson_code', 'linkUrl' => 'link_url', 'details' => 'details'] as $k => $col) {
            if (array_key_exists($k, $req->body) && ($req->body[$k] === null || $req->body[$k] === '')) $row[$col] = null;
        }
        $this->s->db->update('syllabus_lessons', $schoolId, $id, $row);
        return ['lesson' => self::lessonOut($this->lesson($schoolId, $id))];
    }

    private function deleteLesson(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('lessonId');
        $this->lesson($schoolId, $id);
        $this->s->db->exec('DELETE FROM syllabus_lessons WHERE school_id = ? AND id = ?', [$schoolId, $id]);
        return ['ok' => true];
    }

    /**
     * Day-wise plan: give every lesson of a class + subject a teaching day, in order, from startOn.
     * Body: { gradeId, subjectId, startOn, weekdays?: [1..7] (default Mon-Sat), perDay?: 1, skip?: ["2026-11-14"] }
     */
    private function plan(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $start = new \DateTimeImmutable((string)$in->date('startOn'));
        $weekdays = array_values(array_unique(array_filter(array_map('intval', $in->has('weekdays') ? $in->list('weekdays') : [1, 2, 3, 4, 5, 6]), fn($d) => $d >= 1 && $d <= 7)));
        if (!$weekdays) throw HttpError::badRequest('Please choose at least one teaching day.', ['field' => 'weekdays']);
        $perDay = min(10, $in->int('perDay', false, 1) ?? 1);
        $skip = array_flip(array_filter($in->has('skip') ? $in->list('skip') : [], 'is_string'));
        $db = $this->s->db;
        $lessons = $db->all(
            'SELECT l.id FROM syllabus_lessons l JOIN syllabus_units u ON u.id = l.unit_id
              WHERE u.school_id = ? AND u.academic_year_id = ? AND u.grade_id = ? AND u.subject_id = ?
              ORDER BY u.sort_order, u.id, l.sort_order, l.id',
            [$schoolId, $this->yearId($schoolId), (int)$in->int('gradeId'), (int)$in->int('subjectId')],
        );
        if (!$lessons) throw HttpError::badRequest('Add chapters and lessons first.');
        $day = $start;
        $used = 0;
        $last = null;
        $db->tx(function () use ($db, $lessons, $weekdays, $skip, $perDay, &$day, &$used, &$last) {
            foreach ($lessons as $l) {
                if ($used >= $perDay) { $day = $day->modify('+1 day'); $used = 0; }
                for ($guard = 0; !in_array((int)$day->format('N'), $weekdays, true) || isset($skip[$day->format('Y-m-d')]); $guard++) {
                    if ($guard > 400) throw HttpError::badRequest('Could not find teaching days.');
                    $day = $day->modify('+1 day');
                }
                $db->exec('UPDATE syllabus_lessons SET planned_on = ? WHERE id = ?', [$day->format('Y-m-d'), $l['id']]);
                $used++;
                $last = $day->format('Y-m-d');
            }
        });
        $this->s->events->emit('syllabus.planned', ['grade_id' => $in->int('gradeId'), 'subject_id' => $in->int('subjectId'), 'lessons' => count($lessons)], $req);
        return ['lessons' => count($lessons), 'from' => $start->format('Y-m-d'), 'to' => $last];
    }

    /** Body: { divisionId, done: true|false }. Teachers tick lessons as they teach them. */
    private function markDone(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('lessonId');
        $lesson = $this->lesson($schoolId, $id);
        $in = $req->input();
        $divisionId = (int)$in->int('divisionId');
        $division = (new Access($this->s->db))->requireDivision($req, $divisionId);
        $unit = $this->unit($schoolId, (int)$lesson['unit_id']);
        if ((int)$unit['grade_id'] !== (int)$division['grade_id']) throw HttpError::badRequest('This lesson is for another class.');
        if ($in->has('done') && !$in->bool('done')) {
            $this->s->db->exec('DELETE FROM syllabus_progress WHERE lesson_id = ? AND division_id = ?', [$id, $divisionId]);
        } else {
            $this->s->db->exec(
                'INSERT INTO syllabus_progress (school_id, lesson_id, division_id, done_on, done_by) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE done_on = VALUES(done_on), done_by = VALUES(done_by)',
                [$schoolId, $id, $divisionId, date('Y-m-d'), $req->userId()],
            );
        }
        $this->s->events->emit('syllabus.taught', ['lesson_id' => $id, 'division_id' => $divisionId, 'done' => !$in->has('done') || $in->bool('done')], $req);
        return ['ok' => true];
    }
}
