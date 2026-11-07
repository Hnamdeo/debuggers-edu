<?php
declare(strict_types=1);

namespace School\Core;

/** Roles a person can hold in one school. One person may hold several (a teacher who is also a parent). */
final class Roles
{
    public const ADMIN = 'admin';
    public const PRINCIPAL = 'principal';
    public const ACADEMIC_HEAD = 'academic_head';
    public const TEACHER = 'teacher';
    public const ACCOUNTS = 'accounts';
    public const STAFF = 'staff';
    public const PARENT = 'parent';

    public const ALL = [self::ADMIN, self::PRINCIPAL, self::ACADEMIC_HEAD, self::TEACHER, self::ACCOUNTS, self::STAFF, self::PARENT];
    public const LEADERS = [self::ADMIN, self::PRINCIPAL];
    public const ACADEMIC = [self::ADMIN, self::PRINCIPAL, self::ACADEMIC_HEAD, self::TEACHER];
    public const EMPLOYEES = [self::ADMIN, self::PRINCIPAL, self::ACADEMIC_HEAD, self::TEACHER, self::ACCOUNTS, self::STAFF];
}
