<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Module, Request, Response, Roles, Router, Services};

/**
 * Exams, marks and report cards. Leaders create an exam for some classes and subjects (one
 * maximum mark for all, changeable per paper). Teachers type marks for a section, subject by
 * subject; the class teacher adds a remark. Results show total, percentage, grade and rank.
 * When the school publishes the exam, parents see the report card.
 */
final class ExamsModule implements Module
{
    /** CBSE 8-point scale: [lowest percentage, grade]. */
    public const SCALE = [[91, 'A1'], [81, 'A2'], [71, 'B1'], [61, 'B2'], [51, 'C1'], [41, 'C2'], [33, 'D'], [0, 'E']];
    public const PASS_PERCENT = 33;

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'exams'; }

    public function permissions(): array
    {
        return [
            'exams.manage' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD],
            'exams.marks' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/exams';
        $r->get($b, $this->list(...), ['perm' => 'exams.marks']);
        $r->post($b, $this->create(...), ['perm' => 'exams.manage']);
        $r->get("$b/{examId}", $this->get(...), ['perm' => 'exams.marks']);
        $r->delete("$b/{examId}", $this->remove(...), ['perm' => 'exams.manage']);
        $r->patch("$b/{examId}/papers/{paperId}", $this->updatePaper(...), ['perm' => 'exams.manage']);
        $r->get("$b/{examId}/marks", $this->marksSheet(...), ['perm' => 'exams.marks']);
        $r->put("$b/{examId}/marks", $this->saveMarks(...), ['perm' => 'exams.marks']);
        $r->get("$b/{examId}/results", $this->results(...), ['perm' => 'exams.marks']);
        $r->put("$b/{examId}/remarks", $this->saveRemark(...), ['perm' => 'exams.marks']);
        $r->post("$b/{examId}/publish", $this->publish(...), ['perm' => 'exams.manage']);
        $r->get("$b/{examId}/report/{studentId}", $this->report(...));
        $r->get('/schools/{schoolId}/report-cards/{studentId}', $this->studentExams(...));
    }

    public static function gradeFor(float $percent): string
    {
        foreach (self::SCALE as [$min, $g]) if ($percent >= $min) return $g;
        return 'E';
    }

    // ---------- exams ----------

    private function list(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $yearId = (new Access($this->s->db))->currentYearId($schoolId);
        $rows = $this->s->db->all(
            "SELECT e.*, (SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.sort_order SEPARATOR ', ') FROM exam_papers p JOIN grades g ON g.id = p.grade_id WHERE p.exam_id = e.id) AS classes,
                    (SELECT COUNT(*) FROM exam_papers p WHERE p.exam_id = e.id) AS papers
               FROM exams e WHERE e.school_id = ? AND e.academic_year_id = ? ORDER BY COALESCE(e.starts_on, e.created_at) DESC",
            [$schoolId, $yearId],
        );
        return ['exams' => array_map(fn($e) => self::examOut($e) + ['classes' => $e['classes'], 'papers' => (int)$e['papers']], $rows)];
    }

    /** Body: { name, startsOn?, gradeIds: [..], subjectIds: [..], maxMarks? } */
    private function create(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $yearId = (new Access($this->s->db))->currentYearId($schoolId) ?? throw HttpError::badRequest('Please set the current school year in Setup first.');
        $gradeIds = array_values(array_unique(array_map('intval', $in->list('gradeIds'))));
        $subjectIds = array_values(array_unique(array_map('intval', $in->list('subjectIds'))));
        if (!$gradeIds) throw HttpError::badRequest('Please choose the classes.', ['field' => 'gradeIds']);
        if (!$subjectIds) throw HttpError::badRequest('Please choose the subjects.', ['field' => 'subjectIds']);
        foreach ($gradeIds as $g) $this->s->db->one('SELECT id FROM grades WHERE school_id = ? AND id = ?', [$schoolId, $g]) ?? throw HttpError::notFound('Class not found.');
        foreach ($subjectIds as $sub) $this->s->db->one('SELECT id FROM subjects WHERE school_id = ? AND id = ?', [$schoolId, $sub]) ?? throw HttpError::notFound('Subject not found.');
        $max = $in->int('maxMarks', false, 1) ?? 100;
        if ($max > 1000) throw HttpError::badRequest('Maximum marks up to 1000.', ['field' => 'maxMarks']);
        $name = $in->str('name', 80);
        $startsOn = $in->date('startsOn', false);
        $id = $this->s->db->tx(function () use ($schoolId, $yearId, $name, $startsOn, $gradeIds, $subjectIds, $max, $req) {
            $id = $this->s->db->insert('exams', ['school_id' => $schoolId, 'academic_year_id' => $yearId, 'name' => $name, 'starts_on' => $startsOn, 'created_by' => $req->userId()]);
            foreach ($gradeIds as $g) foreach ($subjectIds as $sub) {
                $this->s->db->insert('exam_papers', ['school_id' => $schoolId, 'exam_id' => $id, 'grade_id' => $g, 'subject_id' => $sub, 'max_marks' => $max]);
            }
            return $id;
        });
        $this->s->events->emit('exam.created', ['id' => $id, 'name' => $name], $req);
        return Response::created($this->examDetail($schoolId, $id));
    }

    private function exam(Request $req): array
    {
        return $this->s->db->one('SELECT * FROM exams WHERE school_id = ? AND id = ?', [$req->tenant->schoolId, $req->intParam('examId')])
            ?? throw HttpError::notFound('Exam not found.');
    }

    /** The exam with its classes, each with sections (and how many marks are in) and papers. */
    private function examDetail(int $schoolId, int $examId): array
    {
        $exam = $this->s->db->one('SELECT * FROM exams WHERE id = ?', [$examId]);
        $papers = $this->s->db->all(
            'SELECT p.*, s.name AS subject, g.name AS grade FROM exam_papers p JOIN subjects s ON s.id = p.subject_id JOIN grades g ON g.id = p.grade_id
              WHERE p.exam_id = ? ORDER BY g.sort_order, s.name',
            [$examId],
        );
        $divs = $this->s->db->all(
            "SELECT d.id, d.grade_id, CONCAT(g.name, ' ', d.name) AS label,
                    (SELECT COUNT(*) FROM enrolments e WHERE e.division_id = d.id) AS students,
                    (SELECT COUNT(*) FROM exam_marks m JOIN exam_papers p ON p.id = m.paper_id JOIN enrolments e ON e.student_id = m.student_id AND e.division_id = d.id
                      WHERE p.exam_id = ? AND (m.marks IS NOT NULL OR m.absent = 1)) AS filled
               FROM divisions d JOIN grades g ON g.id = d.grade_id
              WHERE d.school_id = ? AND d.academic_year_id = ? ORDER BY g.sort_order, d.name",
            [$examId, $schoolId, $exam['academic_year_id']],
        );
        $classes = [];
        foreach ($papers as $p) {
            $g = (int)$p['grade_id'];
            $classes[$g] ??= ['gradeId' => $g, 'grade' => $p['grade'], 'papers' => [], 'sections' => []];
            $classes[$g]['papers'][] = ['id' => (int)$p['id'], 'subjectId' => (int)$p['subject_id'], 'subject' => $p['subject'], 'maxMarks' => (int)$p['max_marks']];
        }
        foreach ($divs as $d) {
            $g = (int)$d['grade_id'];
            if (!isset($classes[$g])) continue;
            $needed = (int)$d['students'] * count($classes[$g]['papers']);
            $classes[$g]['sections'][] = ['divisionId' => (int)$d['id'], 'label' => $d['label'], 'students' => (int)$d['students'], 'filled' => (int)$d['filled'], 'needed' => $needed];
        }
        return ['exam' => self::examOut($exam), 'classes' => array_values($classes)];
    }

    private function get(Request $req): array
    {
        $exam = $this->exam($req);
        return $this->examDetail($req->tenant->schoolId, (int)$exam['id']);
    }

    private function remove(Request $req): array
    {
        $exam = $this->exam($req);
        if ($exam['published_at']) throw HttpError::conflict('A published exam cannot be deleted. Unpublish it first.');
        $this->s->db->exec('DELETE FROM exams WHERE id = ?', [$exam['id']]);
        $this->s->events->emit('exam.deleted', ['id' => (int)$exam['id'], 'name' => $exam['name']], $req);
        return ['ok' => true];
    }

    private function updatePaper(Request $req): array
    {
        $exam = $this->exam($req);
        $paper = $this->s->db->one('SELECT * FROM exam_papers WHERE exam_id = ? AND id = ?', [$exam['id'], $req->intParam('paperId')]) ?? throw HttpError::notFound();
        $max = $req->input()->int('maxMarks', true, 1);
        if ($max > 1000) throw HttpError::badRequest('Maximum marks up to 1000.', ['field' => 'maxMarks']);
        $over = $this->s->db->value('SELECT 1 FROM exam_marks WHERE paper_id = ? AND marks > ? LIMIT 1', [$paper['id'], $max]);
        if ($over) throw HttpError::conflict('Some marks are already above this maximum.');
        $this->s->db->exec('UPDATE exam_papers SET max_marks = ? WHERE id = ?', [$max, $paper['id']]);
        $this->s->events->emit('exam.paper_changed', ['id' => (int)$paper['id'], 'max_marks' => $max], $req);
        return $this->examDetail($req->tenant->schoolId, (int)$exam['id']);
    }

    // ---------- marks ----------

    private function paperFor(array $exam, array $division, int $subjectId): array
    {
        return $this->s->db->one(
            'SELECT p.*, s.name AS subject FROM exam_papers p JOIN subjects s ON s.id = p.subject_id WHERE p.exam_id = ? AND p.grade_id = ? AND p.subject_id = ?',
            [$exam['id'], $division['grade_id'], $subjectId],
        ) ?? throw HttpError::notFound('This subject is not in this exam for this class.');
    }

    /** ?divisionId=&subjectId= : the section's children in roll order with marks so far. */
    private function marksSheet(Request $req): array
    {
        $exam = $this->exam($req);
        $division = (new Access($this->s->db))->requireDivision($req, (int)($req->query['divisionId'] ?? 0));
        $paper = $this->paperFor($exam, $division, (int)($req->query['subjectId'] ?? 0));
        $rows = $this->s->db->all(
            "SELECT st.id, st.first_name, st.last_name, e.roll_no, m.marks, m.absent
               FROM enrolments e JOIN students st ON st.id = e.student_id
               LEFT JOIN exam_marks m ON m.paper_id = ? AND m.student_id = st.id
              WHERE e.division_id = ? AND st.status = 'active' ORDER BY e.roll_no IS NULL, e.roll_no, st.first_name",
            [$paper['id'], $division['id']],
        );
        return [
            'exam' => self::examOut($exam),
            'paper' => ['id' => (int)$paper['id'], 'subject' => $paper['subject'], 'maxMarks' => (int)$paper['max_marks']],
            'division' => ['id' => (int)$division['id'], 'label' => $division['grade_name'] . ' ' . $division['name']],
            'students' => array_map(fn($r) => [
                'studentId' => (int)$r['id'], 'name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')), 'rollNo' => $r['roll_no'] === null ? null : (int)$r['roll_no'],
                'marks' => $r['marks'] === null ? null : (float)$r['marks'], 'absent' => (bool)$r['absent'],
            ], $rows),
        ];
    }

    /** Body: { divisionId, subjectId, marks: [{ studentId, marks: number|null, absent?: bool }] } */
    private function saveMarks(Request $req): array
    {
        $exam = $this->exam($req);
        if ($exam['published_at']) throw HttpError::conflict('Results are published. Unpublish to change marks.');
        $in = $req->input();
        $division = (new Access($this->s->db))->requireDivision($req, $in->int('divisionId'));
        $paper = $this->paperFor($exam, $division, $in->int('subjectId'));
        $max = (float)$paper['max_marks'];
        $enrolled = array_map('intval', array_column($this->s->db->all('SELECT student_id FROM enrolments WHERE division_id = ?', [$division['id']]), 'student_id'));
        $schoolId = $req->tenant->schoolId;
        $saved = 0;
        $this->s->db->tx(function () use ($in, $enrolled, $max, $paper, $schoolId, $req, &$saved) {
            foreach ($in->list('marks') as $row) {
                $sid = (int)($row['studentId'] ?? 0);
                if (!in_array($sid, $enrolled, true)) throw HttpError::badRequest('A child is not in this section.');
                $absent = !empty($row['absent']);
                $m = $row['marks'] ?? null;
                if ($m === '' || $absent) $m = null;
                if ($m !== null) {
                    if (!is_numeric($m) || (float)$m < 0 || (float)$m > $max || fmod((float)$m * 2, 1) != 0) {
                        throw HttpError::badRequest(sprintf('Marks must be between 0 and %d (half marks allowed).', $max), ['studentId' => $sid]);
                    }
                    $m = (float)$m;
                }
                $this->s->db->exec(
                    'INSERT INTO exam_marks (paper_id, student_id, school_id, marks, absent, entered_by) VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE marks = VALUES(marks), absent = VALUES(absent), entered_by = VALUES(entered_by)',
                    [$paper['id'], $sid, $schoolId, $m, $absent ? 1 : 0, $req->userId()],
                );
                $saved++;
            }
        });
        $this->s->events->emit('exam.marks_saved', ['id' => (int)$paper['id'], 'division_id' => (int)$division['id'], 'count' => $saved], $req);
        return ['saved' => $saved];
    }

    // ---------- results ----------

    /** Every child of a section with subject marks, total, percentage, grade and rank. */
    private function sectionResults(array $exam, array $division): array
    {
        $papers = $this->s->db->all(
            'SELECT p.id, p.max_marks, s.name AS subject FROM exam_papers p JOIN subjects s ON s.id = p.subject_id WHERE p.exam_id = ? AND p.grade_id = ? ORDER BY s.name',
            [$exam['id'], $division['grade_id']],
        );
        $students = $this->s->db->all(
            "SELECT st.id, st.first_name, st.last_name, st.admission_no, e.roll_no FROM enrolments e JOIN students st ON st.id = e.student_id
              WHERE e.division_id = ? AND st.status = 'active' ORDER BY e.roll_no IS NULL, e.roll_no, st.first_name",
            [$division['id']],
        );
        $marks = [];
        if ($papers && $students) {
            $ids = implode(',', array_map(fn($p) => (int)$p['id'], $papers));
            foreach ($this->s->db->all("SELECT * FROM exam_marks WHERE paper_id IN ($ids)") as $m) $marks[(int)$m['student_id']][(int)$m['paper_id']] = $m;
        }
        $remarks = array_column($this->s->db->all('SELECT student_id, remark FROM exam_remarks WHERE exam_id = ?', [$exam['id']]), 'remark', 'student_id');
        $out = [];
        foreach ($students as $st) {
            $sid = (int)$st['id'];
            $subjects = [];
            $total = 0.0; $maxTotal = 0; $complete = true; $failed = false;
            foreach ($papers as $p) {
                $m = $marks[$sid][(int)$p['id']] ?? null;
                $value = $m && $m['marks'] !== null ? (float)$m['marks'] : null;
                $absent = $m && $m['absent'];
                if ($value === null && !$absent) $complete = false;
                $pct = $value !== null ? $value * 100 / (int)$p['max_marks'] : 0;
                if ($value !== null || $absent) { $total += $value ?? 0; $maxTotal += (int)$p['max_marks']; if ($pct < self::PASS_PERCENT) $failed = true; }
                $subjects[] = ['subject' => $p['subject'], 'maxMarks' => (int)$p['max_marks'], 'marks' => $value, 'absent' => (bool)$absent, 'grade' => $value !== null ? self::gradeFor($pct) : ($absent ? 'AB' : null)];
            }
            $percent = $maxTotal ? round($total * 100 / $maxTotal, 1) : null;
            $out[] = [
                'studentId' => $sid, 'name' => trim($st['first_name'] . ' ' . ($st['last_name'] ?? '')), 'admissionNo' => $st['admission_no'],
                'rollNo' => $st['roll_no'] === null ? null : (int)$st['roll_no'],
                'subjects' => $subjects, 'total' => $total, 'maxTotal' => $maxTotal, 'percent' => $percent,
                'grade' => $percent === null ? null : self::gradeFor($percent), 'result' => !$complete ? 'pending' : ($failed ? 'needs_support' : 'pass'),
                'remark' => $remarks[$sid] ?? null, 'rank' => null,
            ];
        }
        // Rank among children with all marks in (ties share a rank)
        $ranked = array_filter($out, fn($r) => $r['result'] !== 'pending' && $r['percent'] !== null);
        usort($ranked, fn($a, $b) => $b['percent'] <=> $a['percent']);
        $rankOf = [];
        $prev = null; $rank = 0;
        foreach (array_values($ranked) as $i => $r) { if ($r['percent'] !== $prev) { $rank = $i + 1; $prev = $r['percent']; } $rankOf[$r['studentId']] = $rank; }
        foreach ($out as &$r) $r['rank'] = $rankOf[$r['studentId']] ?? null;
        unset($r);
        return ['papers' => array_map(fn($p) => ['subject' => $p['subject'], 'maxMarks' => (int)$p['max_marks']], $papers), 'students' => $out];
    }

    private function results(Request $req): array
    {
        $exam = $this->exam($req);
        $division = (new Access($this->s->db))->requireDivision($req, (int)($req->query['divisionId'] ?? 0));
        return ['exam' => self::examOut($exam), 'division' => ['id' => (int)$division['id'], 'label' => $division['grade_name'] . ' ' . $division['name']]]
            + $this->sectionResults($exam, $division);
    }

    /** Body: { studentId, remark } (class teacher or leaders). */
    private function saveRemark(Request $req): array
    {
        $exam = $this->exam($req);
        $in = $req->input();
        $studentId = $in->int('studentId');
        $div = $this->divisionOf($exam, $studentId) ?? throw HttpError::notFound('Student not found in this year.');
        (new Access($this->s->db))->requireDivision($req, (int)$div['id'], write: true);
        $remark = $in->str('remark', 300, false);
        if ($remark === null) $this->s->db->exec('DELETE FROM exam_remarks WHERE exam_id = ? AND student_id = ?', [$exam['id'], $studentId]);
        else $this->s->db->exec(
            'INSERT INTO exam_remarks (exam_id, student_id, school_id, remark, updated_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE remark = VALUES(remark), updated_by = VALUES(updated_by)',
            [$exam['id'], $studentId, $req->tenant->schoolId, $remark, $req->userId()],
        );
        return ['ok' => true];
    }

    private function divisionOf(array $exam, int $studentId): ?array
    {
        $id = $this->s->db->value('SELECT division_id FROM enrolments WHERE academic_year_id = ? AND student_id = ? AND school_id = ?', [$exam['academic_year_id'], $studentId, $exam['school_id']]);
        return $id ? (new Access($this->s->db))->division((int)$exam['school_id'], (int)$id) : null;
    }

    /** Body: { published: bool }. Parents see report cards only after this. */
    private function publish(Request $req): array
    {
        $exam = $this->exam($req);
        $on = $req->input()->bool('published');
        $this->s->db->exec('UPDATE exams SET published_at = ' . ($on ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$exam['id']]);
        $this->s->events->emit($on ? 'exam.published' : 'exam.unpublished', ['id' => (int)$exam['id'], 'name' => $exam['name']], $req);
        return ['exam' => self::examOut($this->exam($req))];
    }

    // ---------- report card ----------

    private function canSeeChild(Request $req, int $studentId): bool
    {
        $t = $req->tenant;
        if ($t->can('exams.marks')) return true;
        return in_array($studentId, (new Access($this->s->db))->childIds($req), true);
    }

    /** One child's report card for one exam. Parents: only once published. */
    private function report(Request $req): array
    {
        $exam = $this->exam($req);
        $studentId = $req->intParam('studentId');
        if (!$this->canSeeChild($req, $studentId)) throw HttpError::forbidden();
        $staff = $req->tenant->can('exams.marks');
        if (!$staff && !$exam['published_at']) throw HttpError::forbidden('The school has not published these results yet.');
        $division = $this->divisionOf($exam, $studentId) ?? throw HttpError::notFound('Student not found in this year.');
        $section = $this->sectionResults($exam, $division);
        $me = array_values(array_filter($section['students'], fn($s) => $s['studentId'] === $studentId))[0] ?? throw HttpError::notFound();
        $year = $this->s->db->one('SELECT * FROM academic_years WHERE id = ?', [$exam['academic_year_id']]);
        $att = $this->s->db->one(
            "SELECT SUM(status IN ('present','late')) AS present, COUNT(*) AS days FROM student_attendance
              WHERE student_id = ? AND on_date BETWEEN ? AND ?",
            [$studentId, $year['starts_on'], $exam['starts_on'] ?: date('Y-m-d')],
        );
        $school = $req->tenant->school;
        return [
            'exam' => self::examOut($exam), 'year' => $year['name'],
            'school' => ['name' => $school['name'], 'city' => $school['city'] ?? null, 'board' => $school['board'] ?? null, 'logoUrl' => $school['logo_url'] ?? null],
            'class' => $division['grade_name'] . ' ' . $division['name'], 'classSize' => count($section['students']),
            'attendance' => ['present' => (int)($att['present'] ?? 0), 'days' => (int)($att['days'] ?? 0)],
            'scale' => array_map(fn($s) => ['from' => $s[0], 'grade' => $s[1]], self::SCALE),
        ] + $me;
    }

    /** Exams a child has results for (parents: published only), for the child page. */
    private function studentExams(Request $req): array
    {
        $studentId = $req->intParam('studentId');
        if (!$this->canSeeChild($req, $studentId)) throw HttpError::forbidden();
        $staff = $req->tenant->can('exams.marks');
        $rows = $this->s->db->all(
            'SELECT DISTINCT x.* FROM exams x JOIN exam_papers p ON p.exam_id = x.id
               JOIN enrolments e ON e.academic_year_id = x.academic_year_id AND e.student_id = ?
               JOIN divisions d ON d.id = e.division_id AND d.grade_id = p.grade_id
              WHERE x.school_id = ?' . ($staff ? '' : ' AND x.published_at IS NOT NULL') . ' ORDER BY COALESCE(x.starts_on, x.created_at) DESC',
            [$studentId, $req->tenant->schoolId],
        );
        return ['exams' => array_map(self::examOut(...), $rows)];
    }

    private static function examOut(array $e): array
    {
        return ['id' => (int)$e['id'], 'name' => $e['name'], 'startsOn' => $e['starts_on'], 'published' => $e['published_at'] !== null];
    }
}
