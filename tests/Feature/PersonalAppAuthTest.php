<?php

namespace Tests\Feature;

use App\Modules\PersonalApp\Database\Seeders\PersonalAppDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PersonalAppAuthTest extends TestCase
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

    public function test_new_phone_user_can_create_pin_and_returning_user_can_login_with_pin(): void
    {
        // 1. Check new phone number -> has_pin: false
        $checkResponse = $this->postJson('/api/v1/personal-app/auth/phone/check', [
            'phone' => '81299887766',
        ]);

        $checkResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '+6281299887766')
            ->assertJsonPath('data.is_registered', false)
            ->assertJsonPath('data.has_pin', false);

        // 2. First time login -> create 6-digit PIN (2x confirmation)
        $createPinResponse = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => '81299887766',
            'pin' => '654321',
            'pin_confirmation' => '654321',
        ]);

        $createPinResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.phone', '+6281299887766')
            ->assertJsonPath('data.user.has_pin', true)
            ->assertJsonPath('data.is_new_user', true);

        // 3. Returning user check -> has_pin: true
        $checkReturningResponse = $this->postJson('/api/v1/personal-app/auth/phone/check', [
            'phone' => '81299887766',
        ]);

        $checkReturningResponse->assertOk()
            ->assertJsonPath('data.is_registered', true)
            ->assertJsonPath('data.has_pin', true);

        // 4. Returning user login with wrong PIN -> 422
        $wrongPinResponse = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => '81299887766',
            'pin' => '000000',
        ]);

        $wrongPinResponse->assertStatus(422)
            ->assertJsonPath('success', false);

        // 5. Returning user login with correct PIN -> 200
        $loginPinResponse = $this->postJson('/api/v1/personal-app/auth/phone/pin', [
            'phone' => '81299887766',
            'pin' => '654321',
        ]);

        $loginPinResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_new_user', false);
    }

    public function test_google_oauth_user_can_setup_pin_and_unlock_app_lock(): void
    {
        Http::fake([
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-uid-998877',
                'email' => 'ama.google@example.com',
                'name' => 'Ama Google',
                'picture' => 'https://example.com/avatar.png',
            ], 200),
        ]);

        // 1. Login with Google for the first time -> requires_pin_setup: true
        $response = $this->postJson('/api/v1/personal-app/auth/google', [
            'access_token' => 'fake-google-access-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ama.google@example.com')
            ->assertJsonPath('data.user.has_pin', false)
            ->assertJsonPath('data.requires_pin_setup', true);

        $token = $response->json('data.token');

        // 2. Setup 6-digit PIN
        $setupResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/personal-app/auth/pin/setup', [
                'pin' => '112233',
                'pin_confirmation' => '112233',
            ]);

        $setupResponse->assertOk()
            ->assertJsonPath('data.has_pin', true);

        // 3. Verify PIN to unlock App Lock screen
        $verifyResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/personal-app/auth/pin/verify', [
                'pin' => '112233',
            ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.unlocked', true);
    }
}
