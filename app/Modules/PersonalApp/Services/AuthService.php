<?php

namespace App\Modules\PersonalApp\Services;

use App\Modules\PersonalApp\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Normalisasi format nomor ponsel Indonesia selalu menjadi '628xxxxxxxx' murni (tanpa '+' dan tanpa '08').
     */
    public function normalizePhone(string $rawPhone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $rawPhone) ?? '';

        if (str_starts_with($digits, '08')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        } elseif (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Cek apakah nomor ponsel sudah terdaftar dan sudah memiliki PIN 6 digit.
     *
     * @return array{phone: string, is_registered: bool, has_pin: bool}
     */
    public function checkPhoneStatus(string $rawPhone): array
    {
        $phone = $this->normalizePhone($rawPhone);
        $user = User::query()->where('phone', $phone)->first();

        return [
            'phone' => $phone,
            'is_registered' => (bool) $user,
            'has_pin' => $user ? $user->hasPin() : false,
        ];
    }

    /**
     * Login dengan PIN 6 digit (jika sudah punya PIN) atau registrasi & buat PIN pertama kali (jika belum punya PIN).
     *
     * @return array{user: User, token: string, is_new_user: bool}
     */
    public function authenticateWithPhonePin(string $rawPhone, string $pin, ?string $pinConfirmation = null): array
    {
        $phone = $this->normalizePhone($rawPhone);

        /** @var User|null $user */
        $user = User::query()->where('phone', $phone)->first();

        if ($user && $user->hasPin()) {
            if (! Hash::check($pin, $user->pin)) {
                throw ValidationException::withMessages([
                    'pin' => ['PIN yang Anda masukkan salah. Silakan coba lagi.'],
                ]);
            }

            $token = $user->createToken('personal-app-phone', ['*'], now()->addWeek())->plainTextToken;

            return [
                'user' => $user,
                'token' => $token,
                'is_new_user' => false,
            ];
        }

        if ($pinConfirmation === null || $pin !== $pinConfirmation) {
            throw ValidationException::withMessages([
                'pin' => ['Konfirmasi PIN wajib diisi dan harus sama dengan PIN baru Anda.'],
            ]);
        }

        return DB::connection('personal_app')->transaction(function () use ($phone, $pin, $user) {
            $isNewUser = ! $user;

            if (! $user) {
                $lastDigits = substr($phone, -4);
                $user = User::query()->create([
                    'name' => 'Pengguna ' . $lastDigits,
                    'phone' => $phone,
                    'pin' => $pin,
                    'phone_verified_at' => now(),
                ]);
            } else {
                $user->update([
                    'pin' => $pin,
                    'phone_verified_at' => $user->phone_verified_at ?: now(),
                ]);
            }

            $token = $user->createToken('personal-app-phone', ['*'], now()->addWeek())->plainTextToken;

            return [
                'user' => $user->refresh(),
                'token' => $token,
                'is_new_user' => $isNewUser,
            ];
        });
    }

    /**
     * Simpan atau perbarui PIN 6 digit untuk pengguna yang sedang terautentikasi (misal setelah login Google pertama kali).
     */
    public function setupPin(User $user, string $pin): User
    {
        return DB::connection('personal_app')->transaction(function () use ($user, $pin) {
            $user->update(['pin' => $pin]);

            return $user->refresh();
        });
    }

    /**
     * Verifikasi PIN 6 digit pengguna untuk membuka kunci layar (App Lock).
     */
    public function verifyPin(User $user, string $pin): bool
    {
        if (! $user->hasPin()) {
            throw ValidationException::withMessages([
                'pin' => ['Anda belum mengatur PIN keamanan.'],
            ]);
        }

        if (! Hash::check($pin, $user->pin)) {
            throw ValidationException::withMessages([
                'pin' => ['PIN yang dimasukkan salah.'],
            ]);
        }

        return true;
    }

    /**
     * Autentikasi / Registrasi otomatis menggunakan Google OAuth (Access Token, ID Token, atau Auth Code).
     *
     * @param  array{access_token?: string|null, id_token?: string|null, credential?: string|null, code?: string|null, redirect_uri?: string|null}  $payload
     * @return array{user: User, token: string, requires_pin_setup: bool}
     */
    public function loginWithGoogle(array $payload): array
    {
        $googleUser = $this->resolveGoogleUser($payload);

        $googleId = (string) ($googleUser['sub'] ?? '');
        $email = isset($googleUser['email']) ? strtolower(trim((string) $googleUser['email'])) : null;
        $name = (string) ($googleUser['name'] ?? ($email ? explode('@', $email)[0] : 'Pengguna Google'));
        $avatar = isset($googleUser['picture']) ? (string) $googleUser['picture'] : null;
        $rawPhone = $googleUser['phone_number'] ?? $googleUser['phone'] ?? $payload['phone'] ?? null;
        $phone = $rawPhone ? $this->normalizePhone((string) $rawPhone) : null;

        if ($googleId === '') {
            throw ValidationException::withMessages([
                'google' => ['Gagal memverifikasi identitas akun Google Anda.'],
            ]);
        }

        return DB::connection('personal_app')->transaction(function () use ($googleId, $email, $name, $avatar, $phone) {
            $user = User::query()
                ->where('google_id', $googleId)
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
                ->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'google_id' => $googleId,
                    'avatar' => $avatar,
                    'email_verified_at' => $email ? now() : null,
                ]);
            } else {
                $user->update(array_filter([
                    'google_id' => $googleId,
                    'email' => $user->email ?: $email,
                    'phone' => $user->phone ?: $phone,
                    'avatar' => $user->avatar ?: $avatar,
                    'email_verified_at' => $user->email_verified_at ?: ($email ? now() : null),
                ]));
            }

            $user = $user->refresh();
            $token = $user->createToken('personal-app-google', ['*'], now()->addWeek())->plainTextToken;

            return [
                'user' => $user,
                'token' => $token,
                'requires_pin_setup' => ! $user->hasPin(),
            ];
        });
    }

    /**
     * Tautkan akun Google ke user yang sedang terautentikasi (Account Linking).
     */
    public function linkGoogleAccount(User $user, array $payload): User
    {
        $googleUser = $this->resolveGoogleUser($payload);

        $googleId = (string) ($googleUser['sub'] ?? '');
        $email = isset($googleUser['email']) ? strtolower(trim((string) $googleUser['email'])) : null;
        $avatar = isset($googleUser['picture']) ? (string) $googleUser['picture'] : null;

        if ($googleId === '') {
            throw ValidationException::withMessages([
                'google' => ['Gagal memverifikasi identitas akun Google Anda.'],
            ]);
        }

        // Cek apakah Google ID atau email sudah dipakai oleh akun user lain
        $existing = User::query()
            ->where(function ($q) use ($googleId, $email) {
                $q->where('google_id', $googleId);
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'google' => ['Akun Google ini sudah terhubung dengan pengguna lain. Silakan login menggunakan akun tersebut.'],
            ]);
        }

        return DB::connection('personal_app')->transaction(function () use ($user, $googleId, $email, $avatar) {
            $user->update(array_filter([
                'google_id' => $googleId,
                'email' => $user->email ?: $email,
                'avatar' => $user->avatar ?: $avatar,
                'email_verified_at' => $user->email_verified_at ?: ($email ? now() : null),
            ]));

            return $user->refresh();
        });
    }

    /**
     * Tautkan nomor ponsel dan buat PIN 6 digit untuk user yang sedang terautentikasi.
     */
    public function linkPhoneAccount(User $user, string $rawPhone, string $pin, string $pinConfirmation): User
    {
        if ($pin !== $pinConfirmation) {
            throw ValidationException::withMessages([
                'pin' => ['Konfirmasi PIN tidak cocok dengan PIN yang dimasukkan.'],
            ]);
        }

        $phone = $this->normalizePhone($rawPhone);

        // Cek apakah nomor ponsel sudah digunakan oleh akun lain
        $existing = User::query()
            ->where('phone', $phone)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'phone' => ['Nomor ponsel ini sudah terdaftar pada akun lain. Silakan login menggunakan nomor tersebut.'],
            ]);
        }

        return DB::connection('personal_app')->transaction(function () use ($user, $phone, $pin) {
            $user->update([
                'phone' => $phone,
                'pin' => $pin,
                'phone_verified_at' => $user->phone_verified_at ?: now(),
            ]);

            return $user->refresh();
        });
    }

    /**
     * Bangun URL Redirect Google OAuth 2.0.
     */
    public function getGoogleRedirectUrl(): string
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            throw ValidationException::withMessages([
                'google' => ['GOOGLE_CLIENT_ID belum dikonfigurasi pada file .env backend.'],
            ]);
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => (string) config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    /**
     * Verifikasi token/code ke server Google OAuth.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveGoogleUser(array $payload): array
    {
        $accessToken = $payload['access_token'] ?? null;
        $idToken = $payload['id_token'] ?? $payload['credential'] ?? null;
        $code = $payload['code'] ?? null;

        if (! empty($code)) {
            $tokenResponse = Http::when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'code' => $code,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'redirect_uri' => $payload['redirect_uri'] ?? config('services.google.redirect'),
                    'grant_type' => 'authorization_code',
                ]);

            if (! $tokenResponse->successful()) {
                throw ValidationException::withMessages([
                    'google' => ['Gagal menukarkan kode otorisasi Google OAuth. Pastikan GOOGLE_CLIENT_ID & GOOGLE_CLIENT_SECRET benar.'],
                ]);
            }

            $accessToken = $tokenResponse->json('access_token');
            $idToken = $tokenResponse->json('id_token') ?: $idToken;
        }

        if (! empty($accessToken)) {
            $userResponse = Http::when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->withToken((string) $accessToken)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if (! $userResponse->successful()) {
                throw ValidationException::withMessages([
                    'google' => ['Token akses Google tidak valid atau telah kedaluwarsa.'],
                ]);
            }

            return $userResponse->json();
        }

        if (! empty($idToken)) {
            $tokenInfoResponse = Http::when(app()->isLocal(), fn ($http) => $http->withoutVerifying())
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => (string) $idToken,
                ]);

            if (! $tokenInfoResponse->successful()) {
                throw ValidationException::withMessages([
                    'google' => ['ID Token Google tidak valid atau telah kedaluwarsa.'],
                ]);
            }

            $tokenData = $tokenInfoResponse->json();
            $configuredClientId = (string) config('services.google.client_id');

            if ($configuredClientId !== '' && ($tokenData['aud'] ?? '') !== $configuredClientId) {
                throw ValidationException::withMessages([
                    'google' => ['Client ID pada token Google tidak sesuai dengan konfigurasi server.'],
                ]);
            }

            return $tokenData;
        }

        throw ValidationException::withMessages([
            'google' => ['Parameter kredensial Google tidak ditemukan.'],
        ]);
    }

    /**
     * Registrasi pengguna baru dengan email & password (kompatibilitas).
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        return DB::connection('personal_app')->transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
            ]);

            $token = $user->createToken('personal-app-token')->plainTextToken;

            return [
                'user' => $user,
                'token' => $token,
            ];
        });
    }

    /**
     * Autentikasi pengguna dengan email & password (kompatibilitas).
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, token: string}
     */
    public function login(array $credentials): array
    {
        $user = User::query()
            ->where('email', strtolower(trim($credentials['email'])))
            ->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password yang dimasukkan tidak sesuai.'],
            ]);
        }

        $token = $user->createToken('personal-app-token')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Cabut token akses saat ini.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * Perbarui profil pengguna.
     *
     * @param  array{name: string, email?: string|null, phone?: string|null, password?: string|null}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        return DB::connection('personal_app')->transaction(function () use ($user, $data) {
            $payload = [
                'name' => $data['name'],
            ];

            if (array_key_exists('email', $data)) {
                $payload['email'] = $data['email'] ? strtolower(trim($data['email'])) : null;
            }

            if (array_key_exists('avatar', $data)) {
                $payload['avatar'] = $data['avatar'] ?: null;
            }

            if (! empty($data['phone'])) {
                $payload['phone'] = $this->normalizePhone($data['phone']);
            }

            if (! empty($data['password'])) {
                $payload['password'] = $data['password'];
            }

            $user->update($payload);

            return $user->refresh();
        });
    }
}
