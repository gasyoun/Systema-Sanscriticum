<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\TelegramFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VkBotController extends Controller
{
    public function handle(Request $request)
    {
        $data = $request->all();

        // ПРИНУДИТЕЛЬНЫЙ ЛОГ ВСЕХ ВХОДЯЩИХ ЗАПРОСОВ
        Log::info('VK WEBHOOK CATCHED:', $data);

        // 1. ПОДТВЕРЖДЕНИЕ СЕРВЕРА ДЛЯ ВК
        if (($data['type'] ?? '') === 'confirmation') {
            // Принудительно отдаем как простой текст без HTML и JSON
            return response((string) config('services.vk.confirm_code'), 200)
                ->header('Content-Type', 'text/plain');
        }

        // 2. ОБРАБОТКА НОВОГО СООБЩЕНИЯ
        if (($data['type'] ?? '') === 'message_new') {
            $messageObj = $data['object']['message'] ?? null;
            $vkId = is_array($messageObj) ? ($messageObj['from_id'] ?? null) : null;

            // Малформированный payload (битый callback / сканер / replay): отвечаем 200
            // чтобы VK не уходил в бесконечный retry, и логируем для диагностики.
            if (! is_int($vkId) && ! ctype_digit((string) $vkId)) {
                Log::warning('VK webhook: malformed message_new payload', ['data' => $data]);

                return response('ok', 200);
            }

            $vkId = (int) $vkId;
            $text = is_string($messageObj['text'] ?? null) ? $messageObj['text'] : '';
            $ref = $messageObj['ref'] ?? null; // ID студента из ссылки vk.me

            $user = User::where('vk_id', $vkId)->first();

            // Если перешел по кнопке с сайта
            if (! $user && $ref) {
                $user = User::find($ref);
                if ($user) {
                    $user->update(['vk_id' => $vkId]);
                    $this->sendVkMessage($vkId, '✅ Отлично! Вы успешно привязали свой аккаунт ВКонтакте. Теперь я смогу помогать вам здесь. Чем могу помочь?');

                    return response('ok', 200);
                }
            }

            if (! $user) {
                $this->sendVkMessage($vkId, 'Пожалуйста, перейдите в этот чат по кнопке из личного кабинета на сайте, чтобы я мог вас узнать.');

                return response('ok', 200);
            }

            $adminId = config('services.telegram.admin_id');

            // Сохраняем вопрос в базу
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'user',
                'text' => $text,
                'is_read' => false,
            ]);

            // ПРОВЕРКА: Если бот на паузе (отвечает человек)
            if (Cache::has("chat_human_vk_{$vkId}")) {
                if ($adminId) {
                    $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";
                    $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                    $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                    $alertMessage = "🔵 <b>Новое сообщение из ВК от {$safeName}:</b>\n\n<i>{$safeText}</i>\n\n👉 <a href='{$adminUrl}'>Ответить в Админке</a>";
                    $this->sendTelegramAlert($adminId, $alertMessage); // Шлем пуш админу в ТГ
                }

                return response('ok', 200);
            }

            // ПРОВЕРКА НА ВЫЗОВ КУРАТОРА
            $triggerWords = ['куратор', 'человек', 'помощь', 'админ', 'менеджер', 'оператор'];
            foreach ($triggerWords as $word) {
                if (mb_stripos($text, $word) !== false) {
                    Cache::put("chat_human_vk_{$vkId}", true, 7200);
                    $this->sendVkMessage($vkId, '🙏 Понял вас. Передал ваш вопрос живому куратору, ожидайте ответа!');

                    if ($adminId) {
                        $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";
                        $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                        $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                        $this->sendTelegramAlert($adminId, "🔔 <b>СТУДЕНТ ИЗ ВК ЗОВЕТ КУРАТОРА!</b>\nИмя: {$safeName}\nВопрос: {$safeText}\n\n👉 <a href='{$adminUrl}'>Открыть диалог в Админке</a>");
                    }

                    return response('ok', 200);
                }
            }

            // Если не позвал человека, бот начинает "думать"
            $this->sendVkMessage($vkId, '⏳ Изучаю манускрипты...');

            // Вопрос студента уже сохранён в ChatMessage выше — он попадёт в
            // историю последним. Думает единый сервис (DeepSeek через OpenRouter).
            $answer = app(CuratorAi::class)->reply($user, $text);

            if ($answer === null) {
                $this->sendVkMessage($vkId, "Мои чакры перегружены 🧘‍♂️. Пожалуйста, напишите 'позови куратора', и вам ответит человек.");

                return response('ok', 200);
            }

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $answer,
                'is_read' => true,
            ]);

            $this->sendVkMessage($vkId, $answer);
        }

        return response('ok', 200);
    }

    // ==========================================
    // ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
    // ==========================================

    // Отправка в ВК
    private function sendVkMessage($vkId, $text)
    {
        // ВК не понимает разметку: ИИ-куратор форматирует под Telegram, причём
        // нередко в Markdown. Приводим к плоскому тексту — уходят и «**»/«###»,
        // и голые <b>; остаются текст и эмодзи.
        $text = TelegramFormatter::toPlain((string) $text);

        // ДОБАВЛЕНО asForm() - чтобы ВК понял наш токен!
        $response = Http::asForm()->post('https://api.vk.com/method/messages.send', [
            'access_token' => config('services.vk.bot_token'),
            'v' => '5.131',
            'user_id' => $vkId,
            'message' => $text,
            'random_id' => rand(100000, 999999999),
        ]);

        // Если ВК вернул ошибку внутри JSON (например, токен недействителен)
        $json = $response->json();
        if (isset($json['error'])) {
            \Illuminate\Support\Facades\Log::error('ОШИБКА ОТПРАВКИ ВК: ', $json);
        }
    }

    // Уведомление Админа в Телеграм
    private function sendTelegramAlert($chatId, $text)
    {
        $token = config('services.telegram.bot_token');
        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if (! $response->successful()) {
            Log::error('Telegram alert error', ['status' => $response->status(), 'body' => $response->body()]);
        }
    }
}
