<?php

use App\Core\Http\Responses\ApiResponse;
use App\Core\Support\ModuleRegistry;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return ApiResponse::success(
        data: [
            'service' => 'laravelAPI',
            'architecture' => 'Modular Monolith Multi-SQLite',
            'websocket' => 'Laravel Reverb',
            'active_modules' => array_keys(ModuleRegistry::modules()),
        ],
        message: 'Layanan laravelAPI berjalan normal.'
    );
});
