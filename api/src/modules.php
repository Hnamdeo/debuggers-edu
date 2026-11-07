<?php
declare(strict_types=1);

// The modules this app runs. A new feature area (Fees, Payroll, Syllabus, LMS) is one new
// class added to this list; nothing else changes.
return [
    School\Modules\PlatformModule::class,
    School\Modules\AuthModule::class,
    School\Modules\SetupModule::class,
    School\Modules\PeopleModule::class,
    School\Modules\AttendanceModule::class,
    School\Modules\HomeworkModule::class,
    School\Modules\NoticesModule::class,
    School\Modules\AdmissionsModule::class,
    School\Modules\SyllabusModule::class,
    School\Modules\HomeModule::class,
];
