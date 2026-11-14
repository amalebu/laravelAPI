<?php

use App\Modules\PersonalApp\Http\Controllers\AuthController;
use App\Modules\PersonalApp\Http\Controllers\HabitController;
use App\Modules\PersonalApp\Http\Controllers\InvestmentController;
use App\Modules\PersonalApp\Http\Controllers\TodoController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// Alias callback & redirect Google OAuth sesuai URI yang didaftarkan:
// https://9knv83ss-8000.asse.devtunnels.ms/api/v1/auth/google/callback
Route::prefix('api/v1/auth/google')
    ->middleware(['api', 'module.context:personal_app'])
    ->group(function () {
        Route::get('/redirect', [AuthController::class, 'redirectToGoogle']);
        Route::get('/callback', [AuthController::class, 'handleGoogleCallback']);
    });

Route::prefix('api/v1/personal-app')
    ->middleware(['api', 'module.context:personal_app'])
    ->group(function () {
        // Public Phone + PIN Auth Endpoints
        Route::post('/auth/phone/check', [AuthController::class, 'checkPhone']);
        Route::post('/auth/phone/pin', [AuthController::class, 'authenticateWithPhonePin']);

        // Public Google OAuth Endpoints
        Route::post('/auth/google', [AuthController::class, 'loginWithGoogle']);
        Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
        Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

        // Legacy Email/Password Auth Endpoints
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);

        // Protected Endpoints (Sanctum)
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/auth/pin/setup', [AuthController::class, 'setupPin']);
            Route::post('/auth/pin/verify', [AuthController::class, 'verifyPin']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
            Route::post('/auth/google/link', [AuthController::class, 'linkGoogle']);
            Route::post('/auth/phone/link', [AuthController::class, 'linkPhone']);

            // To-Do List & Sub-Checklist Endpoints
            Route::get('/todos', [TodoController::class, 'index']);
            Route::post('/todos', [TodoController::class, 'store']);
            Route::put('/todos/{id}', [TodoController::class, 'update']);
            Route::patch('/todos/{id}/toggle', [TodoController::class, 'toggle']);
            Route::delete('/todos/{id}', [TodoController::class, 'destroy']);
            Route::post('/todos/{id}/items', [TodoController::class, 'storeItem']);
            Route::patch('/todos/{id}/items/{itemId}/toggle', [TodoController::class, 'toggleItem']);
            Route::delete('/todos/{id}/items/{itemId}', [TodoController::class, 'destroyItem']);

            // Habit Tracker Endpoints (Good Habit & Remove Bad Habit + GitHub Heatmap Logs)
            Route::get('/habits', [HabitController::class, 'index']);
            Route::post('/habits', [HabitController::class, 'store']);
            Route::put('/habits/{id}', [HabitController::class, 'update']);
            Route::delete('/habits/{id}', [HabitController::class, 'destroy']);
            Route::post('/habits/{id}/log', [HabitController::class, 'logProgress']);

            // Investment Portfolio Endpoints (Cash RDN + IDX Stocks + Yahoo Finance)
            Route::get('/investments', [InvestmentController::class, 'index']);
            Route::post('/investments/refresh-prices', [InvestmentController::class, 'refreshPrices']);
            Route::get('/investments/tickers/search', [InvestmentController::class, 'searchTickers']);
            Route::get('/investments/tickers/quote', [InvestmentController::class, 'quoteTicker']);
            Route::post('/investments/cash', [InvestmentController::class, 'adjustCash']);
            Route::post('/investments/lots', [InvestmentController::class, 'buyLot']);
            Route::put('/investments/lots/{lotId}', [InvestmentController::class, 'updateLot']);
            Route::delete('/investments/lots/{lotId}', [InvestmentController::class, 'destroyLot']);
            Route::delete('/investments/sales/{saleId}', [InvestmentController::class, 'destroySale']);
            Route::post('/investments/positions/close', [InvestmentController::class, 'closePosition']);
            Route::post('/investments/recalculate-fees', [InvestmentController::class, 'recalculateFees']);

            // Reverb WebSocket Channel Auth Endpoint khusus modul ini
            Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);
        });
    });
