<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Auth, Config, Db, Events, HttpError, Migrator, Module, Request, Response, Roles, Router, Services, Users};

/**
 * The Thinkle Tots team's side: create schools (tenants), add their first admin, pause them.
 * Also deploy-time routes (migrate, first platform admin) guarded by the deploy key.
 */
final class PlatformModule implements Module
{
    public const BOARDS = ['CBSE', 'CGBSE', 'MPBSE', 'ICSE', 'STATE', 'OTHER'];
    public const TEMPLATES = ['preschool', 'primary', 'k10', 'k12', 'empty'];

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'platform'; }
    public function permissions(): array { return []; }
    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $r->get('/health', fn() => ['ok' => true, 'time' => date('c')], ['auth' => 'none']);
        $r->post('/system/migrate', $this->migrate(...), ['auth' => 'none']);
        $r->post('/system/bootstrap', $this->bootstrap(...), ['auth' => 'none']);
        $r->get('/public/schools/{slug}', $this->publicSchool(...), ['auth' => 'none']);
        $r->get('/platform/schools', $this->listSchools(...), ['auth' => 'platform']);
        $r->post('/platform/schools', $this->createSchool(...), ['auth' => 'platform']);
        $r->patch('/platform/schools/{id}', $this->updateSchool(...), ['auth' => 'platform']);
        $r->post('/platform/schools/{id}/admins', $this->addAdmin(...), ['auth' => 'platform']);
    }

    private function requireDeployKey(Request $req): void
    {
        $key = (string)Config::get('deploy_key', '');
        if (strlen($key) < 24 || !hash_equals($key, $req->header('x-deploy-key'))) throw HttpError::forbidden();
    }

    private function migrate(Request $req): array
    {
        $this->requireDeployKey($req);
        return ['applied' => (new Migrator($this->s->db, dirname(__DIR__, 2) . '/migrations'))->run()];
    }

    /** Creates the first Thinkle Tots team account. Works only while there is none. */
    private function bootstrap(Request $req): Response
    {
        $this->requireDeployKey($req);
        $db = $this->s->db;
        if ($db->value('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1')) throw HttpError::conflict('Already set up. Please sign in.');
        $in = $req->input();
        $password = (string)$in->str('password', 200);
        Auth::checkPassword($password);
        $id = (new Users($db))->findOrCreate((string)$in->str('name', 120), $in->phone('phone', false), $in->email('email', false));
        $db->exec('UPDATE users SET is_platform_admin = 1, password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $id]);
        return Response::created(['token' => $this->s->auth->startSession($id)]);
    }

    /** What the school's login page shows before anyone signs in. */
    private function publicSchool(Request $req): array
    {
        $s = $this->s->db->one('SELECT name, slug, logo_url, color, city, phone, status, settings FROM schools WHERE slug = ?', [strtolower($req->param('slug'))])
            ?? throw HttpError::notFound('We could not find this school. Please check the link.');
        $settings = self::settings($s);
        return [
            'name' => $s['name'], 'slug' => $s['slug'], 'logoUrl' => $s['logo_url'], 'color' => $s['color'], 'city' => $s['city'],
            'phone' => $s['phone'], 'paused' => $s['status'] === 'paused',
            'admissionsOpen' => (bool)($settings['admissionsOpen'] ?? false), 'loginBoard' => $settings['loginBoard'] ?? null,
        ];
    }

    private function listSchools(): array
    {
        return ['schools' => array_map(fn($s) => self::schoolOut($s), $this->s->db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM students st WHERE st.school_id = s.id AND st.status = "active") AS students,
                    (SELECT COUNT(*) FROM staff sf WHERE sf.school_id = s.id AND sf.status = "active") AS staff
               FROM schools s ORDER BY s.created_at DESC',
        ))];
    }

    private function createSchool(Request $req): Response
    {
        $in = $req->input();
        $slug = strtolower((string)$in->str('slug', 40));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/', $slug)) throw HttpError::badRequest('Link name: 3 to 40 small letters, numbers or dashes, like "vibgyor-pune".', ['field' => 'slug']);
        if (in_array($slug, ['admin', 'api', 'assets', 'welcome', 'login', 'setup', 'help', 'www'], true)) throw HttpError::badRequest('This link name is reserved.', ['field' => 'slug']);
        $color = $in->str('color', 7, false) ?? '#6C4CF1';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw HttpError::badRequest('Colour must look like #6C4CF1.', ['field' => 'color']);
        $row = [
            'slug' => $slug,
            'name' => $in->str('name', 160),
            'board' => $in->oneOf('board', self::BOARDS, false) ?? 'CBSE',
            'state' => $in->str('state', 60, false),
            'city' => $in->str('city', 80, false),
            'phone' => $in->phone('phone', false),
            'color' => $color,
        ];
        $template = $in->oneOf('template', self::TEMPLATES, false) ?? 'k10';
        $db = $this->s->db;
        $id = $db->tx(function () use ($db, $row, $template, $req) {
            $id = $db->insert('schools', $row);
            // The Setup module listens and creates the year, classes and subjects from the template
            $this->s->events->emit('school.created', ['school_id' => $id, 'id' => $id, 'board' => $row['board'], 'template' => $template], $req);
            return $id;
        });
        return Response::created(['school' => self::schoolOut($db->one('SELECT * FROM schools WHERE id = ?', [$id]))]);
    }

    private function updateSchool(Request $req): array
    {
        $id = $req->intParam('id');
        $in = $req->input();
        $db = $this->s->db;
        $db->one('SELECT id FROM schools WHERE id = ?', [$id]) ?? throw HttpError::notFound();
        $row = array_filter([
            'name' => $in->str('name', 160, false),
            'status' => $in->oneOf('status', ['trial', 'active', 'paused'], false),
            'board' => $in->oneOf('board', self::BOARDS, false),
        ], fn($v) => $v !== null);
        if ($row) {
            $db->exec('UPDATE schools SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?', [...array_values($row), $id]);
            $this->s->events->emit('school.updated', ['school_id' => $id, 'id' => $id] + $row, $req);
        }
        return ['school' => self::schoolOut($db->one('SELECT * FROM schools WHERE id = ?', [$id]))];
    }

    /** Adds the school's first admin (or principal) and returns a one-time link to share on WhatsApp. */
    private function addAdmin(Request $req): Response
    {
        $schoolId = $req->intParam('id');
        $db = $this->s->db;
        $school = $db->one('SELECT * FROM schools WHERE id = ?', [$schoolId]) ?? throw HttpError::notFound();
        $in = $req->input();
        $name = (string)$in->str('name', 120);
        $role = $in->oneOf('role', [Roles::ADMIN, Roles::PRINCIPAL], false) ?? Roles::ADMIN;
        $users = new Users($db);
        [$userId, $link] = $db->tx(function () use ($db, $users, $name, $in, $schoolId, $role, $school, $req) {
            $userId = $users->findOrCreate($name, $in->phone('phone', false), $in->email('email', false));
            $users->addRole($schoolId, $userId, $role);
            $db->exec(
                'INSERT INTO staff (school_id, user_id, name, phone, email, role, designation) SELECT ?, id, name, phone, email, ?, ? FROM users WHERE id = ?
                 ON DUPLICATE KEY UPDATE role = VALUES(role)',
                [$schoolId, $role, $role === Roles::PRINCIPAL ? 'Principal' : 'School admin', $userId],
            );
            $link = $users->hasPassword($userId) ? null : Auth::inviteLink($this->s->auth->createInvite($userId, $schoolId, $req->userId()), $school['slug']);
            return [$userId, $link];
        });
        $this->s->events->emit('school.admin_added', ['school_id' => $schoolId, 'id' => $userId, 'role' => $role], $req);
        return Response::created(['userId' => $userId, 'inviteLink' => $link]);
    }

    /** The school's settings JSON (admissionsOpen, loginBoard {imageUrl, headline, lines}). */
    public static function settings(array $school): array
    {
        return isset($school['settings']) && $school['settings'] ? (json_decode($school['settings'], true) ?: []) : [];
    }

    public static function schoolOut(array $s): array
    {
        $settings = self::settings($s);
        return [
            'id' => (int)$s['id'], 'slug' => $s['slug'], 'name' => $s['name'], 'board' => $s['board'],
            'state' => $s['state'], 'city' => $s['city'], 'phone' => $s['phone'], 'address' => $s['address'],
            'logoUrl' => $s['logo_url'], 'color' => $s['color'], 'status' => $s['status'],
            'students' => isset($s['students']) ? (int)$s['students'] : null,
            'staff' => isset($s['staff']) ? (int)$s['staff'] : null,
            'createdAt' => $s['created_at'],
            'admissionsOpen' => (bool)($settings['admissionsOpen'] ?? false), 'loginBoard' => $settings['loginBoard'] ?? null,
        ];
    }
}
