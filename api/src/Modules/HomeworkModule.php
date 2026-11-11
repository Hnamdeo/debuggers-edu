<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Module, Request, Response, Roles, Router, Services};

/**
 * Homework, worksheets, revision and lessons for a class. A lesson code
 * (ABC-S1-E01) or a video link can be attached, which is how YouTube lessons become
 * homework and revision once the LMS module arrives.
 */
final class HomeworkModule implements Module
{
    public const KINDS = ['homework', 'worksheet', 'revision', 'lesson'];

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'homework'; }

    public function permissions(): array
    {
        return ['homework.give' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER]];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/homework';
        $r->get($b, $this->list(...));
        $r->post($b, $this->create(...), ['perm' => 'homework.give']);
        $r->delete("$b/{homeworkId}", $this->remove(...), ['perm' => 'homework.give']);
    }

    /** ?divisionId= for one class; without it a parent sees their children's classes, a teacher their own. */
    private function list(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $access = new Access($this->s->db);
        if (!empty($req->query['divisionId'])) {
            $divisionIds = [(int)$req->query['divisionId']];
            $access->requireDivision($req, $divisionIds[0]);
        } elseif ($req->tenant->hasRole(Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD)) {
            $divisionIds = null; // whole school
        } else {
            $divisionIds = array_values(array_unique([...$access->teacherDivisionIds($req), ...$access->childDivisionIds($req)]));
            if (!$divisionIds) return ['homework' => []];
        }
        $where = 'h.school_id = ? AND h.deleted_at IS NULL AND h.created_at > NOW() - INTERVAL 60 DAY';
        $args = [$schoolId];
        if ($divisionIds !== null) {
            $where .= ' AND h.division_id IN (' . implode(',', array_fill(0, count($divisionIds), '?')) . ')';
            array_push($args, ...$divisionIds);
        }
        $rows = $this->s->db->all(
            "SELECT h.*, sb.name AS subject, CONCAT(g.name, ' ', d.name) AS division_label, u.name AS teacher
               FROM homework h JOIN divisions d ON d.id = h.division_id JOIN grades g ON g.id = d.grade_id
               LEFT JOIN subjects sb ON sb.id = h.subject_id LEFT JOIN users u ON u.id = h.created_by
              WHERE $where ORDER BY h.created_at DESC LIMIT 200",
            $args,
        );
        return ['homework' => array_map(fn($h) => [
            'id' => (int)$h['id'], 'kind' => $h['kind'], 'title' => $h['title'], 'details' => $h['details'],
            'linkUrl' => $h['link_url'], 'lessonCode' => $h['lesson_code'], 'dueOn' => $h['due_on'],
            'divisionId' => (int)$h['division_id'], 'division' => $h['division_label'], 'subject' => $h['subject'],
            'teacher' => $h['teacher'], 'createdAt' => $h['created_at'], 'mine' => (int)$h['created_by'] === $req->userId(),
        ], $rows)];
    }

    /** Body: { divisionIds: [..] (same work for several sections at once), subjectId?, kind?, title, details?, linkUrl?, lessonCode?, dueOn? } */
    private function create(Request $req): Response
    {
        $schoolId = $req->tenant->schoolId;
        $in = $req->input();
        $access = new Access($this->s->db);
        $divisionIds = array_values(array_unique(array_map('intval', $in->list('divisionIds'))));
        if (!$divisionIds) throw HttpError::badRequest('Please choose a class.', ['field' => 'divisionIds']);
        foreach ($divisionIds as $id) $access->requireDivision($req, $id, write: !$req->tenant->hasRole(Roles::TEACHER));
        $subjectId = $in->int('subjectId', false);
        if ($subjectId) $this->s->db->one('SELECT id FROM subjects WHERE school_id = ? AND id = ?', [$schoolId, $subjectId]) ?? throw HttpError::notFound('Subject not found.');
        $link = $in->str('linkUrl', 500, false);
        if ($link && !preg_match('#^https://#', $link)) throw HttpError::badRequest('Link must start with https://', ['field' => 'linkUrl']);
        $lessonCode = $in->str('lessonCode', 40, false);
        if ($lessonCode && !preg_match('/^[A-Z]{2,5}-[A-Z0-9]{1,4}-[A-Z0-9]{1,5}$/', strtoupper($lessonCode))) throw HttpError::badRequest('Lesson code looks like ABC-S1-E01.', ['field' => 'lessonCode']);
        $row = [
            'school_id' => $schoolId, 'subject_id' => $subjectId,
            'kind' => $in->oneOf('kind', self::KINDS, false) ?? 'homework',
            'title' => $in->str('title', 160), 'details' => $in->str('details', 5000, false),
            'link_url' => $link, 'lesson_code' => $lessonCode ? strtoupper($lessonCode) : null,
            'due_on' => $in->date('dueOn', false), 'created_by' => $req->userId(),
        ];
        $ids = $this->s->db->tx(fn() => array_map(fn($d) => $this->s->db->insert('homework', $row + ['division_id' => $d]), $divisionIds));
        foreach ($ids as $i => $id) {
            $this->s->events->emit('homework.given', ['id' => $id, 'division_id' => $divisionIds[$i], 'kind' => $row['kind']], $req);
        }
        return Response::created(['ids' => $ids]);
    }

    private function remove(Request $req): array
    {
        $schoolId = $req->tenant->schoolId;
        $id = $req->intParam('homeworkId');
        $hw = $this->s->db->one('SELECT * FROM homework WHERE school_id = ? AND id = ? AND deleted_at IS NULL', [$schoolId, $id]) ?? throw HttpError::notFound();
        if ((int)$hw['created_by'] !== $req->userId() && !$req->tenant->hasRole(Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD)) throw HttpError::forbidden();
        $this->s->db->exec('UPDATE homework SET deleted_at = NOW() WHERE id = ?', [$id]);
        $this->s->events->emit('homework.removed', ['id' => $id], $req);
        return ['ok' => true];
    }
}
