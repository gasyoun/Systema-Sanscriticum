<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\StudentSelfService;
use App\Services\Bot\TelegramFormatter;
use App\Services\Support\HomeworkPauseNoteRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обработка message_new легаси VK-бота (привязка аккаунта + ИИ-куратор).
 *
 * Вынесена из VkBotController в очередь: ответ ИИ (OpenRouter, до 45 сек)
 * нельзя ждать в HTTP-запросе вебхука — VK, не получив «ok» за несколько
 * секунд, ретраит событие, и студент получал дубли («Изучаю манускрипты…» ×2,
 * второй LLM-вызов падал по таймауту в «чакры перегружены»).
 */
final class ProcessVkBotMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $message  объект message из message_new
     */
    public function __construct(public readonly array $message)
    {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $vkId = (int) $this->message['from_id'];
        $text = is_string($this->message['text'] ?? null) ? $this->message['text'] : '';
        // Одноразовый токен привязки из ссылки vk.me (VkController::connect).
        // Раньше тут был сырой user id → IDOR-перехват чужого аккаунта.
        $ref = is_string($this->message['ref'] ?? null) ? $this->message['ref'] : null;

        $user = User::where('vk_id', $vkId)->first();

        // Если перешел по кнопке с сайта
        if (! $user && $ref) {
            // Ищем по неугадываемому токену, а не по id. Токен одноразовый —
            // гасим сразу после привязки, чтобы ссылку нельзя было переиспользовать.
            $candidate = User::where('vk_auth_token', $ref)->first();
            // Защита от перехвата аккаунта: привязываем VK ТОЛЬКО к ещё не
            // привязанному аккаунту (на случай гонки/повторной отправки токена).
            if ($candidate && ! $candidate->vk_id) {
                $candidate->update([
                    'vk_id' => $vkId,
                    'vk_connected_at' => now(),
                    'vk_auth_token' => null,
                ]);
                SyncUserAvatarJob::dispatch($candidate->id);
                $this->sendVkMessage($vkId, "✅ Отлично! Вы успешно привязали свой аккаунт ВКонтакте. Теперь я смогу помогать вам здесь.\n\nНапишите «мои группы», чтобы увидеть свои группы и расписание.");

                return;
            }
        }

        if (! $user) {
            $this->sendVkMessage($vkId, 'Пожалуйста, перейдите в этот чат по кнопке из личного кабинета на сайте, чтобы я мог вас узнать.');

            return;
        }

        $adminId = config('services.telegram.admin_id');

        // Сохраняем вопрос в базу. source='vk' (H1200) — отличает от веб-виджета
        // в едином инбоксе (UnifiedMessage::CHANNEL_VK).
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
            'source' => 'vk',
        ]);

        // H2320: «пауза по ДЗ» → примечание куратора.
        app(HomeworkPauseNoteRecorder::class)->recordIfMatches($user, $text, 'vk-bot');

        // SELF-SERVICE: «мои группы» — отвечаем из БД, минуя ИИ.
        if (app(StudentSelfService::class)->matchesGroupsIntent($text)) {
            $summary = app(StudentSelfService::class)->groupsSummary($user, 'vk');

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $summary,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $summary);

            return;
        }

        // SELF-SERVICE: Zoom / запись / расписание из LMS (ORS-FAQ 04/05/06).
        $lmsFact = app(StudentSelfService::class)->lmsFactReply($user, $text);
        if ($lmsFact !== null) {
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $lmsFact,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $lmsFact);

            return;
        }

        // SELF-SERVICE: «мои задания» — статус ДЗ из БД, минуя ИИ (H1357).
        if (app(StudentSelfService::class)->matchesHomeworkIntent($text)) {
            $summary = app(StudentSelfService::class)->homeworkSummary($user);

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $summary,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $summary);

            return;
        }

        // SELF-SERVICE: открытые эфиры ОРС — расписание + подписка/отписка
        // (H3576 §2, зеркало Telegram-ветки 1.65). Отписка — первой.
        $selfService = app(StudentSelfService::class);
        if ($selfService->matchesStreamsUnsubscribeIntent($text)) {
            $reply = $selfService->unsubscribeFromStreams($user);
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $reply,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $reply);

            return;
        }

        if ($selfService->matchesStreamsSubscribeIntent($text)) {
            $reply = $selfService->subscribeToStreams($user);
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $reply,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $reply);

            return;
        }

        if ($selfService->matchesStreamsIntent($text)) {
            $summary = $selfService->streamsSummary($user, 'vk');
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $summary,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $summary);

            return;
        }

        // SELF-SERVICE: /help — детерминированное меню, минуя ИИ (H1357).
        if (app(StudentSelfService::class)->matchesHelpIntent($text)) {
            $menu = app(StudentSelfService::class)->helpMenu();

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $menu,
                'is_read' => true,
                'source' => 'vk',
            ]);

            $this->sendVkMessage($vkId, $menu);

            return;
        }

        // SELF-SERVICE: предупреждение о занятии (H2317).
        if (app(StudentSelfService::class)->matchesAttendanceNoticeIntent($text)) {
            $reply = app(StudentSelfService::class)->handleAttendanceNotice(
                $user,
                $text,
                ScheduleAttendanceNotice::SOURCE_VK,
            );

            if ($reply['ok'] && $reply['text'] !== '') {
                ChatMessage::create([
                    'user_id' => $user->id,
                    'role' => 'bot',
                    'text' => $reply['text'],
                    'is_read' => true,
                    'source' => 'vk',
                ]);

                $this->sendVkMessage($vkId, $reply['text']);

                return;
            }
        }

        // ПРОВЕРКА: Если бот на паузе (отвечает человек)
        if (Cache::has("chat_human_vk_{$vkId}")) {
            if ($adminId) {
                $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";
                $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                $alertMessage = "🔵 <b>Новое сообщение из ВК от {$safeName}:</b>\n\n<i>{$safeText}</i>\n\n👉 <a href='{$adminUrl}'>Ответить в Админке</a>";
                $this->sendTelegramAlert($alertMessage); // Шлем пуш админу в ТГ
            }

            return;
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
                    $this->sendTelegramAlert("🔔 <b>СТУДЕНТ ИЗ ВК ЗОВЕТ КУРАТОРА!</b>\nИмя: {$safeName}\nВопрос: {$safeText}\n\n👉 <a href='{$adminUrl}'>Открыть диалог в Админке</a>");
                }

                return;
            }
        }

        // Если не позвал человека, бот начинает "думать"
        $this->sendVkMessage($vkId, '⏳ Изучаю манускрипты...');

        // Вопрос студента уже сохранён в ChatMessage выше — он попадёт в
        // историю последним. Думает единый сервис (DeepSeek через OpenRouter).
        $answer = app(CuratorAi::class)->reply($user, $text);

        if ($answer === null) {
            $this->sendVkMessage($vkId, "Мои чакры перегружены 🧘‍♂️. Пожалуйста, напишите 'позови куратора', и вам ответит человек.");

            return;
        }

        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'bot',
            'text' => $answer,
            'is_read' => true,
            'source' => 'vk',
        ]);

        $this->sendVkMessage($vkId, $answer);
    }

    // Отправка в ВК
    private function sendVkMessage(int $vkId, string $text): void
    {
        // ВК не понимает разметку: ИИ-куратор форматирует под Telegram, причём
        // нередко в Markdown. Приводим к плоскому тексту — уходят и «**»/«###»,
        // и голые <b>; остаются текст, ссылки и эмодзи.
        $text = TelegramFormatter::toPlain($text);

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
            Log::error('ОШИБКА ОТПРАВКИ ВК: ', $json);
        }
    }

    // Уведомление Админа в Телеграм. ADMIN_TELEGRAM_ID может содержать несколько
    // ID через запятую — шлём всем кураторам.
    private function sendTelegramAlert(string $text): void
    {
        $token = config('services.telegram.bot_token');

        foreach ($this->adminChatIds() as $chatId) {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful()) {
                Log::error('Telegram alert error', ['chat_id' => $chatId, 'status' => $response->status(), 'body' => $response->body()]);
            }
        }
    }

    /**
     * Список ID кураторов-админов из ADMIN_TELEGRAM_ID (несколько — через запятую).
     *
     * @return list<string>
     */
    private function adminChatIds(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.telegram.admin_id')),
        )));
    }
}
