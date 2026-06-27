<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        $user = User::where('email', User::normalizeEmail($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль.'],
            ]);
        }

        $device = $data['device_name'] ?? 'mobile';
        $token = $user->createToken($device)->plainTextToken;

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
