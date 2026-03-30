<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; // Добавили для переключения на человека
use App\Models\ChatMessage;

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
                        'telegram_auth_token' => null 
                    ]);

                    $this->sendMessage($chatId, "Намасте, {$user->name}! 🙏\n\nВаш аккаунт Академии успешно привязан. Теперь важные уведомления и доступы будут приходить прямо сюда. Также вы можете задавать мне вопросы по обучению!");
                } else {
                    $this->sendMessage($chatId, "Ссылка устарела или недействительна. Пожалуйста, сгенерируйте новую кнопку в личном кабинете на сайте.");
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
                    $this->sendMessage($chatId, "Пожалуйста, сначала привяжите свой аккаунт на сайте Академии, чтобы я мог вам помогать.");
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
        $adminId = env('ADMIN_TELEGRAM_ID');

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
                // Генерируем ссылку в админку (замени URL на свой реальный домен)
                $adminUrl = env('APP_URL') . "/admin/dialogs?user_id={$user->id}";
                
                $alertMessage = "🔴 <b>Новое сообщение от {$user->name}:</b>\n\n<i>{$question}</i>\n\n";
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
                $this->sendMessage($chatId, "🙏 Понял вас. Передал ваш вопрос живому куратору, ожидайте ответа!");
                
                if ($adminId) {
                    $adminUrl = env('APP_URL') . "/admin/dialogs?user_id={$user->id}";
                    $this->sendMessage($adminId, "🔔 <b>СТУДЕНТ ЗОВЕТ КУРАТОРА!</b>\nИмя: {$user->name}\nВопрос: {$question}\n\n👉 <a href='{$adminUrl}'>Открыть диалог в Админке</a>");
                }
                return;
            }
        }

        $this->sendMessage($chatId, "⏳ <i>Изучаю манускрипты...</i>");

        try {
            $folderId = env('YANDEX_FOLDER_ID');
            $apiKey = env('YANDEX_API_KEY');
            $agentId = env('YANDEX_AGENT_ID'); // Твой ID агента (fvt...)

            // ==========================================
            // МАГИЯ ПАМЯТИ: Формируем единый текст диалога
            // ==========================================
            // Берем последние 10 сообщений (этого хватит для контекста)
            $dbHistory = ChatMessage::where('user_id', $user->id)
                                    ->orderBy('id', 'desc')
                                    ->take(10)
                                    ->get()
                                    ->reverse();

            // Склеиваем историю в один текст
            $dialogueText = "История диалога:\n";
            foreach ($dbHistory as $msg) {
                // Поскольку вопрос пользователя мы УЖЕ сохранили в базу на Шаге 1, 
                // он тоже попадет сюда в самый конец текста.
                $roleName = ($msg->role === 'user') ? 'Студент' : 'ИИ-Куратор';
                $dialogueText .= "{$roleName}: {$msg->text}\n";
            }

            // НОВЫЙ RESPONSES API ЯНДЕКСА
            $response = Http::withHeaders([
                'Authorization' => 'Api-Key ' . $apiKey, // Вернули Api-Key!
                'OpenAI-Project' => $folderId,          
                'Content-Type' => 'application/json',
            ])->timeout(45)->post("https://ai.api.cloud.yandex.net/v1/responses", [
                'prompt' => [
                    'id' => $agentId // Передаем голый ID, как в curl
                ],
                'input' => $dialogueText // Отправляем весь диалог целиком
            ]);

            if ($response->successful()) {
                // === УМНЫЙ ПОИСК ОТВЕТА В МАССИВЕ YANDEX ===
                $outputs = $response->json('output') ?? [];
                $aiAnswer = null;

                // Перебираем все шаги ответа с конца (финальный текст всегда в конце)
                foreach (array_reverse($outputs) as $step) {
                    if (isset($step['type']) && $step['type'] === 'message' && isset($step['content'][0]['text'])) {
                        $aiAnswer = $step['content'][0]['text'];
                        break; // Нашли текст - выходим из цикла!
                    }
                }

                $aiAnswer = $aiAnswer ?? "Ответ не найден.";
                // ==========================================
                
                // СОХРАНЯЕМ ОТВЕТ БОТА В БАЗУ
                ChatMessage::create([
                    'user_id' => $user->id,
                    'role' => 'bot',
                    'text' => $aiAnswer,
                    'is_read' => true, 
                ]);

                $this->sendMessage($chatId, $aiAnswer);
            } else {
                Log::error('Ошибка Yandex Responses API: ' . $response->body());
                $this->sendMessage($chatId, "Мои чакры перегружены 🧘‍♂️. Пожалуйста, напишите 'позови куратора', и вам ответит человек.");
            }
        } catch (\Exception $e) {
            Log::error('Сбой связи с Yandex: ' . $e->getMessage());
            $this->sendMessage($chatId, "Связь со вселенной прервалась. Позовите куратора.");
        }
    }

    // ==========================================
    // Вспомогательная функция для отправки сообщений
    // ==========================================
    private function sendMessage($chatId, $text)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }
}