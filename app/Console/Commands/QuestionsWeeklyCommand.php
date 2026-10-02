<?php

namespace App\Console\Commands;

use App\Services\SupportQuestions\QuestionMessageClassifier;
use App\Services\SupportQuestions\WeeklyQuestionAnalytics;
use App\Services\SupportQuestions\WeeklyReportComposer;
use App\Services\SupportQuestions\WeeklyReportDeliverer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H5709 — недельная аналитика студенческих вопросов Telegram.
 *
 * Даты — календарные Europe/Moscow: --from включительно, --to — начало
 * следующего дня (эксклюзивный конец). Дефолт — предыдущая завершённая
 * неделя Mon–Sun. Персистить можно только выровненные по понедельнику
 * 7-дневные окна (недельные снапшоты); произвольные окна — только --dry-run.
 *
 * Отправка — ТОЛЬКО явным --send, через exactly-once леджер (см.
 * WeeklyReportDeliverer); --backfill никогда не отправляет; --dry-run не
 * пишет вообще ничего (ни снапшотов, ни классификаций, ни доставок).
 */
class QuestionsWeeklyCommand extends Command
{
    protected $signature = 'support:questions-weekly
        {--from= : ISO-дата (Europe/Moscow), включительно}
        {--to= : ISO-дата, эксклюзивный конец (начало следующего дня)}
        {--dry-run : ничего не писать: ни снапшотов, ни классификаций, ни доставок}
        {--backfill : пересчитать исторические недельные снапшоты; БЕЗ отправки}
        {--backfill-from=2026-07-06 : дата старта бэкфилла (ISO, дефолт 2026-07-06)}
        {--send : отправить сводку в чат «Отдел заботы» (явно; требует совместимого окна)}
        {--reconcile= : sent|not_sent — разрешить неоднозначную доставку недели (--from)}';

    protected $description = 'Недельная аналитика студенческих вопросов Telegram (H5709): снапшот, отчёт, exactly-once доставка.';

