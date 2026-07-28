<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Разовое (и безопасное к повтору) восстановление реальных дат сообщений.
 *
 * До миграции 2026_07_28_160000 колонка `sent_at` несла ON UPDATE
 * current_timestamp: каждый ресинк переписывал её на момент импорта в UTC.
 * Настоящая дата отправки уцелела в `raw_payload.sent_at` — оттуда и берём.
 *
 * Заодно пересчитываем `support_conversations.last_message_at`: он собран из тех
 * же испорченных значений, и без пересчёта старые вопросы так и висели бы вверху
 * списка как сегодняшние.
 */
class RestoreTelegramSupportSentAt extends Command
{
    protected $signature = 'telegram:restore-sent-at
        {--dry-run : Показать расхождения, ничего не записывая}
        {--tolerance=60 : Допустимое расхождение в секундах}';

    protected $description = 'Восстанавливает sent_at телеграм-сообщений из raw_payload и пересчитывает last_message_at тредов';

    /** Раньше этой даты дат отправки быть не может — такое значение считаем мусором. */
    private const EARLIEST = '2015-01-01';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tolerance = max(1, (int) $this->option('tolerance'));

        $checked = 0;
        $fixed = 0;
        $skipped = 0;
        $threadIds = [];

        TelegramSupportMessage::query()
            ->select(['id', 'support_conversation_id', 'sent_at', 'raw_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($messages) use (&$checked, &$fixed, &$skipped, &$threadIds, $dryRun, $tolerance): void {
                foreach ($messages as $message) {
                    $checked++;

                    $real = $this->realSentAt($message);
                    if (! $real) {
                        $skipped++;

                        continue;
                    }

                    if ($message->sent_at && abs($real->diffInSeconds($message->sent_at, false)) <= $tolerance) {
                        continue;
                    }

                    if ($fixed < 20) { // длинные простыни в консоли никто не читает
                        $this->line(sprintf(
                            '  #%d: %s → %s',
                            $message->id,
                            $message->sent_at?->format('d.m.Y H:i:s') ?? '—',
                            $real->format('d.m.Y H:i:s'),
                        ));
                    }

                    if (! $dryRun) {
                        // Через query builder: save() на модели дёрнул бы updated_at
                        // и события ingest-пайплайна, а мы правим только факт.
                        DB::table('telegram_support_messages')
                            ->where('id', $message->id)
                            ->update(['sent_at' => $real->format('Y-m-d H:i:s')]);
                    }

                    $fixed++;

                    if ($message->support_conversation_id) {
                        $threadIds[(int) $message->support_conversation_id] = true;
                    }
                }
            });

        $this->info(sprintf('Проверено %d, восстановлено %d, без даты в payload %d.', $checked, $fixed, $skipped));

        $threads = $dryRun ? 0 : $this->recalculateThreads(array_keys($threadIds));

        if ($dryRun) {
            $this->warn('Прогон вхолостую: ничего не записано. Уберите --dry-run.');
        } else {
            $this->info(sprintf('Пересчитано last_message_at у %d тредов.', $threads));
        }

        return self::SUCCESS;
    }

    /** Дата из raw_payload, если она осмысленна. */
    private function realSentAt(TelegramSupportMessage $message): ?CarbonImmutable
    {
        $raw = $message->raw_payload['sent_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($raw, config('app.timezone'))
                ->timezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        if ($parsed->lt(CarbonImmutable::parse(self::EARLIEST)) || $parsed->isFuture()) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function recalculateThreads(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $touched = 0;

        SupportConversation::query()
            ->whereKey($ids)
            ->chunkById(200, function ($threads) use (&$touched): void {
                foreach ($threads as $thread) {
                    $last = collect([
                        $thread->telegramMessages()->max('sent_at'),
                        $thread->chatMessages()->max('created_at'),
                    ])->filter()->map(fn ($value) => CarbonImmutable::parse($value))->max();

                    if (! $last) {
                        continue;
                    }

                    $thread->forceFill(['last_message_at' => $last])->saveQuietly();
                    $touched++;
                }
            });

        return $touched;
    }
}
