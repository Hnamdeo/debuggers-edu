<?php
declare(strict_types=1);

namespace School\Core;

/** An HTTP request as plain data, so the whole app can be driven from tests without a web server. */
final class Request
{
    /** @var array<string,string> filled by the router from {placeholders} */
    public array $params = [];
    /** Set by Auth once the bearer token is checked. */
    public ?array $user = null;
    /** Set by Tenant for /schools/{schoolId}/... routes. */
    public ?Tenant $tenant = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
    ) {}

    public static function fromGlobals(string $basePath): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) $uri = substr($uri, strlen($basePath));
        $raw = file_get_contents('php://input') ?: '';
        $body = $raw !== '' ? json_decode($raw, true) : [];
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
        }
        // Some Apache setups hide Authorization unless .htaccess passes it on
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($uri, '/'),
            $_GET,
            is_array($body) ? $body : [],
            $headers,
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function intParam(string $name): int
    {
        $v = $this->params[$name] ?? '';
        if (!ctype_digit($v)) throw HttpError::notFound();
        return (int)$v;
    }

    public function input(): Input
    {
        return new Input($this->body);
    }

    public function userId(): int
    {
        return (int)($this->user['id'] ?? 0);
    }
}
