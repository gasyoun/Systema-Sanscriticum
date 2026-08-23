<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Авторизация мобильного приложения через личные токены Sanctum.
 * Веб-кабинет (сессии) не затрагивается — это отдельный токен-канал.
 */
class AuthController extends Controller
{
    /** Логин по email+паролю → персональный токен. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = User::normalizeEmail($data['email']);
        $ip = (string) $request->ip();

        // H3314 — per-credential lockout поверх IP-throttle роута:
        // распределённый брутфорс одной учётки упирается в счётчик по email.
        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            LoginThrottle::fireLockout($request);

            return response()->json(['message' => LoginThrottle::LOCKOUT_MESSAGE], 429);
        }

        $user = User::where('email', $email)->first();

        // Равное время ответа для «нет такого email» и «неверный пароль» —
        // без оракула существования адреса.
        if (! $user) {
            LoginThrottle::equalizeTiming($data['password']);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            LoginThrottle::hit($email, $ip);

            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль.'],
            ]);
        }

        LoginThrottle::clear($email, $ip);

        $device = $data['device_name'] ?? 'mobile';
        // H3314 — новый токен получает явный expires_at (окно из конфига Sanctum,
        // 90 дней по умолчанию). Старые токены досиживают своё created_at-окно.
        $expiresAt = now()->addMinutes(max(1, (int) config('sanctum.expiration', 60 * 24 * 90)));
        $token = $user->createToken($device, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /** Текущий пользователь по токену. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /** Разлогин: отзываем текущий токен. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Токен отозван.']);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'prana_balance' => (int) $user->prana_balance,
        ];
    }
}
