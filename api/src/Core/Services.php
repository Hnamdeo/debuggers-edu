<?php
declare(strict_types=1);

namespace School\Core;

/** Shared services handed to every module (a tiny container, no framework). */
final class Services
{
    public function __construct(
        public readonly Db $db,
        public readonly Events $events,
        public readonly Auth $auth,
    ) {}
}
