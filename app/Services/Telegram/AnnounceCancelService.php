<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H4519: учитель пишет анонс обычными словами («в четверг не смогу, врач»,
 * «отменяю занятие», «не будет занятия 17.09») — бот предлагает кнопку
 * [Снять DD.MM? Да/Нет]. Никакой автопомпы: снимает только явный тап «Да»
 * (AnnounceCancelCallbackService), чужой тап отклоняется, решение
 * подтверждается правкой сообщения с кнопкой (клавиатура уходит).
 *
 * Порядок в цепочке ProcessTelegramZapisiUpdate: ПОСЛЕ явных команд
 * (DateAware/CancelClass/Vacation) — они приоритетны; до CancelUsageHint
 * не важно: hint сам спрашивает wouldOffer() и молчит, если будет кнопка.
 *
 * Bulletproof v1: ни даты, ни однозначного кандидата — молчание (никогда
 * не угадываем). Прошедшие занятия не предлагаются. Flag
 * services.telegram_zapisi.announce_cancel_detect, default false.
 */
final class AnnounceCancelService
{
    /**
     * Явное/веское слово отмены в тексте. Даты НЕ обязательны. Сознательно
     * широкие стемы («отмен», «перенос», «не смогу») — ложное срабатывание
     * безобидно (решение за явным тапом «Да»), а ложный негатив = учитель
     * не получил кнопку. ACL всё равно фильтрует не-учителей чата.
     */
    private const ANNOUNCE_PATTERN = '/отмен|перенос|не\s+будет\s+(?:занятия|урока)|(?:занятие|урок)\s+не\s+будет|не\s+смог(?:у|ем)/u';

    private const OFFER_CLAIM_TTL_SECONDS = 86400;

    /** Максимум кнопок в одном предложении. */
    private const MAX_CANDIDATES = 3;

    public static function enabled(): bool
    {
        return (bool) config('services.telegram_zapisi.announce_cancel_detect', false);
    }

    /**
     * Дёшево: только текст. Для CancelUsageHint (не предложить ли кнопку
     * вместо подсказки формата) и для раннего выхода в handle().
     *
     * @param  array<string, mixed>  $message
     */
    public static function matches(array $message): bool
    {
        $text = self::normalize($message);

        return $text !== '' && preg_match(self::ANNOUNCE_PATTERN, $text) === 1;
    }

    public function handle(array $message): void
    {
        if (! self::enabled()) {
            return;
        }

        $offer = $this->planOffer($message);
        if ($offer === null) {
            return;
        }

        [$chatId, $candidates] = $offer;

        // Повторный анонс того же сообщения — не дублируем предложение.
        $messageId = (int) ($message['message_id'] ?? 0);
        if (! TelegramSendGuard::claimKey('tg:announce-offer:'.$chatId.':'.$messageId, self::OFFER_CLAIM_TTL_SECONDS)) {
            Log::info('AnnounceCancel: offer already sent for this message, suppressed', ['chat_id' => $chatId]);

            return;
        }

        $lines = [];
        $keyboard = [];
        foreach ($candidates as $schedule) {
            $label = $schedule->start->format('d.m').' ('.$this->weekdayShort($schedule->start).') '
                .$schedule->start->format('H:i');
            $lines[] = '• '.$label.' — '.mb_strimwidth((string) $schedule->title, 0, 48, '…');
            $keyboard[] = [['text' => 'Снять '.$schedule->start->format('d.m H:i'), 'callback_data' => 'acx:'.$schedule->id]];
        }
        $keyboard[] = [['text' => 'Не отменять', 'callback_data' => 'acxn']];

        $text = "Похоже, вы сообщили об отмене. Снять с расписания?\n".implode("\n", $lines)
            ."\n\nОтмена уберёт занятие без сдвига остальных дат.";

        $this->sendOffer($chatId, $text, $keyboard, $candidates->pluck('id')->all());
    }

    /**
     * Полная контекстная проверка БЕЗ побочных эффектов: будет ли кнопка.
     * true — и handle() пошлёт то же самое (hint молчит).
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: Collection<int, Schedule>}|null
     */
    public function planOffer(array $message): ?array
    {
        if (! self::matches($message)) {
            return null;
        }

        // Явные команды приоритетны: сработали (или сработают) сами — кнопки не надо.
        if (DateAwareCancelService::matches($message) || CancelClassCommandService::matches($message)) {
            return null;
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        if (! is_numeric($chatId) || ! is_numeric($fromId)) {
            return null;
        }

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null) {
            return null;
        }

        $group = Group::query()->where('telegram_chat_id', (string) $chatId)->first();
        if ($group === null) {
            return null;
        }

        if (! TelegramCommandAcl::managesAll($actor['role'])) {
            $teacher = $actor['teacher'];
            if ($teacher === null || ! Group::ledBy($teacher->id)->whereKey($group->id)->exists()) {
                return null;
            }
        }

        $candidates = $this->candidates($group, self::normalize($message));
        if ($candidates->isEmpty()) {
            return null;
        }

        return [(string) $chatId, $candidates];
    }

