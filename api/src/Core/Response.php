<?php
declare(strict_types=1);

namespace School\Core;

final class Response
{
    public function __construct(public readonly int $status, public readonly array $body = []) {}

    public static function ok(array $body = []): self { return new self(200, $body); }
    public static function created(array $body = []): self { return new self(201, $body); }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
