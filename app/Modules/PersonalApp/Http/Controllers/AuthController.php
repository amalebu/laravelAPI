<?php

namespace App\Modules\PersonalApp\Http\Controllers;

use App\Core\Http\Responses\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\PersonalApp\Http\Requests\Auth\CheckPhoneRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\GoogleLoginRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\LinkGoogleRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\LinkPhoneRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\LoginRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\PhonePinAuthRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\RegisterRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\SetupPinRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\UpdateProfileRequest;
use App\Modules\PersonalApp\Http\Requests\Auth\VerifyPinRequest;
use App\Modules\PersonalApp\Http\Resources\UserResource;
use App\Modules\PersonalApp\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function checkPhone(CheckPhoneRequest $request): JsonResponse
    {
        $result = $this->authService->checkPhoneStatus($request->validated('phone'));

        return ApiResponse::success(
            data: $result,
            message: 'Status nomor ponsel berhasil diperiksa.'
        );
    }

    public function authenticateWithPhonePin(PhonePinAuthRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->authService->authenticateWithPhonePin(
            $validated['phone'],
            $validated['pin'],
            $validated['pin_confirmation'] ?? null
        );

        return ApiResponse::success(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'is_new_user' => $result['is_new_user'],
            ],
            message: $result['is_new_user']
                ? 'PIN berhasil dibuat. Selamat datang!'
                : 'Berhasil masuk ke akun Anda.'
        );
    }

    public function setupPin(SetupPinRequest $request): JsonResponse
    {
        $user = $this->authService->setupPin(
            $request->user(),
            $request->validated('pin')
        );

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'PIN keamanan 6 digit berhasil disimpan.'
        );
    }

    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $this->authService->verifyPin(
            $request->user(),
            $request->validated('pin')
        );

        return ApiResponse::success(
            data: ['unlocked' => true],
            message: 'PIN terverifikasi. Layar berhasil dibuka.'
        );
    }

    public function loginWithGoogle(GoogleLoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginWithGoogle($request->validated());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'requires_pin_setup' => $result['requires_pin_setup'],
            ],
            message: 'Berhasil masuk dengan akun Google.'
        );
    }

    public function redirectToGoogle(): JsonResponse
    {
        $url = $this->authService->getGoogleRedirectUrl();

        return ApiResponse::success(
            data: ['url' => $url],
            message: 'URL otorisasi Google berhasil dibuat.'
        );
    }

    public function handleGoogleCallback(Request $request): RedirectResponse|JsonResponse
    {
        $frontendUrl = rtrim((string) config('services.google.frontend_url', 'http://localhost:5173'), '/');

        if ($request->has('error') || ! $request->has('code')) {
            return redirect()->away($frontendUrl . '/login?google_error=' . urlencode((string) $request->query('error', 'cancelled')));
        }

        try {
            $result = $this->authService->loginWithGoogle([
                'code' => (string) $request->query('code'),
            ]);

            return redirect()->away($frontendUrl . '/login?google_token=' . urlencode($result['token']));
        } catch (\Throwable $e) {
            return redirect()->away($frontendUrl . '/login?google_error=' . urlencode($e->getMessage()));
        }
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return ApiResponse::created(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ],
            message: 'Registrasi akun berhasil.'
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ],
            message: 'Berhasil masuk ke akun Anda.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(
            data: null,
            message: 'Berhasil keluar dari sesi.'
        );
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            data: new UserResource($request->user()),
            message: 'Profil pengguna berhasil diambil.'
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $updatedUser = $this->authService->updateProfile(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            data: new UserResource($updatedUser),
            message: 'Profil pengguna berhasil diperbarui.'
        );
    }

    public function linkGoogle(LinkGoogleRequest $request): JsonResponse
    {
        $updatedUser = $this->authService->linkGoogleAccount(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            data: new UserResource($updatedUser),
            message: 'Akun Google berhasil ditautkan ke profil Anda.'
        );
    }

    public function linkPhone(LinkPhoneRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $updatedUser = $this->authService->linkPhoneAccount(
            $request->user(),
            $validated['phone'],
            $validated['pin'],
            $validated['pin_confirmation'] ?? $validated['pin']
        );

        return ApiResponse::success(
            data: new UserResource($updatedUser),
            message: 'Nomor ponsel dan PIN berhasil ditautkan ke akun Anda.'
        );
    }
}
