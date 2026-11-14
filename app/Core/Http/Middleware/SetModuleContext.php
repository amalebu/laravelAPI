<?php

namespace App\Core\Http\Middleware;

use App\Core\Http\Responses\ApiResponse;
use App\Core\Models\ModulePersonalAccessToken;
use App\Core\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class SetModuleContext
{
    /**
     * Mengarahkan koneksi database default, model User, dan model Sanctum token
     * ke database SQLite modul yang sedang diakses.
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $module = ModuleRegistry::resolve($moduleKey);

        if (! $module) {
            return ApiResponse::error(
                message: "Modul '{$moduleKey}' tidak terdaftar pada sistem.",
                statusCode: 404
            );
        }

        $connection = $module['connection'];
        $userModel = $module['user_model'];

        // 1. Ubah default database connection ke SQLite modul terkait
        DB::setDefaultConnection($connection);
        config(['database.default' => $connection]);

        // 2. Arahkan Auth User Provider ke model User milik modul terkait
        config(['auth.providers.users.model' => $userModel]);
        Auth::forgetGuards();

        // 3. Pastikan Sanctum menggunakan model PersonalAccessToken dinamis
        Sanctum::usePersonalAccessTokenModel(ModulePersonalAccessToken::class);

        // 4. Pastikan timezone aplikasi sinkron ke Asia/Jakarta (atau header X-Timezone jika valid)
        $clientTimezone = $request->header('X-Timezone', config('app.timezone', 'Asia/Jakarta'));
        if ($clientTimezone && in_array($clientTimezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($clientTimezone);
            config(['app.timezone' => $clientTimezone]);
        }

        // Simpan metadata modul pada request attributes bila dibutuhkan oleh layer lain
        $request->attributes->set('active_module', $module);

        $response = $next($request);

        $content = $response->getContent();
        if (is_string($content) && ! $response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($content));
        }

        return $response;
    }
}
