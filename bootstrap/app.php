<?php

use App\Core\Http\Middleware\SetModuleContext;
use App\Core\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'module.context' => SetModuleContext::class,
        ]);

        // Pastikan SetModuleContext berjalan SEBELUM AuthenticatesRequests (auth:sanctum)
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetModuleContext::class
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pure API-Only: seluruh respons error wajib berbentuk JSON murni
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::error(
                message: 'Data yang dikirim tidak valid.',
                statusCode: 422,
                errors: $e->errors()
            );
        });

        $exceptions->render(function (AuthenticationException $e) {
            return ApiResponse::error(
                message: 'Sesi telah berakhir atau token tidak valid. Silakan login kembali.',
                statusCode: 401
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $e) {
            return ApiResponse::error(
                message: 'Anda tidak memiliki akses untuk melakukan tindakan ini.',
                statusCode: 403
            );
        });

        $exceptions->render(function (NotFoundHttpException $e) {
            return ApiResponse::error(
                message: 'Endpoint atau data yang diminta tidak ditemukan.',
                statusCode: 404
            );
        });
    })->create();
