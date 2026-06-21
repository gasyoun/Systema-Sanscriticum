<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http; // Добавили для переключения на человека
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Получаем все данные, которые прислал Telegram
        $data = $request->all();

        // Проверяем, есть ли текст в сообщении
        if (isset($data['message']['text'])) {
            $chatId = $data['message']['chat']['id'];
            $text = $data['message']['text'];

            // Ловим команду /start с уникальным токеном (Deep Linking)
            if (str_starts_with($text, '/start ')) {
                $token = str_replace('/start ', '', $text);

                // Ищем студента с таким токеном
                $user = User::where('telegram_auth_token', $token)->first();

                if ($user) {
                    // Привязываем Telegram ID к студенту и удаляем временный токен
                    $user->update([
                        'telegram_id' => $chatId,
                        'telegram_auth_token' => null,
                    ]);

                    $this->sendMessage($chatId, "Намасте, {$user->name}! 🙏\n\nВаш аккаунт Академии успешно привязан. Теперь важные уведомления и доступы будут приходить прямо сюда. Также вы можете задавать мне вопросы по обучению!");
                } else {
                    $this->sendMessage($chatId, 'Ссылка устарела или недействительна. Пожалуйста, сгенерируйте новую кнопку в личном кабинете на сайте.');
                }
            }
            // Если просто написали /start без токена
            elseif ($text === '/start') {
                $this->sendMessage($chatId, "Намасте! 🙏\nЧтобы получать уведомления и задавать вопросы, вам нужно привязать свой аккаунт. Для этого зайдите в личный кабинет на сайте Академии и нажмите кнопку «Подключить Telegram».");
            }
            // ==========================================
            // НОВАЯ ЧАСТЬ: ОБРАБОТКА ОБЫЧНЫХ ВОПРОСОВ
            // ==========================================
            else {
                // Ищем, кто нам пишет
                $user = User::where('telegram_id', $chatId)->first();

                if ($user) {
                    // Студент авторизован! Передаем вопрос ИИ-агенту
                    $this->processStudentQuestion($user, $text, $chatId);
                } else {
                    // Пишет кто-то левый или неавторизованный
                    $this->sendMessage($chatId, 'Пожалуйста, сначала привяжите свой аккаунт на сайте Академии, чтобы я мог вам помогать.');
                }
            }
        }

        // Telegram ждет ответ 200 OK, иначе будет слать сообщение снова и снова
        return response()->json(['status' => 'ok']);
    }

    // ==========================================
    // ЛОГИКА ИИ: РАБОТАЕМ ЧЕРЕЗ БАЗУ ДАННЫХ
    // ==========================================
    private function processStudentQuestion($user, $question, $chatId)
    {
        $adminId = config('services.telegram.admin_id');

        // 1. СОХРАНЯЕМ ВОПРОС СТУДЕНТА В БАЗУ
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $question,
            'is_read' => false, // Куратор это еще не видел
        ]);

        // 2. ПРОВЕРЯЕМ РЕЖИМ ЧЕЛОВЕКА
        if (Cache::has("chat_human_{$chatId}")) {
            if ($adminId) {
                $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";

                $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                $safeQuestion = htmlspecialchars($question, ENT_QUOTES, 'UTF-8');
                $alertMessage = "🔴 <b>Новое сообщение от {$safeName}:</b>\n\n<i>{$safeQuestion}</i>\n\n";
                $alertMessage .= "👉 <a href='{$adminUrl}'>Ответить в Админке</a>";

                $this->sendMessage($adminId, $alertMessage);
            }

            return;
        }

        // 3. ТРИГГЕР "ПОЗОВИ ЧЕЛОВЕКА"
        $triggerWords = ['куратор', 'человек', 'помощь', 'админ', 'менеджер', 'оператор'];
        foreach ($triggerWords as $word) {
            if (mb_stripos($question, $word) !== false) {
                Cache::put("chat_human_{$chatId}", true, 7200);
                $this->sendMessage($chatId, '🙏 Понял вас. Передал ваш вопрос живому куратору, ожидайте ответа!');

                if ($adminId) {
                    $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";
                    $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                    $safeQuestion = htmlspecialchars($question, ENT_QUOTES, 'UTF-8');
                    $this->sendMessage($adminId, "🔔 <b>СТУДЕНТ ЗОВЕТ КУРАТОРА!</b>\nИмя: {$safeName}\nВопрос: {$safeQuestion}\n\n👉 <a href='{$adminUrl}'>Открыть диалог в Админке</a>");
                }

                return;
            }
        }

        $this->sendMessage($chatId, '⏳ <i>Изучаю манускрипты...</i>');

        // Вопрос студента уже сохранён в ChatMessage выше — он попадёт в историю
        // последним. Думает единый сервис (DeepSeek через OpenRouter).
        $answer = app(CuratorAi::class)->reply($user, $question);

        if ($answer === null) {
            $this->sendMessage($chatId, "Мои чакры перегружены 🧘‍♂️. Пожалуйста, напишите 'позови куратора', и вам ответит человек.");

            return;
        }

        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => $answer,
            'is_read' => true,
        ]);

        $this->sendMessage($chatId, $answer);
    }

    // ==========================================
    // Вспомогательная функция для отправки сообщений
    // ==========================================
    private function sendMessage($chatId, $text)
    {
        $token = config('services.telegram.bot_token');

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if (! $response->successful()) {
            Log::error('Telegram API error', ['status' => $response->status(), 'body' => $response->body()]);
        }
    }
}
