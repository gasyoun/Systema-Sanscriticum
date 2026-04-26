<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function connect()
    {
        $user = auth()->user();

        // 1. Генерируем сложный уникальный токен
        $token = Str::random(32);

        // 2. Записываем его студенту в базу данных
        $user->update([
            'telegram_auth_token' => $token
        ]);

        // 3. Достаем имя бота из .env
        $botUsername = config('services.telegram.bot_username');

        // 4. Формируем ту самую магическую Deep Link ссылку
        $url = "https://t.me/{$botUsername}?start={$token}";

        // 5. Перебрасываем студента прямо в приложение Telegram
        return redirect()->away($url);
    }
}