    public function handle(
        WeeklyQuestionAnalytics $analytics,
        WeeklyReportComposer $composer,
        WeeklyReportDeliverer $deliverer,
    ): int {
        if ($reconcile = (string) ($this->option('reconcile') ?? '')) {
            return $this->handleReconcile($deliverer, $reconcile);
        }

        $dryRun = (bool) $this->option('dry-run');
        $send = (bool) $this->option('send');
        $backfillSince = $this->option('backfill')
            ? ((string) $this->option('backfill-from') ?: WeeklyQuestionAnalytics::BACKFILL_SINCE)
            : null;

        if ($send && $dryRun) {
            $this->error('--send and --dry-run are mutually exclusive.');

            return self::FAILURE;
        }
        if ($send && $backfillSince !== null) {
            $this->error('--send and --backfill are mutually exclusive (backfill never sends).');

            return self::FAILURE;
        }

        if ($backfillSince !== null) {
            return $this->handleBackfill($analytics, $backfillSince, $dryRun);
        }

        [$from, $to] = $this->resolveWindow();

        $aligned = $from->isMonday() && $from->addDays(7)->equalTo($to);
        if (! $aligned && ! $dryRun) {
            $this->error(sprintf(
                'Window %s..%s is not a Monday-aligned 7-day week; persisting requires an aligned week. Use --dry-run for ad-hoc analysis.',
                $from->toDateString(),
                $to->toDateString(),
            ));

            return self::FAILURE;
        }

        $result = $analytics->computeWindow($from, $to, $persist = ! $dryRun);

        $previous = $analytics->recentSnapshots(6)
            ->filter(fn ($s): bool => (string) $s->week_start < $from->toDateString())
            ->first();

        $report = $composer->compose(
            $result['payload'],
            $result['is_incomplete'],
            $result['incompleteness_reason'],
            $previous,
            $this->dashboardUrl(),
        );

        if ($persist) {
            $analytics->persistSnapshot($result['payload'], $result['is_incomplete'], $result['incompleteness_reason']);
        }

        $summary = [
            'week_start' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dry_run' => $dryRun,
            'persisted' => $persist,
            'is_incomplete' => $result['is_incomplete'],
            'incompleteness_reason' => $result['incompleteness_reason'],
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'totals' => $result['payload']['totals'],
            'reconciliation' => $result['payload']['reconciliation'],
            'coverage' => $result['payload']['coverage'],
            'populations' => array_map(
                static fn (array $p): array => [
                    'questions' => $p['questions'],
                    'by_category' => $p['by_category'],
                    'unclassified' => $p['unclassified'],
                    'unique_questioners' => $p['unique_questioners'],
                ],
                $result['payload']['populations'],
            ),
        ];

        if (! $result['payload']['reconciliation']['sum_check']) {
            $this->error('RECONCILIATION MISMATCH: exclusions do not sum to the incoming total.');
        }

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- report html ---');
        $this->line($report);

        if ($send) {
            try {
                $delivery = $deliverer->deliver($from->toDateString(), $report);
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->line(json_encode([
                'delivered' => true,
                'week_start' => $from->toDateString(),
                'state' => $delivery['state'],
                'telegram_message_id' => $delivery['message_id'],
                'suppressed' => $delivery['suppressed'],
                'reason' => $delivery['reason'],
            ], JSON_UNESCAPED_UNICODE));

            if ($delivery['suppressed']) {
                $this->warn(sprintf(
                    'Post for week %s was suppressed (%s): snapshot refreshed, no repost — exactly-once holds.',
                    $from->toDateString(),
                    $delivery['reason'],
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(): array
    {
        $fromRaw = (string) ($this->option('from') ?? '');
        $toRaw = (string) ($this->option('to') ?? '');

        if ($fromRaw !== '' && $toRaw !== '') {
            $window = WeeklyQuestionAnalytics::windowForDates($fromRaw, $toRaw);

            return [$window['from'], $window['to']];
        }
        if ($fromRaw !== '' || $toRaw !== '') {
            throw new \InvalidArgumentException('--from and --to must be passed together.');
        }

        $window = WeeklyQuestionAnalytics::defaultWindow();

        return [$window['from'], $window['to']];
    }

    private function handleBackfill(WeeklyQuestionAnalytics $analytics, string $since, bool $dryRun): int
    {
        $firstMonday = CarbonImmutable::parse($since, 'Europe/Moscow');
        if (! $firstMonday->isMonday()) {
            $firstMonday = $firstMonday->startOfWeek(CarbonImmutable::MONDAY);
        }

        [$lastFrom] = array_values(WeeklyQuestionAnalytics::defaultWindow());

        $weeks = [];
        for ($monday = $firstMonday; $monday->lt($lastFrom); $monday = $monday->addWeek()) {
            $weeks[] = $monday;
        }

        if ($weeks === []) {
            $this->warn('No completed weeks in the backfill range.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($weeks as $monday) {
            $result = $analytics->computeWindow($monday, $monday->addDays(7), $persist = ! $dryRun);
            if ($persist) {
                $analytics->persistSnapshot($result['payload'], $result['is_incomplete'], $result['incompleteness_reason']);
            }
            $rows[] = [
                'week_start' => $monday->toDateString(),
                'external_questions' => $result['payload']['totals']['external_questions'],
                'is_incomplete' => $result['is_incomplete'],
                'incompleteness_reason' => $result['incompleteness_reason'],
                'reconciles' => $result['payload']['reconciliation']['sum_check'],
            ];
        }

        $this->line(json_encode([
            'backfill' => true,
            'dry_run' => $dryRun,
            'since' => $firstMonday->toDateString(),
            'weeks' => count($rows),
            'persisted' => ! $dryRun,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function handleReconcile(WeeklyReportDeliverer $deliverer, string $verdict): int
    {
        if (! in_array($verdict, ['sent', 'not_sent'], true)) {
            $this->error('--reconcile accepts sent|not_sent.');

            return self::FAILURE;
        }

        $fromRaw = (string) ($this->option('from') ?? '');
        if ($fromRaw === '') {
            $this->error('--reconcile requires --from=<monday of the week>.');

            return self::FAILURE;
        }

        $weekStart = CarbonImmutable::parse($fromRaw, 'Europe/Moscow')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        try {
            $result = $deliverer->reconcile($weekStart, $verdict === 'sent');
        } catch (\Throwable $e) {
            $this->error('No delivery row for week '.$weekStart.' — nothing to reconcile.');

            return self::FAILURE;
        }

        $this->line(json_encode(['week_start' => $weekStart, 'reconciled' => $result], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function dashboardUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $panel = trim((string) config('filament.path', 'admin'), '/');

        return sprintf('%s/%s/telegram-support/support-questions-weekly', $base, $panel);
    }
}
