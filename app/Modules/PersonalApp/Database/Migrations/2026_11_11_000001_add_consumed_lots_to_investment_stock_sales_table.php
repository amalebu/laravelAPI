<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('personal_app')->table('investment_stock_sales', function (Blueprint $table) {
            $table->json('consumed_lots')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::connection('personal_app')->table('investment_stock_sales', function (Blueprint $table) {
            $table->dropColumn('consumed_lots');
        });
    }
};
