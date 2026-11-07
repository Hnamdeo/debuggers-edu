<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Access, Events, HttpError, Module, Request, Response, Roles, Router, Services};

/** Notices and circulars. Each person only sees what is meant for them. */
final class NoticesModule implements Module
{
    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'notices'; }

    public function permissions(): array
    {
        return [
            'notices.send_school' => [Roles::ADMIN, Roles::PRINCIPAL],
            'notices.send_class' => [Roles::ADMIN, Roles::PRINCIPAL, Roles::ACADEMIC_HEAD, Roles::TEACHER],
        ];
    }

    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $b = '/schools/{schoolId}/notices';
        $r->get($b, $this->list(...));
        $r->post($b, $this->create(...), ['perm' => 'notices.send_class']);
        $r->delete("$b/{noticeId}", $this->remove(...), ['perm' => 'notices.send_class']);
    }

    public function visibleTo(Request $req, int $limit = 50): array
    {
        $t = $req->tenant;
        $access = new Access($this->s->db);
        $isStaff = $t->hasRole(...Roles::EMPLOYEES);
        $isParent = $t->hasRole(Roles::PARENT);
        $leader = $t->hasRole(Roles::ADMIN, Roles::PRINCIPAL);
        $audiences = ["'everyone'"];
        if ($isStaff) $audiences[] = "'staff'";
        if ($isParent) $audiences[] = "'parents'";
        $divisionIds = $leader ? [] : array_values(array_unique([...$access->teacherDivisionIds($req), ...$access->childDivisionIds($req)]));
        $divisionClause = $leader ? "n.audience = 'division'" : ($divisionIds ? 'n.division_id IN (' . implode(',', $divisionIds) . ')' : '0');
        $rows = $this->s->db->all(
            "SELECT n.*, u.name AS author, CONCAT(g.name, ' ', d.name) AS division_label
               FROM notices n LEFT JOIN users u ON u.id = n.created_by
               LEFT JOIN divisions d ON d.id = n.division_id LEFT JOIN grades g ON g.id = d.grade_id
              WHERE n.school_id = ? AND n.deleted_at IS NULL
                AND (n.audience IN (" . implode(',', $audiences) . ") OR ($divisionClause) OR ? = 1 OR n.created_by = ?)
              ORDER BY n.created_at DESC LIMIT " . max(1, min($limit, 100)),
            [$t->schoolId, $leader ? 1 : 0, $req->userId()],
        );
        return array_map(fn($n) => [
            'id' => (int)$n['id'], 'title' => $n['title'], 'body' => $n['body'], 'audience' => $n['audience'],
            'division' => $n['division_label'], 'author' => $n['author'], 'createdAt' => $n['created_at'],
            'mine' => (int)$n['created_by'] === $req->userId(),
        ], $rows);
    }

    private function list(Request $req): array
    {
        return ['notices' => $this->visibleTo($req)];
    }

    private function create(Request $req): Response
    {
        $in = $req->input();
        $audience = $in->oneOf('audience', ['everyone', 'staff', 'parents', 'division'], false) ?? 'everyone';
        $divisionId = null;
        if ($audience === 'division') {
            $divisionId = (int)$in->int('divisionId');
            (new Access($this->s->db))->requireDivision($req, $divisionId, write: true);
        } else {
            $req->tenant->require('notices.send_school');
        }
        $id = $this->s->db->insert('notices', [
            'school_id' => $req->tenant->schoolId, 'title' => $in->str('title', 160), 'body' => $in->str('body', 5000, false),
            'audience' => $audience, 'division_id' => $divisionId, 'created_by' => $req->userId(),
        ]);
        $this->s->events->emit('notice.sent', ['id' => $id, 'audience' => $audience, 'division_id' => $divisionId], $req);
        return Response::created(['id' => $id]);
    }

    private function remove(Request $req): array
    {
        $id = $req->intParam('noticeId');
        $n = $this->s->db->one('SELECT * FROM notices WHERE school_id = ? AND id = ? AND deleted_at IS NULL', [$req->tenant->schoolId, $id]) ?? throw HttpError::notFound();
        if ((int)$n['created_by'] !== $req->userId() && !$req->tenant->hasRole(Roles::ADMIN, Roles::PRINCIPAL)) throw HttpError::forbidden();
        $this->s->db->exec('UPDATE notices SET deleted_at = NOW() WHERE id = ?', [$id]);
        $this->s->events->emit('notice.removed', ['id' => $id], $req);
        return ['ok' => true];
    }
}
