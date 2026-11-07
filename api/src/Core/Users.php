<?php
declare(strict_types=1);

namespace School\Core;

/**
 * One account per real person, matched by mobile or email, so a teacher who is also a
 * parent, or a parent with children in two schools, signs in once and sees everything.
 */
final class Users
{
    public function __construct(private readonly Db $db) {}

    public function findOrCreate(string $name, ?string $phone, ?string $email): int
    {
        if (!$phone && !$email) throw HttpError::badRequest('Please give a mobile number or an email.', ['field' => 'phone']);
        $byPhone = $phone ? $this->db->one('SELECT id, email FROM users WHERE phone = ?', [$phone]) : null;
        $byEmail = $email ? $this->db->one('SELECT id, phone FROM users WHERE email = ?', [$email]) : null;
        if ($byPhone && $byEmail && $byPhone['id'] !== $byEmail['id']) {
            throw HttpError::conflict('This mobile number and this email belong to two different people.');
        }
        $existing = $byPhone ?? $byEmail;
        if ($existing) {
            $id = (int)$existing['id'];
            // Fill in what we did not know before, never overwrite
            if ($phone && !$byPhone) $this->db->exec('UPDATE users SET phone = ? WHERE id = ? AND phone IS NULL', [$phone, $id]);
            if ($email && !$byEmail) $this->db->exec('UPDATE users SET email = ? WHERE id = ? AND email IS NULL', [$email, $id]);
            return $id;
        }
        return $this->db->insert('users', ['name' => $name, 'phone' => $phone, 'email' => $email]);
    }

    public function addRole(int $schoolId, int $userId, string $role): void
    {
        if (!in_array($role, Roles::ALL, true)) throw HttpError::badRequest('Unknown role.');
        $this->db->exec('INSERT IGNORE INTO memberships (school_id, user_id, role) VALUES (?, ?, ?)', [$schoolId, $userId, $role]);
    }

    public function removeRole(int $schoolId, int $userId, string $role): void
    {
        $this->db->exec('DELETE FROM memberships WHERE school_id = ? AND user_id = ? AND role = ?', [$schoolId, $userId, $role]);
    }

    public function hasPassword(int $userId): bool
    {
        return (bool)$this->db->value('SELECT password_hash IS NOT NULL FROM users WHERE id = ?', [$userId]);
    }
}
