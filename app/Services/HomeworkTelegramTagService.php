<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonTelegramHook;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * #ДЗ из Telegram-чата группы → homework_prompt урока (волна 2).
 *
 * Резолвер урока (зафиксировано): C → B → A → G
 *  C — reply на пост бота «ДЗ открыто» (lesson_telegram_hooks)
 *  B — единственное открытое ДЗ группы с пустым/placeholder prompt
 *  A — урок с самой свежей записью в lookback
 *  G — иначе inline-кнопки выбора (без тихого угадывания)
 */
class HomeworkTelegramTagService
{
    public const CALLBACK_PREFIX = 'hwdz:';

    public function enabled(): bool
    {
        return (bool) config('homework.tg_tag.enabled', true);
    }

    /**
     * Сообщение из группы/супергруппы с тегом #ДЗ / /dz?
     */
    public function isTagMessage(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return $this->extractPrompt($text) !== null;
    }

    public function isGroupChat(array $chat): bool
    {
        $type = (string) ($chat['type'] ?? '');

        return in_array($type, ['group', 'supergroup'], true);
    }

    public function isStaff(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isAdminLike() || $user->isTeacher() || $user->isManager();
    }

    /**
     * Обработать входящее сообщение с #ДЗ. Возвращает true, если сообщение
     * «съедено» (даже при отказе по правам — в группу не уводим в AI-чат).
     *
     * @param  array<string, mixed>  $message  Telegram message object
     */
    public function handleIncoming(array $message): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $chat = $message['chat'] ?? null;
        if (! is_array($chat) || ! $this->isGroupChat($chat)) {
            return false;
        }

        $text = isset($message['text']) ? (string) $message['text'] : '';
        if (! $this->isTagMessage($text)) {
            return false;
        }

        $chatId = (string) ($chat['id'] ?? '');
        $fromId = $message['from']['id'] ?? null;
        if ($chatId === '' || $fromId === null) {
            return true;
        }

        $user = User::query()->where('telegram_id', $fromId)->first();
        if (! $this->isStaff($user)) {
            // Тишина: не светим фичу студентам и не засоряем чат.
            return true;
        }

        $prompt = $this->extractPrompt($text);
        if ($prompt === null || $prompt === '') {
            $this->replyHtml($chatId, 'После <b>#ДЗ</b> нужен текст задания.');

            return true;
        }

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            $this->replyHtml(
                $chatId,
                'Этот чат не привязан к группе на платформе. Заполните <code>telegram_chat_id</code> у группы в админке.',
            );

