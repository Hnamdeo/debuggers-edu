<?php
declare(strict_types=1);

namespace School\Core;

/**
 * A feature area (Attendance, Homework, ...). A module owns its routes, permissions,
 * event listeners and tables, and talks to other modules only through events or the
 * shared core tables (schools, users, students, divisions). Payroll, Fees and the LMS
 * are added later as new modules without touching existing ones.
 */
interface Module
{
    public function name(): string;

    /** @return array<string, list<string>> permission => roles that have it */
    public function permissions(): array;

    public function routes(Router $router): void;

    public function listen(Events $events): void;
}
