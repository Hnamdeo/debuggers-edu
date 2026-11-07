<?php
declare(strict_types=1);

namespace School\Modules;

use School\Core\{Auth, Config, HttpError, Input, Module, Events, Request, Response, Router, Services};

/** Sign in (mobile or email + password, or Google), invite links, and "who am I". */
final class AuthModule implements Module
{
    private const MAX_FAILS = 8;          // per login or IP
    private const FAIL_WINDOW_MIN = 15;

    public function __construct(private readonly Services $s) {}

    public function name(): string { return 'auth'; }
    public function permissions(): array { return []; }
    public function listen(Events $events): void {}

    public function routes(Router $r): void
    {
        $r->post('/auth/login', $this->login(...), ['auth' => 'none']);
        $r->post('/auth/google', $this->google(...), ['auth' => 'none']);
        $r->get('/auth/invite', $this->invitePeek(...), ['auth' => 'none']);
        $r->post('/auth/welcome', $this->acceptInvite(...), ['auth' => 'none']);
        $r->post('/auth/logout', $this->logout(...));
        $r->get('/me', $this->me(...));
        $r->patch('/me', $this->updateMe(...));
    }

    private function login(Request $req): Response
    {
        $in = $req->input();
        $login = strtolower($in->str('login', 190));
        $password = (string)$in->str('password', 200);
        $ip = $this->ip($req);
        $db = $this->s->db;

        $fails = (int)$db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE (login = ? OR ip = ?) AND created_at > NOW() - INTERVAL ' . self::FAIL_WINDOW_MIN . ' MINUTE',
            [$login, $ip],
        );
        if ($fails >= self::MAX_FAILS) throw new HttpError(429, 'Too many tries. Please wait 15 minutes and try again.');

