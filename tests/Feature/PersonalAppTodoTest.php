<?php

namespace Tests\Feature;

use App\Modules\PersonalApp\Database\Seeders\PersonalAppDatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PersonalAppTodoTest extends TestCase
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

    private function authenticateUser(string $phone = '81211112222', string $pin = '123456'): string
    {
        $res = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => $phone,
            'pin' => $pin,
            'pin_confirmation' => $pin,
        ]);

        $res->assertOk();

        return $res->json('data.token');
    }

    public function test_todo_with_subchecklists_auto_completes_when_all_items_checked_and_supports_manual_toggle(): void
    {
        $token = $this->authenticateUser();
        $today = Carbon::now()->format('Y-m-d');

        // 1. Create Todo with 2 sub-checklists, priority high, due_date & due_time
        $createRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/personal-app/todos', [
                'title' => 'Persiapan Rilis Produk',
                'description' => 'Pastikan semua checklist rilis terpenuhi',
                'priority' => 'high',
                'due_date' => $today,
                'due_time' => '23:59',
                'items' => [
                    ['title' => 'Review kode backend'],
                    ['title' => 'Uji tampilan mobile PWA'],
                ],
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Persiapan Rilis Produk')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.due_date', $today)
            ->assertJsonPath('data.due_time', '23:59')
            ->assertJsonPath('data.is_completed', false)
            ->assertJsonPath('data.subtasks_total', 2)
            ->assertJsonPath('data.subtasks_completed', 0);

        $todoId = $createRes->json('data.id');
        $firstItemId = $createRes->json('data.items.0.id');
        $secondItemId = $createRes->json('data.items.1.id');

        // 2. Toggle first sub-checklist -> 1/2 completed, parent still false
        $toggleFirst = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/personal-app/todos/{$todoId}/items/{$firstItemId}/toggle");

        $toggleFirst->assertOk()
            ->assertJsonPath('data.subtasks_completed', 1)
            ->assertJsonPath('data.subtasks_total', 2)
            ->assertJsonPath('data.is_completed', false);

        // 3. Toggle second sub-checklist -> 2/2 completed, parent automatically becomes true!
        $toggleSecond = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/personal-app/todos/{$todoId}/items/{$secondItemId}/toggle");

        $toggleSecond->assertOk()
            ->assertJsonPath('data.subtasks_completed', 2)
            ->assertJsonPath('data.subtasks_total', 2)
            ->assertJsonPath('data.is_completed', true);

        // 4. Uncheck second sub-checklist -> 1/2 completed, parent automatically reverts to false!
        $untoggleSecond = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/personal-app/todos/{$todoId}/items/{$secondItemId}/toggle");

        $untoggleSecond->assertOk()
            ->assertJsonPath('data.subtasks_completed', 1)
            ->assertJsonPath('data.is_completed', false);

        // 5. Manually toggle parent todo even with 1/2 sub-checklist -> parent becomes true
        $manualToggle = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/personal-app/todos/{$todoId}/toggle");

        $manualToggle->assertOk()
            ->assertJsonPath('data.is_completed', true)
            ->assertJsonPath('data.subtasks_completed', 1);

        // 6. Add a 3rd sub-checklist inline -> parent reverts to false because new item is uncompleted
        $addItemRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/personal-app/todos/{$todoId}/items", [
                'title' => 'Update dokumentasi rilis',
            ]);

        $addItemRes->assertCreated()
            ->assertJsonPath('data.subtasks_total', 3)
            ->assertJsonPath('data.subtasks_completed', 1)
            ->assertJsonPath('data.is_completed', false);
    }

    public function test_todo_filtering_update_delete_and_user_isolation(): void
    {
        $tokenUserA = $this->authenticateUser('81211112222', '123456');
        $tokenUserB = $this->authenticateUser('81233334444', '654321');

        $today = Carbon::now()->format('Y-m-d');
        $tomorrow = Carbon::now()->addDay()->format('Y-m-d');

        // User A creates 2 todos
        $todoToday = $this->withHeader('Authorization', "Bearer {$tokenUserA}")
            ->postJson('/api/v1/personal-app/todos', [
                'title' => 'Bayar Tagihan Internet',
                'priority' => 'high',
                'due_date' => $today,
            ])->json('data.id');

        $this->withHeader('Authorization', "Bearer {$tokenUserA}")
            ->postJson('/api/v1/personal-app/todos', [
                'title' => 'Beli Kebutuhan Bulanan',
                'priority' => 'low',
                'due_date' => $tomorrow,
            ])->assertCreated();

        // Filter 'today' returns 1 todo and counts summary
        $listRes = $this->withHeader('Authorization', "Bearer {$tokenUserA}")
            ->getJson('/api/v1/personal-app/todos?filter=today');

        $listRes->assertOk()
            ->assertJsonCount(1, 'data.todos')
            ->assertJsonPath('data.counts.today', 1)
            ->assertJsonPath('data.counts.upcoming', 1)
            ->assertJsonPath('data.counts.active', 2);

        // User B cannot modify User A's todo
        $this->withHeader('Authorization', "Bearer {$tokenUserB}")
            ->patchJson("/api/v1/personal-app/todos/{$todoToday}/toggle")
            ->assertNotFound();

        // User A updates and deletes todo
        $this->withHeader('Authorization', "Bearer {$tokenUserA}")
            ->putJson("/api/v1/personal-app/todos/{$todoToday}", [
                'title' => 'Bayar Tagihan Internet & Listrik',
                'priority' => 'medium',
                'due_date' => $today,
                'due_time' => '18:30',
                'items' => [
                    ['title' => 'Cek tagihan IndiHome', 'is_completed' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Bayar Tagihan Internet & Listrik')
            ->assertJsonPath('data.is_completed', true);

        $this->withHeader('Authorization', "Bearer {$tokenUserA}")
            ->deleteJson("/api/v1/personal-app/todos/{$todoToday}")
            ->assertOk();
    }
}
