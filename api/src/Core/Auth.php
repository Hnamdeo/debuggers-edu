<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Sign-in sessions. The browser keeps a random token; the database keeps only its SHA-256,
 * so a leaked database cannot be used to sign in. Sessions last 60 days and can be ended.
 */
final class Auth
{
    public const SESSION_DAYS = 60;

    public function __construct(private readonly Db $db) {}

    public function startSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->insert('sessions', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400),
        ]);
        $this->db->exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
        return $token;
    }

    public function userFromRequest(Request $req): ?array
    {
        $header = $req->header('authorization');
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) return null;
        return $this->db->one(
            'SELECT u.id, u.name, u.email, u.phone, u.is_platform_admin, u.language
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.is_active = 1',
            [hash('sha256', strtolower($m[1]))],
        );
    }

    public function endSession(Request $req): void
    {
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $req->header('authorization'), $m)) {
            $this->db->exec('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', strtolower($m[1]))]);
        }
    }

    /** One-time link (invite or password reset), valid for $days. Returns the raw token for the link. */
    public function createInvite(int $userId, ?int $schoolId, int $createdBy, int $days = 14): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->insert('invites', [
            'user_id' => $userId,
            'school_id' => $schoolId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'created_by' => $createdBy ?: null,
        ]);
        return $token;
    }

    public static function inviteLink(string $token, ?string $schoolSlug): string
    {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        return $base . '/' . ($schoolSlug ? rawurlencode($schoolSlug) . '/' : '') . 'welcome?token=' . $token;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function checkPassword(string $password): void
    {
        if (mb_strlen($password) < 8) throw HttpError::badRequest('Password needs at least 8 characters.', ['field' => 'password']);
        if (mb_strlen($password) > 200) throw HttpError::badRequest('Password is too long.', ['field' => 'password']);
    }
}
