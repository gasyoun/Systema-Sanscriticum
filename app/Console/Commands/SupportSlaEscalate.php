<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\SupportSlaClock;
use App\Support\TelegramSendGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H3999 (рулинг A5): SLA-сеть над лентой ЛС.
 *
 * Открытый тред, последнее сообщение в котором — входящее, и оно висит дольше
 * первого порога РАБОЧЕГО времени, поднимает пинг первому куратору; дольше
 * второго — следующему. Тихие часы 22:00–09:00 МСК паузят и пинг, и накопление
 * минут ({@see SupportSlaClock}). Студенту при этом не уходит НИЧЕГО: SLA — это
 * сообщение кураторам про их собственную очередь, а не «мы скоро ответим».
 *
 * Каждый пинг идёт через клейм {@see TelegramSendGuard} до вызова API — фенс
 * репозитория после инцидента 24-08-2026, — и отмечается событием
 * {@see self::EVENT} с тиром: клейм закрывает дубль внутри своего окна, событие
 * закрывает его навсегда, а тир не даёт второму пингу проглотить первый.
 */
class SupportSlaEscalate extends Command
{
    public const EVENT = 'dm_sla_ping';

    protected $signature = 'support:sla-escalate
        {--dry : только показать, кого и почему пингуем}';

    protected $description = 'H3999: пинг куратору по открытым тредам без ответа (15/60 рабочих минут).';

    public function handle(TelegramAdminNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry');

        if (! $dry && ! (bool) config('features.support_sla_escalation', false)) {
            $this->info('support:sla-escalate выключен (SUPPORT_SLA_ESCALATION=false).');

            return self::SUCCESS;
        }

        /** @var list<string> $curators */
        $curators = array_values(array_filter(array_map(
            'strval',
            (array) config('support.sla.curators', []),
        )));

        if ($curators === []) {
            // Пустой список — не ошибка конфигурации молча, а сказанный вслух
            // отказ: иначе команда «отработала успешно», не сделав ничего.
            $this->warn('support.sla.curators пуст — пинговать некого.');

            return self::SUCCESS;
        }

        $clock = SupportSlaClock::fromConfig();
        $now = CarbonImmutable::now();

        if ($clock->isQuietNow($now)) {
            $this->info('Тихие часы — пингов нет.');

            return self::SUCCESS;
        }

        $first = max(1, (int) config('support.sla.first_ping_minutes', 15));
        $second = max($first + 1, (int) config('support.sla.second_ping_minutes', 60));
        $lookback = max(1, (int) config('support.sla.lookback_hours', 48));

        $pinged = 0;

        foreach ($this->unansweredThreads($lookback) as [$thread, $incoming]) {
            $waited = $clock->workingMinutesBetween(
                CarbonImmutable::parse($incoming->sent_at ?? $incoming->created_at),
                $now,
            );

            $tier = match (true) {
                $waited >= $second => 2,
                $waited >= $first => 1,
                default => 0,
            };

            if ($tier === 0) {
                continue;
            }

            $curator = $curators[$tier - 1] ?? null;

            if ($curator === null) {
                // Второго куратора в списке нет — второй тир некому отдать.
                continue;
            }

            if ($this->alreadyPinged($thread, $tier)) {
                continue;
            }

            $text = $this->pingText($thread, $incoming, $waited, $tier);

            $this->line(sprintf(
                '#%d %s — %d раб. мин, тир %d → %s',
                $thread->id,
                $thread->displayName(),
                $waited,
                $tier,
                $curator,
            ));

            if ($dry) {
                continue;
            }

            if (! TelegramSendGuard::claim($curator, $text)) {
                continue;
            }

            $delivered = $notifier->notifyRecipients([$curator], $text);

            if ($delivered === []) {
                // Точно известный отказ доставки — клейм отпускаем, чтобы
                // следующий проход планировщика имел право попробовать снова.
                TelegramSendGuard::release($curator, $text);
                $this->error("Пинг куратору {$curator} не доставлен.");

                continue;
            }

            SupportAiReplyEvent::create([
                'telegram_support_message_id' => $incoming->id,
                'event_type' => self::EVENT,
                'meta' => [
                    'via' => 'support_sla',
                    'conversation_id' => $thread->id,
                    'tier' => $tier,
                    'waited_working_minutes' => $waited,
                    'curator_chat_id' => $curator,
                ],
            ]);

            $pinged++;
        }

        $this->info($dry ? '--dry: ничего не отправлено.' : "Пингов отправлено: {$pinged}.");

        return self::SUCCESS;
    }

    /**
     * Открытые треды, последнее сообщение которых — входящее.
     *
     * Потолок окна обязателен: без него первый же прогон на живой базе
     * разослал бы кураторам весь бэклог — тот самый урок, который H3380 v2.2
     * выучил на первичном history-заборе.
     *
     * @return list<array{0: SupportConversation, 1: TelegramSupportMessage}>
     */
    private function unansweredThreads(int $lookbackHours): array
    {
        $since = now()->subHours($lookbackHours);

        $threads = SupportConversation::query()
            ->whereIn('status', [SupportConversation::STATUS_OPEN, SupportConversation::STATUS_PENDING])
            ->where('last_message_at', '>=', $since)
            ->orderBy('last_message_at')
            ->limit(200)
            ->get();

        $out = [];

        foreach ($threads as $thread) {
            /** @var TelegramSupportMessage|null $last */
            $last = TelegramSupportMessage::query()
                ->where('support_conversation_id', $thread->id)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            if ($last === null || $last->direction !== 'incoming') {
                continue;
            }

            $out[] = [$thread, $last];
        }

        return $out;
    }

    private function alreadyPinged(SupportConversation $thread, int $tier): bool
    {
        return SupportAiReplyEvent::query()
            ->where('event_type', self::EVENT)
            ->where('meta->conversation_id', $thread->id)
            ->where('meta->tier', $tier)
            ->exists();
    }

    private function pingText(
        SupportConversation $thread,
        TelegramSupportMessage $incoming,
        int $waited,
        int $tier,
    ): string {
        $name = htmlspecialchars($thread->displayName(), ENT_QUOTES, 'UTF-8');
        $question = htmlspecialchars(mb_substr((string) $incoming->text, 0, 300), ENT_QUOTES, 'UTF-8');
        $head = $tier === 1
            ? '⏱ <b>Вопрос без ответа</b>'
            : '⏱ <b>Вопрос без ответа — второй круг</b>';

        return implode("\n", [
            $head,
            "Студент: {$name}",
            "Ждёт {$waited} раб. мин.",
            '',
            "<i>{$question}</i>",
            '',
            'Студенту про SLA ничего не уходило — ответьте в Telegram.',
        ]);
    }
}
