<?php

namespace Tests\Feature;

use App\Modules\PersonalApp\Database\Seeders\PersonalAppDatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PersonalAppHabitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', [
            '--database' => 'personal_app',
            '--path' => 'app/Modules/PersonalApp/Database/Migrations',
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Artisan::call('db:seed', [
            '--database' => 'personal_app',
            '--class' => PersonalAppDatabaseSeeder::class,
            '--force' => true,
        ]);

        parent::tearDown();
    }

    private function authenticateUser(string $phone = '81255556666', string $pin = '123456'): string
    {
        $res = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => $phone,
            'pin' => $pin,
            'pin_confirmation' => $pin,
        ]);

        $res->assertOk();

        return $res->json('data.token');
    }

    public function test_good_habit_quantifiable_progress_backfill_and_streak_calculation(): void
    {
        $token = $this->authenticateUser();
        $today = Carbon::now()->format('Y-m-d');
        $yesterday = Carbon::now()->subDay()->format('Y-m-d');

        // 1. Create Good Habit: Minum Air 2 Liter (step 0.5)
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/habits', [
                'name' => 'Minum Air 2 Liter',
                'description' => 'Hidrasi harian',
                'type' => 'good',
                'icon' => 'droplets',
                'unit' => 'Liter',
                'target_value' => 2,
                'step_value' => 0.5,
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.type', 'good')
            ->assertJsonPath('data.target_value', 2)
            ->assertJsonPath('data.step_value', 0.5)
            ->assertJsonPath('data.today_value', 0)
            ->assertJsonPath('data.is_today_goal_met', false);

        $habitId = $createRes->json('data.id');

        // 2. Log +0.5L today -> today_value = 0.5 (not yet 2L)
        $log1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/habits/{$habitId}/log", [
                'mode' => 'increment',
                'value' => 0.5,
            ]);

        $log1->assertOk()
            ->assertJsonPath('data.today_value', 0.5)
            ->assertJsonPath('data.is_today_goal_met', false);

        // 3. Backfill yesterday with 2.0L -> streak becomes 1
        $logYesterday = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/habits/{$habitId}/log", [
                'logged_date' => $yesterday,
                'mode' => 'set',
                'value' => 2,
            ]);

        $logYesterday->assertOk()
            ->assertJsonPath('data.current_streak', 1);

        // 4. Add +1.5L today -> today_value = 2.0 (meets 2L target!) -> streak becomes 2!
        $log2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/habits/{$habitId}/log", [
                'mode' => 'increment',
                'value' => 1.5,
            ]);

        $log2->assertOk()
            ->assertJsonPath('data.today_value', 2)
            ->assertJsonPath('data.is_today_goal_met', true)
            ->assertJsonPath('data.current_streak', 2)
            ->assertJsonPath('data.longest_streak', 2);
    }

    public function test_bad_habit_threshold_and_relapse_tracking(): void
    {
        $token = $this->authenticateUser();

        // 1. Create Bad Habit: Batasi Kopi Maks 1 Gelas
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/habits', [
                'name' => 'Batasi Kopi Manis',
                'type' => 'bad',
                'icon' => 'coffee',
                'unit' => 'Gelas',
                'target_value' => 1,
                'step_value' => 1,
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.type', 'bad')
            ->assertJsonPath('data.today_value', 0)
            ->assertJsonPath('data.is_today_goal_met', true)
            ->assertJsonPath('data.current_streak', 1);

        $habitId = $createRes->json('data.id');

        // 2. Log 1 Gelas (slightly tempted, still <= max 1) -> is_today_goal_met remains true
        $logTempted = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/habits/{$habitId}/log", [
                'mode' => 'increment',
                'value' => 1,
            ]);

        $logTempted->assertOk()
            ->assertJsonPath('data.today_value', 1)
            ->assertJsonPath('data.is_today_goal_met', true)
            ->assertJsonPath('data.current_streak', 1);

        // 3. Log another +1 Gelas -> total 2 (> max 1) -> Relapse! Streak resets to 0
        $logRelapse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/habits/{$habitId}/log", [
                'mode' => 'increment',
                'value' => 1,
            ]);

        $logRelapse->assertOk()
            ->assertJsonPath('data.today_value', 2)
            ->assertJsonPath('data.is_today_goal_met', false)
            ->assertJsonPath('data.current_streak', 0);
    }
}
