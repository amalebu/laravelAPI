<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('personal_app')->create('investment_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('total_topup', 18, 2)->default(0);
            $table->decimal('total_withdrawn', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::connection('personal_app')->create('investment_cash_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30); // topup | withdraw | buy_stock | sell_stock | adjustment
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->date('transacted_at');
            $table->timestamps();

            $table->index(['user_id', 'transacted_at']);
        });

        Schema::connection('personal_app')->create('investment_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticker', 20); // BBCA, PSAT, BBRI
            $table->string('yahoo_symbol', 30); // BBCA.JK, PSAT.JK
            $table->string('company_name', 255)->nullable();
            $table->decimal('last_price', 18, 2)->default(0);
            $table->decimal('previous_close', 18, 2)->default(0);
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ticker']);
            $table->index(['user_id']);
        });

        Schema::connection('personal_app')->create('investment_stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('investment_stocks')->cascadeOnDelete();
            $table->date('opened_at');
            $table->unsignedInteger('initial_lots');
            $table->unsignedInteger('remaining_lots');
            $table->decimal('buy_price', 18, 2); // Harga pembukaan per lembar
            $table->decimal('buy_fee', 18, 2)->default(0); // Total komisi beli
            $table->boolean('deducted_from_cash')->default(true);
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('open'); // open | partial | closed
            $table->timestamps();

            $table->index(['user_id', 'stock_id', 'status']);
            $table->index(['stock_id', 'opened_at']);
        });

        Schema::connection('personal_app')->create('investment_stock_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('investment_stocks')->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->nullable()->constrained('investment_stock_lots')->nullOnDelete();
            $table->date('sold_at');
            $table->unsignedInteger('sold_lots');
            $table->decimal('buy_avg_price', 18, 2);
            $table->decimal('sell_price', 18, 2);
            $table->decimal('sell_fee', 18, 2)->default(0);
            $table->decimal('gross_proceeds', 18, 2)->default(0);
            $table->decimal('net_proceeds', 18, 2)->default(0);
            $table->decimal('realized_pl', 18, 2)->default(0);
            $table->decimal('realized_pl_percent', 10, 2)->default(0);
            $table->boolean('added_to_cash')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('personal_app')->dropIfExists('investment_stock_sales');
        Schema::connection('personal_app')->dropIfExists('investment_stock_lots');
        Schema::connection('personal_app')->dropIfExists('investment_stocks');
        Schema::connection('personal_app')->dropIfExists('investment_cash_mutations');
        Schema::connection('personal_app')->dropIfExists('investment_cash_accounts');
    }
};
