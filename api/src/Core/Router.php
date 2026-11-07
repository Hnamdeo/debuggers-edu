<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Maps METHOD + path pattern to a handler. Options per route:
 *   auth: 'none' | 'user' (default) | 'platform'
 *   perm: a permission the user needs in the school ({schoolId} routes only)
 * Any route under /schools/{schoolId}/ gets a Tenant, so a handler can never
 * forget to check that the user belongs to that school.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, names:list<string>, handler:callable, auth:string, perm:?string}> */
    private array $routes = [];

    public function get(string $p, callable $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable $h, array $o = []): void { $this->add('PUT', $p, $h, $o); }
    public function patch(string $p, callable $h, array $o = []): void { $this->add('PATCH', $p, $h, $o); }
    public function delete(string $p, callable $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        preg_match_all('/\{(\w+)\}/', $pattern, $m);
        $regex = '#^' . preg_replace('/\\\{(\w+)\\\}/', '([^/]+)', preg_quote($pattern, '#')) . '$#';
        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'names' => $m[1],
            'handler' => $handler,
            'auth' => $options['auth'] ?? 'user',
            'perm' => $options['perm'] ?? null,
        ];
    }

    /** @return array{0: array, 1: array<string,string>} */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) continue;
            $pathMatched = true;
            if ($route['method'] !== $method) continue;
            return [$route, array_combine($route['names'], array_map('urldecode', array_slice($m, 1))) ?: []];
        }
        throw $pathMatched ? new HttpError(405, 'Method not allowed.') : HttpError::notFound('No such page.');
    }

    /** @return list<string> e.g. "GET /schools/{schoolId}/students", for docs and tests */
    public function list(): array
    {
        return array_map(fn($r) => $r['method'] . ' ' . $r['regex'], $this->routes);
    }
}
