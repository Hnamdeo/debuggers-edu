<?php
declare(strict_types=1);

// Single entry point for the school API: every /api/... request lands here (see .htaccess).
require __DIR__ . '/src/autoload.php';

use School\Core\{App, Db, HttpError, Request, Response};

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

try {
    $app = new App(Db::get(), require __DIR__ . '/src/modules.php');
    $response = $app->handle(Request::fromGlobals('/api'));
} catch (HttpError $e) {
    $response = new Response($e->status, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[school-api] ' . $e);
    $response = new Response(503, ['error' => 'The school app is starting up. Please try again in a minute.']);
}
$response->send();
