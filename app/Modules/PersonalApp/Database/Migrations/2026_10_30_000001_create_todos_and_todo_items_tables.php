<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('personal_app')->create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20)->default('medium'); // low | medium | high
            $table->date('due_date')->nullable();
            $table->string('due_time', 10)->nullable(); // HH:mm
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_completed', 'due_date']);
            $table->index(['user_id', 'priority']);
        });

        Schema::connection('personal_app')->create('todo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('todo_id')->constrained('todos')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['todo_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection('personal_app')->dropIfExists('todo_items');
        Schema::connection('personal_app')->dropIfExists('todos');
    }
};