        $digits = preg_replace('/\D/', '', $login);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) $digits = substr($digits, 2);
        $user = str_contains($login, '@')
            ? $db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$login])
            : $db->one('SELECT * FROM users WHERE phone = ? AND is_active = 1', [$digits]);

        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            $db->insert('login_attempts', ['login' => $login, 'ip' => $ip]);
            throw HttpError::unauthorized('Mobile number or password is not right.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $user['id']]);
        }
        $db->exec('DELETE FROM login_attempts WHERE login = ?', [$login]);
        return Response::ok(['token' => $this->s->auth->startSession((int)$user['id'])] + $this->profile((int)$user['id']));
    }

    /** Google sign-in works only for people a school has already added (by the same email). */
    private function google(Request $req): Response
    {
        $idToken = (string)$req->input()->str('idToken', 4000);
        $clientId = (string)Config::get('google_client_id', '');
        if ($clientId === '') throw HttpError::badRequest('Google sign-in is not set up yet.');
        $raw = @file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        $claims = $raw ? json_decode($raw, true) : null;
        $valid = is_array($claims)
            && ($claims['aud'] ?? '') === $clientId
            && in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['email_verified'] ?? '') === 'true'
            && (int)($claims['exp'] ?? 0) > time();
        if (!$valid) throw HttpError::unauthorized('Google sign-in did not work. Please try again.');

        $db = $this->s->db;
        $user = $db->one('SELECT * FROM users WHERE google_sub = ? AND is_active = 1', [$claims['sub']])
            ?? $db->one('SELECT * FROM users WHERE email = ? AND is_active = 1', [strtolower($claims['email'])]);
        if (!$user) throw HttpError::forbidden('This Google account is not added to any school yet. Please ask your school office.');
        if (!$user['google_sub']) $db->exec('UPDATE users SET google_sub = ? WHERE id = ?', [$claims['sub'], $user['id']]);
        return Response::ok(['token' => $this->s->auth->startSession((int)$user['id'])] + $this->profile((int)$user['id']));
    }

    private function findInvite(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw HttpError::notFound('This link is not valid.');
        $invite = $this->s->db->one(
            'SELECT i.*, u.name, u.email, u.phone, s.name AS school_name, s.slug, s.logo_url, s.color
               FROM invites i JOIN users u ON u.id = i.user_id LEFT JOIN schools s ON s.id = i.school_id
              WHERE i.token_hash = ?',
            [hash('sha256', $token)],
        );
        if (!$invite) throw HttpError::notFound('This link is not valid.');
        if ($invite['used_at']) throw HttpError::conflict('This link was already used. Please sign in.');
        if (strtotime($invite['expires_at']) < time()) throw new HttpError(410, 'This link has expired. Please ask your school for a new one.');
        return $invite;
    }

    private function invitePeek(Request $req): array
    {
        $i = $this->findInvite((string)($req->query['token'] ?? ''));
        return [
            'name' => $i['name'],
            'login' => $i['phone'] ?: $i['email'],
            'school' => $i['school_id'] ? ['name' => $i['school_name'], 'slug' => $i['slug'], 'logoUrl' => $i['logo_url'], 'color' => $i['color']] : null,
        ];
    }

    private function acceptInvite(Request $req): Response
    {
        $in = $req->input();
        $invite = $this->findInvite((string)$in->str('token', 100));
        $password = (string)$in->str('password', 200);
        Auth::checkPassword($password);
        $db = $this->s->db;
        $db->tx(function () use ($db, $invite, $password, $in) {
            $db->exec('UPDATE users SET password_hash = ?, name = COALESCE(?, name) WHERE id = ?', [Auth::hashPassword($password), $in->str('name', 120, false), $invite['user_id']]);
            $db->exec('UPDATE invites SET used_at = NOW() WHERE id = ?', [$invite['id']]);
        });
        $userId = (int)$invite['user_id'];
        return Response::ok(['token' => $this->s->auth->startSession($userId)] + $this->profile($userId));
    }

    private function logout(Request $req): array
    {
        $this->s->auth->endSession($req);
        return ['ok' => true];
    }

    private function me(Request $req): array
    {
        return $this->profile($req->userId());
    }

    private function updateMe(Request $req): array
    {
        $in = $req->input();
        $db = $this->s->db;
        if ($in->has('language')) $db->exec('UPDATE users SET language = ? WHERE id = ?', [$in->oneOf('language', ['en', 'hi']), $req->userId()]);
        if ($in->has('name')) $db->exec('UPDATE users SET name = ? WHERE id = ?', [$in->str('name', 120), $req->userId()]);
        if ($in->has('password')) {
            $password = (string)$in->str('password', 200);
            Auth::checkPassword($password);
            $db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($password), $req->userId()]);
        }
        return $this->profile($req->userId());
    }

    /** The signed-in person and every school they belong to, with their roles there. */
    private function profile(int $userId): array
    {
        $db = $this->s->db;
        $u = $db->one('SELECT id, name, email, phone, language, is_platform_admin FROM users WHERE id = ?', [$userId]);
        $rows = $db->all(
            'SELECT s.id, s.slug, s.name, s.logo_url, s.color, s.status, m.role
               FROM memberships m JOIN schools s ON s.id = m.school_id
              WHERE m.user_id = ? ORDER BY s.name',
            [$userId],
        );
        $schools = [];
        foreach ($rows as $row) {
            $schools[$row['id']] ??= [
                'id' => (int)$row['id'], 'slug' => $row['slug'], 'name' => $row['name'],
                'logoUrl' => $row['logo_url'], 'color' => $row['color'], 'status' => $row['status'], 'roles' => [],
            ];
            $schools[$row['id']]['roles'][] = $row['role'];
        }
        return [
            'user' => [
                'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
                'language' => $u['language'], 'isPlatformAdmin' => (bool)$u['is_platform_admin'],
            ],
            'schools' => array_values($schools),
        ];
    }

    private function ip(Request $req): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? $req->header('x-test-ip') ?: 'cli'), 0, 45);
    }
}
