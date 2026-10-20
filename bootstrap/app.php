<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\Idempotent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->statefulApi();
        $m->alias(['active' => ActiveUser::class, 'admin' => AdminOnly::class, 'idempotent' => Idempotent::class]);
        $m->redirectGuestsTo(fn () => '/');
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->shouldRenderJsonWhen(fn (Request $r, $error) => $r->is('api/*') || $r->expectsJson());
        $e->render(function (ValidationException $ex, Request $r) {
            if ($r->is('api/*') || $r->expectsJson()) {
                return response()->json(['message' => $ex->getMessage(), 'code' => 'validation_error', 'errors' => $ex->errors(), 'request_id' => (string) Str::uuid()], 422);
            }
        });
        $e->render(function (HttpExceptionInterface $ex, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => $ex->getMessage() ?: 'Request could not be completed.', 'code' => match ($ex->getStatusCode()) {
                    401 => 'unauthenticated',403 => 'forbidden',404 => 'not_found',409 => 'conflict',422 => 'validation_error',429 => 'rate_limited',default => 'request_error'
                }, 'errors' => (object) [], 'request_id' => (string) Str::uuid()], $ex->getStatusCode(), $ex->getHeaders());
            }
        });
    })->create();
