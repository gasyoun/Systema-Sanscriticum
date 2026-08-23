<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;

class TelegramController extends Controller
{
    /**
     * GET /telegram/connect — инструкция без мутаций.
     *
     * H3313: раньше каждый заход по GET крутил telegram_auth_token — любой
     * сторонней странице достаточно было навигации, чтобы браузер студента
     * перегенерил токен (CSRF-by-navigation; SameSite=lax пропускает топ-левел
     * переходы). Выдача токена переехала в CSRF-защищённый POST ниже.
     */
    public function connect()
    {
        return view('student.messenger-connect', [
            'channel' => 'telegram',
            'connected' => filled(auth()->user()?->telegram_id),
        ]);
    }

    /**
     * POST /telegram/connect — CSRF-защищённая выдача токена и переход в бота.
     */
    public function start()
    {
        $user = auth()->user();

        // 1. Генерируем сложный уникальный токен
        $token = Str::random(32);

        // 2. Записываем его студенту в базу данных
        $user->update([
            'telegram_auth_token' => $token,
        ]);

        // 3. Имя бота кабинета (с фолбэком на основной, если отдельный не задан)
        $botUsername = config('services.telegram.student_bot_username')
            ?: config('services.telegram.bot_username');

        // 4. Формируем ту самую магическую Deep Link ссылку
        $url = "https://t.me/{$botUsername}?start={$token}";

        // 5. Перебрасываем студента прямо в приложение Telegram
        return redirect()->away($url);
    }
}
