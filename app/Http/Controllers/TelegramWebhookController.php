<?php

namespace App\Http\Controllers;

use App\Jobs\SyncUserAvatarJob;
use App\Models\ChatMessage;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use App\Models\VacationQuorumPoll;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\AttendanceNoticeService;
use App\Services\Bot\CabinetLoginBotCommand;
use App\Services\Bot\CabinetProvisionBotCommand;
use App\Services\Bot\CuratorAi;
use App\Services\Bot\DebtorsBotCommand;
use App\Services\Bot\RosterBotCommand;
use App\Services\Bot\StudentSelfService;
use App\Services\Bot\TelegramFormatter;
use App\Services\Bot\UnblockBotCommand; // Добавили для переключения на человека
use App\Services\HomeworkTelegramTagService;
use App\Services\Support\HomeworkPauseNoteRecorder;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportHintSendButton;
use App\Services\VacationQuorumService;
use App\Support\TelegramSendGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    /** Прежняя подсказка непривязанному студенту (флаги «Telegram-входа» OFF). */
    private const CABINET_LINK_HINT = "Намасте! 🙏\nЧтобы получать уведомления и задавать вопросы, вам нужно привязать свой аккаунт. Для этого зайдите в личный кабинет на сайте Академии и нажмите кнопку «Подключить Telegram».";

    public function handle(Request $request)
    {
        // Получаем все данные, которые прислал Telegram
        $data = $request->all();

        // Дедуп update_id (TelegramSendGuard::claimUpdate): при медленном/упавшем
        // обработчике Telegram перешилёт апдейт — без клейма студент получил бы
        // второй ответ бота (включая повторный AI-вызов). Повтор молча
        // подтверждаем «ok»: обработка уже была (или не удалась — но повтором
        // это не лечится, см. контракт at-most-once в TelegramSendGuard).
        $updateId = isset($data['update_id']) && is_numeric($data['update_id'])
            ? (int) $data['update_id']
            : null;
        if ($updateId !== null && ! TelegramSendGuard::claimUpdate('main', $updateId)) {
            return response()->json(['status' => 'ok']);
        }

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

            // #ДЗ / /dz в чате группы (волна 2): до student-AI и «привяжите аккаунт».
            // Не-теговые сообщения группы тоже глушим здесь — иначе chat.id группы
            // уходит в ветку «неавторизованный» и спамит «привяжите аккаунт».
            $chatType = (string) ($data['message']['chat']['type'] ?? '');
            if (in_array($chatType, ['group', 'supergroup'], true)) {
                // H3912: куратор-команды (/долги, /группа, /кто) отвечают куратору
                // прямо в чате «Отдел заботы» — только этот чат, только привязанный
                // куратор; остальным и в обычных групповых чатах — тишина.
                if ($this->handleCareChatCuratorCommand($data['message'], $text)) {
                    return response()->json(['status' => 'ok']);
                }

                $tag = app(HomeworkTelegramTagService::class);
                if ($tag->isTagMessage($text)) {
                    $tag->handleIncoming($data['message']);
                } else {
                    // H2317: студент в чате группы пишет «не приду / опоздаю / …»
                    // — фиксируем предупреждение, не уводим в AI-ветку (group chat
                    // иначе глушится целиком).
                    $this->handleGroupAttendanceNotice($data['message']);
                }

                return response()->json(['status' => 'ok']);
            }

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
            // Если просто написали /start без токена — «Telegram-вход»
            // (CABINET_ADOPTION_ROADMAP P2) или прежняя подсказка про сайт.
            elseif ($text === '/start') {
                $this->handleCabinetLoginEntry($chatId, $fromUsername);
            }
            // «Telegram-вход»: /login // /вход — одноразовая ссылка входа в чат.
            // Ветка сама мертва при флаге OFF — сообщение уходит дальше по
            // обычным веткам (AI / «привяжите аккаунт»).
            elseif (config('features.telegram_cabinet_login')
                && app(CabinetLoginBotCommand::class)->isLoginCommand($text)) {
                $this->handleCabinetLoginEntry($chatId, $fromUsername);
            }
            // Самообслуживание «/кабинет <email>»: автосоздание кабинета одним
            // шагом (Free-tier, 02-09-2026). Ветка мертва при флаге OFF —
            // сообщение уходит дальше по обычным веткам.
            elseif (config('features.telegram_cabinet_provision')
                && app(CabinetProvisionBotCommand::class)->isCommand($text)) {
                $this->sendMessage(
                    $chatId,
                    app(CabinetProvisionBotCommand::class)->replyForCommand($chatId, $fromUsername, $text),
                );
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
                    // Пишет кто-то левый или неавторизованный. «Telegram-вход»,
                    // сценарий email-привязки (отдельный флаг, @DECIDE владельца):
                    // включён и похоже на email → матч по оплате; выключен →
                    // прежнее сообщение про сайт.
                    $cabinetLogin = app(CabinetLoginBotCommand::class);

                    if ($cabinetLogin->isEmailLinkEnabled() && $cabinetLogin->looksLikeEmail($text)) {
                        $this->sendMessage($chatId, $cabinetLogin->replyForEmail($chatId, $fromUsername, $text));
                    } else {
                        $this->sendMessage($chatId, 'Пожалуйста, сначала привяжите свой аккаунт на сайте Академии, чтобы я мог вам помогать.');
                    }
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

        // H2320: «пауза по ДЗ» → users.note (не статус HomeworkSubmission).
        app(HomeworkPauseNoteRecorder::class)->recordIfMatches($user, $question, 'telegram-bot');

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

        // 1.55. SELF-SERVICE: Zoom / запись / расписание из LMS (ORS-FAQ 04/05/06).
        $lmsFact = app(StudentSelfService::class)->lmsFactReply($user, $question);
        if ($lmsFact !== null) {
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $lmsFact,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);

            $this->sendMessage($chatId, $lmsFact);

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

        // 1.65. SELF-SERVICE: открытые эфиры ОРС — расписание + подписка/отписка
        // (H3576 §2). Подписка = активная группа потока → classes:remind-upcoming
        // напомнит за час; отдельного хранилища подписок нет. Отписка проверяется
        // первой — фразы содержат «эфир(ы)» внутри себя.
        $selfService = app(StudentSelfService::class);
        if ($selfService->matchesStreamsUnsubscribeIntent($question)) {
            $reply = $selfService->unsubscribeFromStreams($user);
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $reply,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);
            $this->sendMessage($chatId, $reply);

            return;
        }

        if ($selfService->matchesStreamsSubscribeIntent($question)) {
            $reply = $selfService->subscribeToStreams($user);
            ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => $reply,
                'is_read' => true,
                'source' => 'telegram_bot',
            ]);
            $this->sendMessage($chatId, $reply);

            return;
        }

        if ($selfService->matchesStreamsIntent($question)) {
            $summary = $selfService->streamsSummary($user);
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

        // 1.8. SELF-SERVICE: предупреждение о занятии (H2317) — не приду / опоздаю / …
        if (app(StudentSelfService::class)->matchesAttendanceNoticeIntent($question)) {
            $reply = app(StudentSelfService::class)->handleAttendanceNotice(
                $user,
                $question,
                ScheduleAttendanceNotice::SOURCE_TELEGRAM,
            );

            if ($reply['ok'] && $reply['text'] !== '') {
                ChatMessage::create([
                    'user_id' => $user->id,
                    'role' => 'bot',
                    'text' => $reply['text'],
                    'is_read' => true,
                    'source' => 'telegram_bot',
                ]);

                $this->sendMessage($chatId, $reply['text']);

                return;
            }
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

    /**
     * H2317: предупреждение о занятии из чата учебной группы.
     * Только привязанный студент + распознанная фраза; ответ в группу коротко.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleGroupAttendanceNotice(array $message): void
    {
        $text = isset($message['text']) ? (string) $message['text'] : '';
        if (! app(StudentSelfService::class)->matchesAttendanceNoticeIntent($text)) {
            return;
        }

        $fromId = $message['from']['id'] ?? null;
        if ($fromId === null) {
            return;
        }

        $user = User::query()->where('telegram_id', $fromId)->first();
        if (! $user) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $preferGroupId = null;
        if ($chatId !== null) {
            $group = app(AttendanceNoticeService::class)
                ->groupByTelegramChatId($chatId);
            $preferGroupId = $group?->id;
        }

        $reply = app(StudentSelfService::class)->handleAttendanceNotice(
            $user,
            $text,
            ScheduleAttendanceNotice::SOURCE_TELEGRAM_GROUP,
            $preferGroupId,
        );

        if ($reply['ok'] && $reply['text'] !== '' && $chatId !== null) {
            // Краткий ack в группу: «Имя: статус», без HTML-меню.
            $safeName = htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8');
            $plain = strip_tags(str_replace(['<b>', '</b>'], '', $reply['text']));
            $firstLine = strtok($plain, "\n") ?: $plain;
            $this->sendMessage($chatId, "{$safeName}: {$firstLine}");
        }
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

    /**
     * Куратор-команды в групповом чате (H3912): работают ТОЛЬКО в чате
     * «Отдел заботы» (recording_gap.care_telegram_chat_id — тот же чат,
     * куда копируются ops-алерты) и только от привязанного куратора
     * (admin/manager/super_admin). Автор ищется по message.from.id —
     * chat.id в группе принадлежит чату, а не отправителю. Возврат true
     * = команда обработана, апдейт дальше не идёт; иначе ветка молча
     * проваливается в обычную обработку групповых сообщений (теги #ДЗ,
     * оповещения о посещаемости).
     */
    private function handleCareChatCuratorCommand(array $message, string $text): bool
    {
        $careChatId = trim((string) config('recording_gap.care_telegram_chat_id', ''));
        if ($careChatId === '') {
            return false;
        }

        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null || (string) $chatId !== $careChatId) {
            return false;
        }

        if (! app(DebtorsBotCommand::class)->isCommand($text)
            && ! app(RosterBotCommand::class)->isCommand($text)) {
            return false;
        }

        $fromId = $message['from']['id'] ?? null;
        if ($fromId === null) {
            return false;
        }

        $curator = User::query()->where('telegram_id', $fromId)->first();
        if (! $curator || ! DebtorsBotCommand::isCurator($curator)) {
            return false;
        }

        $reply = app(RosterBotCommand::class)->isCommand($text)
            ? app(RosterBotCommand::class)->reply($curator, $text)
            : app(DebtorsBotCommand::class)->reply($curator, $text);

        $this->sendMessage($chatId, $reply);

        return true;
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
    // «TELEGRAM-ВХОД» В КАБИНЕТ (CABINET_ADOPTION_ROADMAP P2, 28-08-2026)
    // /start или /вход: привязан → одноразовая ссылка входа; не привязан →
    // (под-флаг) приглашение прислать email заказа; всё выключено → прежняя
    // подсказка про «Подключить Telegram» на сайте. Решения — в
    // CabinetLoginBotCommand, отправка — через единый sendMessage.
    // ==========================================
    private function handleCabinetLoginEntry($chatId, ?string $fromUsername): void
    {
        $cabinetLogin = app(CabinetLoginBotCommand::class);

        if (! $cabinetLogin->isEnabled()) {
            $this->sendMessage($chatId, self::CABINET_LINK_HINT);

            return;
        }

        $user = User::where('telegram_id', $chatId)->first();

        if ($user) {
            // Бэкфилл @username — тот же приём, что в основной AI-ветке.
            $user->rememberTelegramUsername($fromUsername);
            $this->sendMessage($chatId, $cabinetLogin->replyForLinkedUser($user));

            return;
        }

        if ($cabinetLogin->isEmailLinkEnabled()) {
            $this->sendMessage($chatId, $cabinetLogin->askForEmailMessage());

            return;
        }

        $this->sendMessage($chatId, self::CABINET_LINK_HINT);
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

        // Выбор урока для #ДЗ (волна 2, звено G).
        if (str_starts_with($data, HomeworkTelegramTagService::CALLBACK_PREFIX)) {
            app(HomeworkTelegramTagService::class)->handleCallback($callback);

            return;
        }

        // H3765 A5: «Отправить как есть» под подсказкой куратору — черновик
        // уходит студенту одним нажатием, минуя мёртвую админку.
        if (str_starts_with($data, SupportDmAutoReply::SEND_CALLBACK_PREFIX)) {
            app(SupportHintSendButton::class)->handle($callback);

            return;
        }

        // H3790 фаза C: одобрение распускания каникульной группы (кворум не соbrался).
        if (str_starts_with($data, VacationQuorumService::CALLBACK_APPROVE)
            || str_starts_with($data, VacationQuorumService::CALLBACK_DECLINE)) {
            $this->handleVacationQuorumCallback($callback, $notifier);

            return;
        }

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

    private function handleVacationQuorumCallback(array $callback, TelegramAdminNotifier $notifier): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $fromId = $callback['from']['id'] ?? null;

        $admin = $fromId !== null ? User::where('telegram_id', $fromId)->first() : null;
        if (! $admin || ! UnblockBotCommand::isAuthorized($admin)) {
            $notifier->answerCallback($callbackId, 'Недостаточно прав.');

            return;
        }

        $service = app(VacationQuorumService::class);
        $isApprove = str_starts_with($data, VacationQuorumService::CALLBACK_APPROVE);
        $pollId = (int) substr($data, strrpos($data, ':') + 1);
        $poll = VacationQuorumPoll::find($pollId);

        if (! $poll) {
            $notifier->answerCallback($callbackId, 'Опрос не найден.');

            return;
        }

        if ($isApprove) {
            $service->approveDissolution($poll, $admin);
            $notifier->answerCallback($callbackId, 'Группа распущена.');
        } else {
            $service->declineDissolution($poll, $admin);
            $notifier->answerCallback($callbackId, 'Оставили группу.');
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
