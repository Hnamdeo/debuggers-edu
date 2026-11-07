<?php
declare(strict_types=1);

namespace School\Core;

/** Thrown anywhere in a request to stop it with a status code and a message the user can read. */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function badRequest(string $m, array $extra = []): self { return new self(400, $m, $extra); }
    public static function unauthorized(string $m = 'Please sign in again.'): self { return new self(401, $m); }
    public static function forbidden(string $m = 'You do not have access to this.'): self { return new self(403, $m); }
    public static function notFound(string $m = 'Not found.'): self { return new self(404, $m); }
    public static function conflict(string $m): self { return new self(409, $m); }
}
