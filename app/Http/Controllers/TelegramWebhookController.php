<?php

namespace App\Http\Controllers;

use App\Jobs\SyncUserAvatarJob;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\DebtorsBotCommand;
use App\Services\Bot\RosterBotCommand;
use App\Services\Bot\StudentSelfService;
use App\Services\Bot\TelegramFormatter;
use App\Services\Bot\UnblockBotCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache; // Добавили для переключения на человека
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Получаем все данные, которые прислал Telegram
        $data = $request->all();

        // Нажатие inline-кнопки (например «Разблокировать» из алерта H849).
        if (isset($data['callback_query'])) {
            $this->handleCallbackQuery($data['callback_query']);

            return response()->json(['status' => 'ok']);
        }

        // Проверяем, есть ли текст в сообщении
        if (isset($data['message']['text'])) {
            $chatId = $data['message']['chat']['id'];
            $text = $data['message']['text'];
            // @username отправителя (необязателен в Telegram; может отсутствовать).
            $fromUsername = $data['message']['from']['username'] ?? null;

            // Ловим команду /start с уникальным токеном (Deep Linking)
            if (str_starts_with($text, '/start ')) {
                $token = str_replace('/start ', '', $text);

                // Ищем студента с таким токеном
                $user = User::where('telegram_auth_token', $token)->first();

                if ($user) {
                    // Привязываем Telegram ID к студенту и удаляем временный токен
                    $user->update([
                        'telegram_id' => $chatId,
                        'telegram_username' => User::normalizeTelegramUsername($fromUsername),
                        'telegram_auth_token' => null,
                        'telegram_connected_at' => now(),
                    ]);

                    // Подтягиваем аватарку из Telegram (в очереди, не тормозим вебхук).
                    SyncUserAvatarJob::dispatch($user->id);

                    $this->sendMessage($chatId, "Намасте, {$user->name}! 🙏\n\nВаш аккаунт Академии успешно привязан. Теперь важные уведомления и доступы будут приходить прямо сюда. Также вы можете задавать мне вопросы по обучению!\n\nНапишите <b>«мои группы»</b>, чтобы увидеть свои группы и расписание.");
                } else {
                    $this->sendMessage($chatId, 'Ссылка устарела или недействительна. Пожалуйста, сгенерируйте новую кнопку в личном кабинете на сайте.');
                }
            }
            // Если просто написали /start без токена
            elseif ($text === '/start') {
                $this->sendMessage($chatId, "Намасте! 🙏\nЧтобы получать уведомления и задавать вопросы, вам нужно привязать свой аккаунт. Для этого зайдите в личный кабинет на сайте Академии и нажмите кнопку «Подключить Telegram».");
            }
            // Отписка от рекламной рассылки (152-ФЗ: право отзыва согласия обязательно,
            // т.к. существующие пользователи грандфазерятся в согласие). Гасит ТОЛЬКО
            // рекламные анонсы (wants_messenger_announcements) — транзакционные
            // уведомления по учёбе идут мимо этого флага и продолжают приходить.
            elseif ($this->isUnsubscribeCommand($text)) {
                $this->handleUnsubscribe($chatId);
            }
            // ==========================================
            // КУРАТОР-КОМАНДЫ (/долги — S4 H250; /группа, /кто — S6 H816) — отдельная
            // ветка от студенческого AI-чата: неавторизованным (не куратор/не привязан)
            // — тишина, а не обычное «привяжите аккаунт», чтобы не светить существование
            // команды посторонним.
            // ==========================================
            elseif (app(DebtorsBotCommand::class)->isCommand($text) || app(RosterBotCommand::class)->isCommand($text)) {
                $this->handleCuratorCommand($chatId, $text);
            }
            // Команда разблокировки /unblock <email> (H849) — только super_admin/admin.
            // Неавторизованным тишина: не светим существование команды.
            elseif (app(UnblockBotCommand::class)->isCommand($text)) {
                $this->handleUnblockCommand($chatId, $text);
            }
            // ==========================================
            // НОВАЯ ЧАСТЬ: ОБРАБОТКА ОБЫЧНЫХ ВОПРОСОВ
            // ==========================================
            else {
                // Ищем, кто нам пишет
                $user = User::where('telegram_id', $chatId)->first();

                if ($user) {
                    // Бэкфилл @username для уже привязанных: ловим при любом
                    // сообщении и обновляем, если он изменился (идемпотентно).
                    $user->rememberTelegramUsername($fromUsername);

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

        // 1. СОХРАНЯЕМ ВОПРОС СТУДЕНТА В БАЗУ. source='telegram_bot' (H1200) —
        // отличает от веб-виджета в едином инбоксе (UnifiedMessage::CHANNEL_TELEGRAM_BOT).
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $question,
            'is_read' => false, // Куратор это еще не видел
            'source' => 'telegram_bot',
        ]);

        // 1.5. SELF-SERVICE: «мои группы» — отвечаем из БД, минуя ИИ (личные данные
        // ИИ выдумывать не вправе). Мгновенный ответ даже в режиме человека.
        if (app(StudentSelfService::class)->matchesGroupsIntent($question)) {
            $summary = app(StudentSelfService::class)->groupsSummary($user);

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $summary,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);

            $this->sendMessage($chatId, $summary);

            return;
        }

        // 1.6. SELF-SERVICE: «мои задания» — статус ДЗ из БД, минуя ИИ (H1357).
        if (app(StudentSelfService::class)->matchesHomeworkIntent($question)) {
            $summary = app(StudentSelfService::class)->homeworkSummary($user);

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $summary,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);

            $this->sendMessage($chatId, $summary);

            return;
        }

        // 1.7. SELF-SERVICE: /help — детерминированное меню, минуя ИИ (H1357).
        if (app(StudentSelfService::class)->matchesHelpIntent($question)) {
            $menu = app(StudentSelfService::class)->helpMenu();

            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $menu,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);

            $this->sendMessage($chatId, $menu);

            return;
        }

        // 2. ПРОВЕРЯЕМ РЕЖИМ ЧЕЛОВЕКА
        if (Cache::has("chat_human_{$chatId}")) {
            if ($adminId) {
                $adminUrl = config('app.url')."/admin/dialogs?user_id={$user->id}";

                $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
                $safeQuestion = htmlspecialchars($question, ENT_QUOTES, 'UTF-8');
                $alertMessage = "🔴 <b>Новое сообщение от {$safeName}:</b>\n\n<i>{$safeQuestion}</i>\n\n";
                $alertMessage .= "👉 <a href='{$adminUrl}'>Ответить в Админке</a>";

                $this->sendAdminAlert($alertMessage);
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
                    $this->sendAdminAlert("🔔 <b>СТУДЕНТ ЗОВЕТ КУРАТОРА!</b>\nИмя: {$safeName}\nВопрос: {$safeQuestion}\n\n👉 <a href='{$adminUrl}'>Открыть диалог в Админке</a>");
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
            'source' => 'telegram_bot',
        ]);

        $this->sendMessage($chatId, $answer);
    }

    // ==========================================
    // Куратор-команды поверх DebtorsReport — см. DebtorsBotCommand (H250).
    // Неавторизованным (не найден по telegram_id, либо найден, но не
    // admin/manager/super_admin) — тишина: не подтверждаем существование
    // команды посторонним и студентам.
    // ==========================================
    private function handleCuratorCommand($chatId, $text)
    {
        $curator = User::where('telegram_id', $chatId)->first();

        if (! $curator || ! DebtorsBotCommand::isCurator($curator)) {
            return;
        }

        // /группа и /кто (S6) — ростер/поиск; /долги (S4) — сводка должников.
        $reply = app(RosterBotCommand::class)->isCommand($text)
            ? app(RosterBotCommand::class)->reply($curator, $text)
            : app(DebtorsBotCommand::class)->reply($curator, $text);

        $this->sendMessage($chatId, $reply);
    }

    // ==========================================
    // Отписка от рекламной рассылки (152-ФЗ). Триггеры — только явные формы, чтобы
    // не спутать с обычным вопросом студенту ИИ-агенту (голое «стоп» намеренно НЕ ловим).
    // ==========================================
    private function isUnsubscribeCommand(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return in_array($normalized, [
            '/stop',
            '/unsubscribe',
            'отписаться',
            'отписаться от рассылки',
            'отписка',
            'стоп рассылка',
            'стоп рассылку',
        ], true);
    }

    private function handleUnsubscribe($chatId): void
    {
        $user = User::where('telegram_id', $chatId)->first();

        // Не привязан — не подтверждаем существование команды сверх вежливого ответа.
        if (! $user) {
            $this->sendMessage($chatId, 'Этот чат не привязан к аккаунту Академии, рекламные рассылки сюда и так не приходят.');

            return;
        }

        if (! $user->wants_messenger_announcements) {
            $this->sendMessage($chatId, 'Вы уже отписаны от рекламных рассылок. Важные уведомления по учёбе продолжают приходить.');

            return;
        }

        $user->forceFill(['wants_messenger_announcements' => false])->save();

        $this->sendMessage($chatId, '🔕 Готово — вы отписаны от рекламных рассылок. Важные уведомления по учёбе (расписание, доступы, ответы куратора) продолжат приходить.');
    }

    // ==========================================
    // Разблокировка студента из Telegram (H849): текстовая команда /unblock <email>.
    // Авторизация строже куратор-команд — только super_admin/admin (выдача ссылки =
    // потенциальный вход в чужой кабинет). Неавторизованным — тишина.
    // ==========================================
    private function handleUnblockCommand($chatId, $text)
    {
        $admin = User::where('telegram_id', $chatId)->first();

        if (! UnblockBotCommand::isAuthorized($admin)) {
            return;
        }

        $reply = app(UnblockBotCommand::class)->replyForCommand($admin, $text);
        $this->sendMessage($chatId, $reply);
    }

    // ==========================================
    // Нажатие inline-кнопки. Пока единственная — «Разблокировать» (callback_data
    // «ub:<id записи>») из проактивного алерта H849. Гасим «часики» на кнопке,
    // авторизуем нажавшего по telegram_id → роль, отвечаем ссылкой в тот же чат.
    // ==========================================
    private function handleCallbackQuery(array $callback)
    {
        $notifier = app(TelegramAdminNotifier::class);

        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? $fromId;

        if (! str_starts_with($data, 'ub:') || $fromId === null) {
            $notifier->answerCallback($callbackId);

            return;
        }

        $admin = User::where('telegram_id', $fromId)->first();
        if (! UnblockBotCommand::isAuthorized($admin)) {
            $notifier->answerCallback($callbackId, 'Недостаточно прав.');

            return;
        }

        $attemptId = (int) substr($data, 3);
        $reply = app(UnblockBotCommand::class)->replyForAttempt($admin, $attemptId);

        $notifier->answerCallback($callbackId, 'Готово');

        $token = (string) config('services.telegram.bot_token');
        if ($token !== '' && $chatId !== null) {
            $notifier->send($token, (string) $chatId, $reply);
        }
    }

    // ==========================================
    // Сообщения студенту — ботом кабинета (фолбэк на основной бот)
    // ==========================================
    private function sendMessage($chatId, $text)
    {
        $token = config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token');

        // Нормализуем разметку: модель часто шлёт Markdown (###, **) вместо
        // Telegram-HTML — конвертер приводит всё к валидным тегам.
        $text = TelegramFormatter::toHtml((string) $text);

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if (! $response->successful()) {
            Log::error('Telegram API error', ['status' => $response->status(), 'body' => $response->body()]);

            // Чаще всего это ошибка парсинга HTML (модель прислала кривой тег или
            // одиночный «<»). Не теряем сообщение — досылаем как обычный текст.
            $fallback = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]);

            if (! $fallback->successful()) {
                Log::error('Telegram API error (plain fallback)', ['status' => $fallback->status(), 'body' => $fallback->body()]);
            }
        }
    }

    // ==========================================
    // Алерт куратору-админу — ВСЕГДА основным ботом: админ сидит в служебных
    // чатах основного бота и может не запускать бота кабинета.
    // ADMIN_TELEGRAM_ID может содержать несколько ID через запятую — шлём всем.
    // ==========================================
    private function sendAdminAlert($text)
    {
        $token = config('services.telegram.bot_token');

        foreach ($this->adminChatIds() as $chatId) {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful()) {
                Log::error('Telegram admin alert error', ['chat_id' => $chatId, 'status' => $response->status(), 'body' => $response->body()]);
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
