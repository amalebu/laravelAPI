<?php

namespace App\Modules\PersonalApp\Database\Seeders;

use App\Modules\PersonalApp\Models\Habit;
use App\Modules\PersonalApp\Models\Todo;
use App\Modules\PersonalApp\Models\User;
use App\Modules\PersonalApp\Services\HabitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class PersonalAppDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['phone' => '+6281234567890'],
            [
                'name' => 'Ama Demo',
                'email' => 'demo@personal.app',
                'pin' => Hash::make('123456'),
                'phone_verified_at' => now(),
                'password' => Hash::make('password123'),
            ]
        );

        if (Todo::query()->where('user_id', $user->id)->count() === 0) {
            $today = Carbon::now()->format('Y-m-d');
            $tomorrow = Carbon::now()->addDay()->format('Y-m-d');

            $todo1 = Todo::query()->create([
                'user_id' => $user->id,
                'title' => 'Review pengeluaran & anggaran minggu ini',
                'description' => 'Rekap struk belanja dan cek sisa budget bulanan.',
                'priority' => 'high',
                'due_date' => $today,
                'due_time' => '19:00',
                'is_completed' => false,
            ]);

            $todo1->items()->createMany([
                ['title' => 'Catat pengeluaran makan & transportasi', 'is_completed' => true, 'sort_order' => 0],
                ['title' => 'Cek saldo rekening utama & e-wallet', 'is_completed' => false, 'sort_order' => 1],
                ['title' => 'Sisihkan dana tabungan rutin', 'is_completed' => false, 'sort_order' => 2],
            ]);

            $todo2 = Todo::query()->create([
                'user_id' => $user->id,
                'title' => 'Olahraga sore & persiapan esok hari',
                'description' => 'Jogging ringan 30 menit di sekitar rumah.',
                'priority' => 'medium',
                'due_date' => $tomorrow,
                'due_time' => '16:30',
                'is_completed' => false,
            ]);

            $todo2->items()->createMany([
                ['title' => 'Siapkan sepatu & botol minum', 'is_completed' => false, 'sort_order' => 0],
                ['title' => 'Pemanasan 5 menit', 'is_completed' => false, 'sort_order' => 1],
            ]);
        }

        if (Habit::query()->where('user_id', $user->id)->count() === 0) {
            $habitService = app(HabitService::class);

            // 1. Good Habit: Minum Air Putih 2 Liter
            $waterHabit = Habit::query()->create([
                'user_id' => $user->id,
                'name' => 'Minum Air Putih 2 Liter',
                'description' => 'Jaga hidrasi tubuh minimal 2 liter per hari.',
                'type' => 'good',
                'icon' => 'droplets',
                'unit' => 'Liter',
                'target_value' => 2.0,
                'step_value' => 0.5,
                'is_active' => true,
            ]);

            $waterSamples = [2.0, 2.5, 1.5, 2.0, 1.0, 2.0, 2.0, 0.5, 2.0, 2.5, 2.0, 1.5, 2.0, 1.5];
            foreach ($waterSamples as $daysAgo => $val) {
                $waterHabit->logs()->create([
                    'user_id' => $user->id,
                    'logged_date' => Carbon::now()->subDays($daysAgo)->format('Y-m-d'),
                    'value' => $val,
                ]);
            }
            $habitService->recalculateStreaks($waterHabit);

            // 2. Good Habit: Olahraga / Jalan Pagi 30 Menit
            $workoutHabit = Habit::query()->create([
                'user_id' => $user->id,
                'name' => 'Olahraga & Stretching',
                'description' => 'Target minimal 30 menit aktivitas fisik setiap hari.',
                'type' => 'good',
                'icon' => 'dumbbell',
                'unit' => 'Menit',
                'target_value' => 30,
                'step_value' => 10,
                'is_active' => true,
            ]);

            $workoutSamples = [30, 30, 20, 30, 0, 30, 40, 10, 30, 30];
            foreach ($workoutSamples as $daysAgo => $val) {
                if ($val > 0) {
                    $workoutHabit->logs()->create([
                        'user_id' => $user->id,
                        'logged_date' => Carbon::now()->subDays($daysAgo)->format('Y-m-d'),
                        'value' => $val,
                    ]);
                }
            }
            $habitService->recalculateStreaks($workoutHabit);

            // 3. Remove Bad Habit: Kurangi Minuman Manis / Kopi Susu (Maks 1 gelas)
            $sugarHabit = Habit::query()->create([
                'user_id' => $user->id,
                'name' => 'Batasi Minuman Manis / Boba',
                'description' => 'Putih = 0 gelas (bersih), Agak Merah = 1 gelas, Merah Pekat = > 1 gelas.',
                'type' => 'bad',
                'icon' => 'coffee',
                'unit' => 'Gelas',
                'target_value' => 1,
                'step_value' => 1,
                'is_active' => true,
                'created_at' => Carbon::now()->subDays(14),
            ]);

            $sugarSamples = [0, 0, 1, 0, 2, 0, 1, 0, 0, 3, 0, 1];
            foreach ($sugarSamples as $daysAgo => $val) {
                if ($val > 0) {
                    $sugarHabit->logs()->create([
                        'user_id' => $user->id,
                        'logged_date' => Carbon::now()->subDays($daysAgo)->format('Y-m-d'),
                        'value' => $val,
                    ]);
                }
            }
            $habitService->recalculateStreaks($sugarHabit);
        }
    }
}
