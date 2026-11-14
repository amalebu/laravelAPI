<?php

use App\Modules\PersonalApp\Services\InvestmentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('investment:recalculate-stamp-duty {userId=3}', function (InvestmentService $service) {
    $userId = (int) $this->argument('userId');
    $this->info("Menghitung ulang Fee materai (> Rp 10 Jt/hari) untuk user ID {$userId}...");
    $portfolio = $service->recalculateAllHistoricalStampDuty($userId);
    $this->info("Berhasil! Saldo Cash RDN saat ini: Rp " . number_format($portfolio['cash']['balance'], 2, ',', '.'));
})->purpose('Hitung ulang Fee materai (> Rp 10 Jt/hari) dan rekonsiliasi mutasi Cash RDN');
