<?php

namespace App\Modules\PersonalApp;

use Illuminate\Support\ServiceProvider;

class PersonalAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/Routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/Routes/channels.php');
    }
}
