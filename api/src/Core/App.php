<?php
declare(strict_types=1);

namespace School\Core;

/** Wires modules together and turns a Request into a Response. */
final class App
{
    public readonly Router $router;
    public readonly Services $services;
    /** @var array<string, list<string>> */
    private array $permissions = [];

    /** @param list<class-string<Module>> $moduleClasses */
    public function __construct(Db $db, array $moduleClasses)
    {
        $this->router = new Router();
        $events = new Events();
        $this->services = new Services($db, $events, new Auth($db));
        (new Audit($db))->listen($events);
        foreach ($moduleClasses as $class) {
            /** @var Module $module */
            $module = new $class($this->services);
            foreach ($module->permissions() as $perm => $roles) {
                if (isset($this->permissions[$perm])) throw new \LogicException("Permission $perm defined twice");
                $this->permissions[$perm] = $roles;
            }
            $module->routes($this->router);
            $module->listen($events);
        }
    }

    public function handle(Request $req): Response
    {
        try {
            [$route, $params] = $this->router->match($req->method, $req->path);
            $req->params = $params;
            if ($route['auth'] !== 'none') {
                $req->user = $this->services->auth->userFromRequest($req) ?? throw HttpError::unauthorized();
                if ($route['auth'] === 'platform' && !$req->user['is_platform_admin']) throw HttpError::forbidden();
            }
            if (isset($params['schoolId'])) {
                $req->tenant = $this->tenant($req, $req->intParam('schoolId'));
                if ($route['perm']) $req->tenant->require($route['perm']);
            }
            $result = ($route['handler'])($req);
            return $result instanceof Response ? $result : Response::ok($result ?? []);
        } catch (HttpError $e) {
            return new Response($e->status, ['error' => $e->getMessage()] + $e->extra);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) return new Response(409, ['error' => 'This already exists.']);
            error_log('[school-api] ' . $e);
            return new Response(500, ['error' => 'Something went wrong. Please try again.']);
        } catch (\Throwable $e) {
            error_log('[school-api] ' . $e);
            return new Response(500, ['error' => 'Something went wrong. Please try again.']);
        }
    }

    private function tenant(Request $req, int $schoolId): Tenant
    {
        $db = $this->services->db;
        $school = $db->one('SELECT * FROM schools WHERE id = ?', [$schoolId]) ?? throw HttpError::notFound('School not found.');
        $platform = (bool)$req->user['is_platform_admin'];
        $roles = array_column($db->all('SELECT role FROM memberships WHERE school_id = ? AND user_id = ?', [$schoolId, $req->userId()]), 'role');
        if (!$roles && !$platform) throw HttpError::forbidden('You are not part of this school.');
        if ($school['status'] === 'paused' && !$platform) throw HttpError::forbidden('This school account is paused. Please contact Thinkle Tots.');
        return new Tenant($schoolId, $school, $roles, $this->permissions, $platform);
    }
}
