<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('personal_app')->create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('good'); // good | bad
            $table->string('icon', 50)->default('droplets');
            $table->string('unit', 40)->default('kali'); // Liter, Gelas, Menit, Halaman, Batang, kali
            $table->decimal('target_value', 10, 2)->default(1); // Target minimal (good) atau Batas maksimal (bad)
            $table->decimal('step_value', 10, 2)->default(1); // Takaran per klik +/- (misal 0.5, 1, 10)
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'type', 'is_active']);
        });

        Schema::connection('personal_app')->create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained('habits')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('logged_date');
            $table->decimal('value', 10, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['habit_id', 'logged_date']);
            $table->index(['user_id', 'logged_date']);
        });
    }

    public function down(): void
    {
        Schema::connection('personal_app')->dropIfExists('habit_logs');
        Schema::connection('personal_app')->dropIfExists('habits');
    }
};