            return true;
        }

        $replyToId = isset($message['reply_to_message']['message_id'])
            ? (int) $message['reply_to_message']['message_id']
            : null;

        $resolved = $this->resolveLesson($group, $chatId, $replyToId);

        if ($resolved['status'] === 'one') {
            /** @var Lesson $lesson */
            $lesson = $resolved['lesson'];
            $this->applyPrompt($lesson, $prompt);
            $this->replyHtml($chatId, $this->successText($lesson));

            return true;
        }

        if ($resolved['status'] === 'many') {
            /** @var Collection<int, Lesson> $lessons */
            $lessons = $resolved['lessons'];
            $this->storePending($fromId, $chatId, $prompt, $lessons->pluck('id')->all());
            $this->replyWithChoices($chatId, $lessons);

            return true;
        }

        $this->replyHtml(
            $chatId,
            'Не нашла урок для этого ДЗ. Загрузите запись занятия на платформе или ответьте reply на сообщение бота «ДЗ открыто».',
        );

        return true;
    }

    /**
     * Inline-кнопка выбора урока (звено G).
     *
     * @param  array<string, mixed>  $callback
     */
    public function handleCallback(array $callback): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return false;
        }

        $lessonId = (int) substr($data, strlen(self::CALLBACK_PREFIX));
        $fromId = $callback['from']['id'] ?? null;
        $chatId = isset($callback['message']['chat']['id'])
            ? (string) $callback['message']['chat']['id']
            : null;
        $callbackId = (string) ($callback['id'] ?? '');

        if ($lessonId < 1 || $fromId === null || $chatId === null) {
            $this->answerCallback($callbackId);

            return true;
        }

        $user = User::query()->where('telegram_id', $fromId)->first();
        if (! $this->isStaff($user)) {
            $this->answerCallback($callbackId, 'Недостаточно прав.');

            return true;
        }

        $pending = $this->getPending($fromId, $chatId);
        if ($pending === null || ! in_array($lessonId, $pending['lesson_ids'], true)) {
            $this->answerCallback($callbackId, 'Запрос устарел — отправьте #ДЗ ещё раз.');

            return true;
        }

        $lesson = Lesson::query()->with('course')->find($lessonId);
        if ($lesson === null) {
            $this->answerCallback($callbackId, 'Урок не найден.');

            return true;
        }

        $this->applyPrompt($lesson, $pending['prompt']);
        $this->forgetPending($fromId, $chatId);
        $this->answerCallback($callbackId, 'Готово');
        $this->replyHtml($chatId, $this->successText($lesson));

        return true;
    }

    /**
     * Пост в чат(ы) группы после generic-автооткрытия — якорь для reply (C).
     */
    public function postOpenInvite(Lesson $lesson): void
    {
        if (! $this->enabled() || ! config('homework.tg_tag.post_open_invite', true)) {
            return;
        }

        $lesson->loadMissing('course', 'group');

        $groups = $this->groupsForLesson($lesson);
        if ($groups->isEmpty()) {
            return;
        }

        $title = $lesson->title ? e($lesson->title) : 'урок';
        $date = $lesson->lesson_date?->format('d.m.Y');
        $dateBit = $date ? " ({$date})" : '';
        $url = $lesson->course
            ? route('student.lesson', [$lesson->course->slug, $lesson->id])
            : '';

        $text = "📝 Приём ДЗ открыт: <b>{$title}</b>{$dateBit}\n"
            .'Чтобы задать условие на платформе, <b>ответьте</b> на это сообщение с <code>#ДЗ</code> и текстом задания.'
            .($url !== '' ? "\n{$url}" : '');

        foreach ($groups as $group) {
            $chatId = (string) ($group->telegram_chat_id ?? '');
            if ($chatId === '') {
                continue;
            }

            $messageId = $this->sendHtml($chatId, $text);
            if ($messageId === null) {
                continue;
            }

            LessonTelegramHook::query()->updateOrCreate(
                [
                    'telegram_chat_id' => $chatId,
                    'telegram_message_id' => $messageId,
                ],
                [
                    'lesson_id' => $lesson->id,
                    'group_id' => $group->id,
                    'purpose' => LessonTelegramHook::PURPOSE_OPEN_INVITE,
                ],
            );
        }
    }

    /**
     * Вытащить текст задания после тега. null = тега нет.
     * Пустая строка = тег есть, текста нет (ошибка «нужен текст»).
     */
    public function extractPrompt(string $text): ?string
    {
        $trimmed = trim($text);

        // /dz@botname rest  |  /dz rest
        if (preg_match('/^\/dz(?:@\S+)?(?:\s+(.*))?$/us', $trimmed, $m)) {
            return trim((string) ($m[1] ?? ''));
        }

        // #ДЗ / #дз / #DZ / #dz — берём всё после первого вхождения тега
        if (preg_match('/#(?:ДЗ|дз|DZ|dz)\b(.*)$/us', $trimmed, $m)) {
            return trim((string) $m[1]);
        }

        return null;
    }

    /**
     * @return array{status: 'one', lesson: Lesson}|array{status: 'many', lessons: Collection<int, Lesson>}|array{status: 'none'}
     */
    public function resolveLesson(Group $group, string $chatId, ?int $replyToMessageId): array
    {
        // C — reply to open-invite
        if ($replyToMessageId !== null && $replyToMessageId > 0) {
            $hook = LessonTelegramHook::query()
                ->where('telegram_chat_id', $chatId)
                ->where('telegram_message_id', $replyToMessageId)
                ->with(['lesson.course'])
                ->first();

            if ($hook?->lesson) {
                return ['status' => 'one', 'lesson' => $hook->lesson];
            }
        }

        // B — single open placeholder / empty prompt
        $openPlaceholders = $this->lessonsForGroup($group)
            ->filter(fn (Lesson $l) => $this->isOpenPlaceholder($l))
            ->values();

        if ($openPlaceholders->count() === 1) {
            return ['status' => 'one', 'lesson' => $openPlaceholders->first()];
        }

        if ($openPlaceholders->count() > 1) {
            return ['status' => 'many', 'lessons' => $openPlaceholders];
        }

        // A — newest recording within lookback
        $lookbackHours = max(1, (int) config('homework.tg_tag.lookback_hours', 72));
        $since = now()->subHours($lookbackHours);

        $recent = $this->lessonsForGroup($group)
            ->filter(fn (Lesson $l) => $l->recording_attached_at !== null
                && $l->recording_attached_at->greaterThanOrEqualTo($since))
            ->sortByDesc(fn (Lesson $l) => $l->recording_attached_at?->getTimestamp() ?? 0)
            ->values();

        if ($recent->count() === 1) {
            return ['status' => 'one', 'lesson' => $recent->first()];
        }

        if ($recent->count() > 1) {
            // Prefer the single newest if clearly newer? Plan says G on >1 — no silent pick.
            return ['status' => 'many', 'lessons' => $recent->take(5)->values()];
        }

        return ['status' => 'none'];
    }

    public function applyPrompt(Lesson $lesson, string $prompt): void
    {
        DB::transaction(function () use ($lesson, $prompt): void {
            // Текст от преподавателя; homework_auto_opened_at не трогаем
            // (если автомат открыл — маркер остаётся; если нет — null).
            $lesson->homework_prompt = $prompt;
            $lesson->homework_enabled = true;
            $lesson->save();
        });
    }

    /**
     * Уроки, видимые этой группе: group_id совпал ИЛИ общий урок курса группы.
     *
     * @return Collection<int, Lesson>
     */
    public function lessonsForGroup(Group $group): Collection
    {
        $courseIds = $group->courses()->pluck('courses.id');

        return Lesson::query()
            ->with('course')
            ->where(function ($q) use ($group, $courseIds) {
                $q->where('group_id', $group->id);

                if ($courseIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($courseIds) {
                        $q2->whereNull('group_id')
                            ->whereIn('course_id', $courseIds);
                    });
                }
            })
            ->get();
    }

    private function isOpenPlaceholder(Lesson $lesson): bool
    {
        if (! $lesson->homework_enabled) {
            return false;
        }

        if (filled($lesson->homework_closed_at)) {
            return false;
        }

        $prompt = trim((string) $lesson->homework_prompt);
        if ($prompt === '') {
            return true;
        }

        $generic = trim((string) config('homework.auto_open.generic_prompt', 'Домашнее задание'));

        return $prompt === $generic;
    }

    /**
     * @return Collection<int, Group>
     */
    private function groupsForLesson(Lesson $lesson): Collection
    {
        if ($lesson->group_id) {
            $group = $lesson->group ?? Group::query()->find($lesson->group_id);

            return $group ? collect([$group]) : collect();
        }

        if (! $lesson->course_id) {
            return collect();
        }

        return Group::query()
            ->whereHas('courses', fn ($q) => $q->whereKey($lesson->course_id))
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->get();
    }

    private function successText(Lesson $lesson): string
    {
        $lesson->loadMissing('course');
        $title = $lesson->title ? e($lesson->title) : 'урок';
        $date = $lesson->lesson_date?->format('d.m.Y');
        $dateBit = $date ? " ({$date})" : '';
        $url = $lesson->course
            ? route('student.lesson', [$lesson->course->slug, $lesson->id])
            : '';

        return "✅ Обновила ДЗ к уроку <b>{$title}</b>{$dateBit}"
            .($url !== '' ? "\n{$url}" : '');
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     */
    private function replyWithChoices(string $chatId, Collection $lessons): void
    {
        $rows = [];
        foreach ($lessons->take(5) as $lesson) {
            $label = trim(($lesson->lesson_date?->format('d.m') ?? '').' '.($lesson->title ?: "урок #{$lesson->id}"));
            $label = Str::limit($label, 40, '…');
            $rows[] = [[
                'text' => $label,
                'callback_data' => self::CALLBACK_PREFIX.$lesson->id,
            ]];
        }

        $this->sendHtml(
            $chatId,
            'Несколько уроков подходят. Выберите, к какому прикрепить ДЗ:',
            $rows,
        );
    }

    /** @param  list<int>  $lessonIds */
    private function storePending(int|string $fromId, string $chatId, string $prompt, array $lessonIds): void
    {
        $ttl = max(5, (int) config('homework.tg_tag.pending_ttl_minutes', 60));
        Cache::put($this->pendingKey($fromId, $chatId), [
            'prompt' => $prompt,
            'lesson_ids' => array_map('intval', $lessonIds),
        ], now()->addMinutes($ttl));
    }

    /** @return array{prompt: string, lesson_ids: list<int>}|null */
    private function getPending(int|string $fromId, string $chatId): ?array
    {
        $data = Cache::get($this->pendingKey($fromId, $chatId));

        if (! is_array($data) || ! isset($data['prompt'], $data['lesson_ids']) || ! is_array($data['lesson_ids'])) {
            return null;
        }

        return [
            'prompt' => (string) $data['prompt'],
            'lesson_ids' => array_map('intval', $data['lesson_ids']),
        ];
    }

    private function forgetPending(int|string $fromId, string $chatId): void
    {
        Cache::forget($this->pendingKey($fromId, $chatId));
    }

    private function pendingKey(int|string $fromId, string $chatId): string
    {
        return "homework.tg_tag.pending.{$fromId}.{$chatId}";
    }

    private function replyHtml(string $chatId, string $html): void
    {
        $this->sendHtml($chatId, $html);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $inlineKeyboard
     */
    private function sendHtml(string $chatId, string $html, ?array $inlineKeyboard = null): ?int
    {
        $token = $this->botToken();
        if ($token === '' || $chatId === '') {
            return null;
        }

        // Идемпотентность (TelegramSendGuard): сервис вызывается и из вебхука
        // (ределивери Telegram), и из ProcessTelegramZapisiUpdate (tries=3) —
        // повторная обработка того же тега не должна дать второй ответ в чат.
        // Клейм по тексту; раскладка кнопок в ключ не входит (текст у разных
        // клавиатур различается).
        if (! TelegramSendGuard::claim($chatId, $html)) {
            Log::info('HomeworkTelegramTagService: identical message already sent, duplicate suppressed', [
                'chat_id' => $chatId,
                'dedup_key' => TelegramSendGuard::key($chatId, $html),
            ]);

            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                Log::warning('HomeworkTelegramTagService: sendMessage failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $messageId = $response->json('result.message_id');

            return is_numeric($messageId) ? (int) $messageId : null;
        } catch (\Throwable $e) {
            Log::warning('HomeworkTelegramTagService: sendMessage exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function answerCallback(string $callbackId, ?string $text = null): void
    {
        if ($callbackId === '') {
            return;
        }

        $token = $this->botToken();
        if ($token === '') {
            return;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $payload['text'] = $text;
            $payload['show_alert'] = false;
        }

        try {
            Http::post("https://api.telegram.org/bot{$token}/answerCallbackQuery", $payload);
        } catch (\Throwable $e) {
            Log::warning('HomeworkTelegramTagService: answerCallback failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Групповые уведомления и #ДЗ — через @zapisi_ORSbot (MarketingSetting),
     * тот же бот, что напоминания о занятиях в чаты групп (SendZapisiBotMessageJob).
     * Кабинетный student-bot в групповые чаты часто не добавлен → chat not found.
     */
    private function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($zapisi !== '') {
            return $zapisi;
        }

        // Fallback: local/tests without MarketingSetting.zapisi_bot_token.
        return (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token')
            ?: '');
    }
}
