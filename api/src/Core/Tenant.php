<?php
declare(strict_types=1);

namespace School\Core;

/** The school a request works in, and what the signed-in person may do there. */
final class Tenant
{
    /** @param list<string> $roles @param array<string, list<string>> $permissionMap */
    public function __construct(
        public readonly int $schoolId,
        public readonly array $school,
        public readonly array $roles,
        private readonly array $permissionMap,
        public readonly bool $platformAdmin = false,
    ) {}

    public function can(string $permission): bool
    {
        if ($this->platformAdmin) return true;
        $allowed = $this->permissionMap[$permission] ?? null;
        if ($allowed === null) throw new \LogicException("Unknown permission $permission");
        return (bool)array_intersect($this->roles, $allowed);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) throw HttpError::forbidden();
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->platformAdmin || (bool)array_intersect($this->roles, $roles);
    }
}
