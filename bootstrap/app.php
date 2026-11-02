<?php

use App\Domain\Support\DomainError;
use App\Http\Api\ApiResponse;
use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ParseXmlBody;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias([
            'api.key' => ApiKeyAuth::class,
            'xml.body' => ParseXmlBody::class,
            'idempotent' => Idempotency::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $r) => $r->is('api/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $isApi($r) || $r->expectsJson());

        // Бизнес-ошибки: в API — JSON/XML с кодом, в интерфейсе — сообщение у формы
        $exceptions->render(function (DomainError $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return ApiResponse::error($e->getMessage(), $e->status, $e->field ? [$e->field => [$e->getMessage()]] : null, $r);
            }
            if ($r->expectsJson() && ! $r->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage(), 'field' => $e->field], $e->status);
            }

            return back()->withErrors([$e->field ?? 'domain' => $e->getMessage()])->with('error', $e->getMessage());
        });

        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return ApiResponse::error('Ошибка данных', 400, $e->errors(), $r);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::error('Требуется аутентификация', 401, null, $r) : null;
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::error('Не найдено', 404, null, $r) : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            return $isApi($r) ? ApiResponse::error($e->getMessage() ?: 'Ошибка', $e->getStatusCode(), null, $r) : null;
        });
    })->create();
