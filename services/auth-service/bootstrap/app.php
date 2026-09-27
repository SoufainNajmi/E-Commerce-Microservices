<?php

use App\Http\Middleware\PreventAuthCaching;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->api(append: [PreventAuthCaching::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => true);
        $exceptions->render(function (Throwable $e, Request $request) {
            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            $message = match ($status) {
                422 => 'Validation failed',
                401 => $e->getMessage() === 'Invalid credentials' ? 'Invalid credentials' : 'Unauthenticated',
                403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed',
                429 => 'Too many requests', default => 'Request failed',
            };

            return response()->json(['success' => false, 'message' => $message, 'errors' => $e instanceof ValidationException ? $e->errors() : (object) []], $status, array_merge($e instanceof HttpExceptionInterface ? $e->getHeaders() : [], ['Cache-Control' => 'no-store, private']));
        });
    })->create();