    /**
     * Кандидаты на снятие: точные даты из текста (DD.MM, «сегодня», «завтра»,
     * «в четверг») → занятия тех дней в будущем. Дат нет → однозначный случай:
     * ровно одно будущее занятие группы. Иначе — молчание, не угадываем.
     *
     * @return Collection<int, Schedule>
     */
    private function candidates(Group $group, string $text): Collection
    {
        $dates = DateAwareCancelService::parseDates($text);

        foreach (['сегодня' => 0, 'завтра' => 1] as $word => $offset) {
            if (preg_match('/\b'.$word.'\b/u', $text) === 1) {
                $dates[] = now()->startOfDay()->addDays($offset);
            }
        }

        foreach (self::weekdayFromText($text) as $weekday) {
            $date = now()->startOfDay();
            while ($date->dayOfWeek !== $weekday) {
                $date->addDay();
            }
            $dates[] = $date;
            // «В четверг», сказанное в четверг, двусмысленно: сегодня ИЛИ через
            // неделю. Предлагаем обе даты кнопками — выбор за учителем.
            if ($date->isToday()) {
                $dates[] = $date->copy()->addWeek();
            }
        }

        $query = Schedule::query()
            ->where('group_id', $group->id)
            ->where('start', '>', now())
            ->orderBy('start');

        if ($dates !== []) {
            $query->where(function ($q) use ($dates): void {
                foreach ($dates as $date) {
                    $q->orWhereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
                }
            });
        } elseif (Schedule::query()->where('group_id', $group->id)->where('start', '>', now())->count() !== 1) {
            // Ни дат, ни однозначности — не предлагаем ничего.
            return collect();
        }

        return $query->limit(self::MAX_CANDIDATES)->get();
    }

    /**
     * @return list<int> 0=воскресенье … 6=суббота (dayOfWeek)
     */
    private static function weekdayFromText(string $text): array
    {
        $map = [
            'понедельник' => 1, 'вторник' => 2, 'среду' => 3, 'среда' => 3, 'четверг' => 4,
            'пятницу' => 5, 'пятница' => 5, 'субботу' => 6, 'суббота' => 6, 'воскресенье' => 0,
        ];

        $found = [];
        foreach ($map as $word => $dow) {
            if (preg_match('/\b'.$word.'\b/u', $text) === 1) {
                $found[$dow] = true;
            }
        }

        return array_keys($found);
    }

    private function weekdayShort(Carbon $date): string
    {
        return ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'][(int) $date->format('w')];
    }

    private static function normalize(array $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
    }

    /** Токен @zapisi_ORSbot (MarketingSetting) с локальным фолбэком для тестов. */
    private static function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($zapisi !== '') {
            return $zapisi;
        }

        return (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token')
            ?: '');
    }

    /**
     * Синхронная отправка предложения (нужен message_id не нужен — правку
     * делает колбэк по callback.message). Идемпотентность/транспорт — как в
     * SendZapisiBotMessageJob: клейм по тексту, транспортный сбой ключ не
     * отпускает (ретрай подавлен), отказ Telegram — отпускает и логируется.
     *
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     * @param  list<int>  $candidateIds
     */
    private function sendOffer(string $chatId, string $text, array $keyboard, array $candidateIds = []): void
    {
        if (! TelegramSendGuard::claim($chatId, $text)) {
            Log::info('AnnounceCancel: identical offer already sent, duplicate suppressed', ['chat_id' => $chatId]);

            return;
        }

        $token = self::botToken();
        if ($token === '') {
            return;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('AnnounceCancel: transport failure, retry suppressed to avoid duplicate', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            TelegramSendGuard::release($chatId, $text);
            Log::warning('AnnounceCancel: Telegram sendMessage error', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return;
        }

        Log::info('AnnounceCancel: offer sent', [
            'chat_id' => $chatId,
            'offer_message_id' => $response->json('result.message_id'),
            'candidates' => $candidateIds,
        ]);
    }
}
