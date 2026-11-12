<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Services\Support\DraftReviewMinutesLedger;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6177 (E026, hermes-24-01a10bca): acceptance-обёртка учёта черновиков и
 * человеческих минут.
 *
 * Сценарии миссии: разделение исходов accepted/minor · substantial-edit ·
 * reject (знаменатель включает отказы); отправка НЕ выводится из принятия;
 * минуты review/correction/incident; запрет отрицательного времени;
 * дедупликация событий; сохранение без текста учеников и реальных сообщений.
 *
 * Фикстуры — только синтетика (SQLite in-memory, forceCreate), ни одной
 * production-записи и ни одного реального сообщения; тексты фикстур —
 * сентинел, который обязан отсутствовать во всех сохранённых строках журнала.
 * Ожидаемые значения зафиксированы литералами.
 *
 * Точка входа `php artisan test --filter=H6177HermesAcceptanceTest`
 * зарегистрирована по правилам owning repo (tests/Feature/Support, PSR-4
 * Tests\Feature\Support, автодискавери phpunit.xml).
 */
class H6177HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** Сентинел текста ученика — не должен попасть ни в одну строку журнала. */
    private const SENTINEL = 'Х6177-СЕНТИНЕЛ-ТЕКСТ-УЧЕНИКА';

    private DraftReviewMinutesLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));

        $this->ledger = new DraftReviewMinutesLedger;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_review_outcomes_are_split_with_rejects_in_denominator(): void
    {
        $this->record('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR);
        $this->record('ev-2', DraftReviewMinutesLedger::OUTCOME_SUBSTANTIAL_EDIT);
        $this->record('ev-3', DraftReviewMinutesLedger::OUTCOME_REJECT);

        $aggregate = $this->ledger->countWindow($this->windowFrom(), $this->windowTo());

        $this->assertSame(1, $aggregate['accepted_minor_n']);
        $this->assertSame(1, $aggregate['substantial_edit_n']);
        $this->assertSame(1, $aggregate['reject_n']);
        // Знаменатель «Принято черновиков» включает отказы.
        $this->assertSame(3, $aggregate['total_reviews']);
    }

    public function test_sent_is_not_derived_from_acceptance(): void
    {
        // Принят, но НЕ отправлен — принятие не влечёт отправку.
        $this->record('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, sent: false);
        // Существенная правка и отправка — отправка без «принятия как есть».
        $this->record('ev-2', DraftReviewMinutesLedger::OUTCOME_SUBSTANTIAL_EDIT, sent: true);
        // Принят и отправлен.
        $this->record('ev-3', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, sent: true);

        $aggregate = $this->ledger->countWindow($this->windowFrom(), $this->windowTo());

        $this->assertSame(2, $aggregate['accepted_minor_n']);
        $this->assertSame(1, $aggregate['substantial_edit_n']);
        $this->assertSame(0, $aggregate['reject_n']);
        $this->assertSame(3, $aggregate['total_reviews']);
        // sent_n считается только по флагу sent, а не по принятым.
        $this->assertSame(2, $aggregate['sent_n']);
    }

    public function test_review_correction_incident_minutes_are_accounted(): void
    {
        $this->record('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, minutes: ['review' => 5, 'correction' => 2]);
        $this->record('ev-2', DraftReviewMinutesLedger::OUTCOME_SUBSTANTIAL_EDIT, minutes: ['correction' => 3]);
        $this->record('ev-3', DraftReviewMinutesLedger::OUTCOME_REJECT, minutes: ['incident' => 7]);

        $aggregate = $this->ledger->countWindow($this->windowFrom(), $this->windowTo());

        $this->assertSame(5.0, $aggregate['review_minutes_total']);
        $this->assertSame(5.0, $aggregate['correction_minutes_total']);
        $this->assertSame(7.0, $aggregate['incident_minutes_total']);
        $this->assertSame(17.0, $aggregate['minutes_total']);
    }

    public function test_negative_time_is_rejected_whole_entry(): void
    {
        $result = $this->ledger->recordEvent($this->descriptor('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, minutes: ['review' => -5]));

        $this->assertSame(DraftReviewMinutesLedger::STATUS_REJECTED_NEGATIVE_TIME, $result['status']);
        $this->assertNull($result['event']);
        // Атомарная отмена: строка не написана вовсе, счётчики окна нулевые.
        $this->assertSame(0, SupportAiReplyEvent::query()->count());

        $aggregate = $this->ledger->countWindow($this->windowFrom(), $this->windowTo());

        $this->assertSame(0, $aggregate['total_reviews']);
        $this->assertSame(0.0, $aggregate['minutes_total']);
    }

    public function test_non_numeric_time_is_rejected_whole_entry(): void
    {
        $result = $this->ledger->recordEvent($this->descriptor('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, minutes: ['correction' => 'сколько-то']));

        $this->assertSame(DraftReviewMinutesLedger::STATUS_INVALID_MINUTES, $result['status']);
        $this->assertNull($result['event']);
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    public function test_unknown_outcome_is_rejected(): void
    {
        $result = $this->ledger->recordEvent($this->descriptor('ev-1', 'sent'));

        $this->assertSame(DraftReviewMinutesLedger::STATUS_INVALID_OUTCOME, $result['status']);
        $this->assertNull($result['event']);
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    public function test_events_are_deduplicated(): void
    {
        $first = $this->ledger->recordEvent($this->descriptor('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, minutes: ['review' => 4]));
        $second = $this->ledger->recordEvent($this->descriptor('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR, minutes: ['review' => 4]));

        $this->assertSame(DraftReviewMinutesLedger::STATUS_RECORDED, $first['status']);
        $this->assertSame(DraftReviewMinutesLedger::STATUS_DUPLICATE, $second['status']);
        $this->assertSame(1, SupportAiReplyEvent::query()->count());

        // Двойная подача одного события (ретрай/повтор синка) — счётчики не растут.
        $events = $this->ledger->eventsForWindow($this->windowFrom(), $this->windowTo());
        $aggregate = $this->ledger->tally($events->concat($events)->all(), $this->windowFrom(), $this->windowTo());

        $this->assertSame(1, $aggregate['accepted_minor_n']);
        $this->assertSame(1, $aggregate['total_distinct']);
        $this->assertSame(1, $aggregate['duplicates_ignored']);
        $this->assertSame(4.0, $aggregate['review_minutes_total']);
    }

    public function test_persisted_rows_carry_no_real_messages(): void
    {
        // Синтетический черновик с сентинелом текста — его текст не должен
        // уехать ни в одну строку журнала учёта.
        $draft = SupportAnswerSuggestion::forceCreate([
            'user_id' => null,
            'source_type' => SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE,
            'source_id' => 424242,
            'category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'detected_text' => self::SENTINEL,
            'draft_text' => self::SENTINEL,
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        $this->ledger->recordEvent([
            'event_key' => 'review:'.DraftReviewMinutesLedger::draftKey((string) $draft->source_type, (int) $draft->source_id),
            'draft_key' => DraftReviewMinutesLedger::draftKey((string) $draft->source_type, (int) $draft->source_id),
            'outcome' => DraftReviewMinutesLedger::OUTCOME_SUBSTANTIAL_EDIT,
            'sent' => true,
            'review_minutes' => 3,
            'correction_minutes' => 6,
            'incident_minutes' => 0,
            'occurred_at' => Carbon::parse('2026-11-10 10:00:00'),
        ]);
        $this->ledger->recordAggregate($this->ledger->countWindow($this->windowFrom(), $this->windowTo()));

        $rows = SupportAiReplyEvent::query()->get();
        $this->assertSame(2, $rows->count());

        $payload = json_encode($rows->all(), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($payload);
        $this->assertStringNotContainsString(self::SENTINEL, $payload);

        // Строки журнала несут ровно дескриптор/счётчики — без текста.
        $eventMeta = $rows->firstWhere('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_MINUTES)->meta;
        $this->assertSame(
            DraftReviewMinutesLedger::EVENT_META_KEYS,
            array_keys($eventMeta),
        );

        $aggregateMeta = $rows->firstWhere('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_AGGREGATE)->meta;
        $this->assertSame(
            [...DraftReviewMinutesLedger::AGGREGATE_KEYS, 'window_key'],
            array_keys($aggregateMeta),
        );
    }

    public function test_record_aggregate_is_idempotent_per_window(): void
    {
        $this->record('ev-1', DraftReviewMinutesLedger::OUTCOME_ACCEPTED_MINOR);

        $first = $this->ledger->recordAggregate($this->ledger->countWindow($this->windowFrom(), $this->windowTo()));
        $second = $this->ledger->recordAggregate($this->ledger->countWindow($this->windowFrom(), $this->windowTo()));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_DRAFT_REVIEW_AGGREGATE)
            ->count());
    }

    public function test_event_type_constants_fit_the_column(): void
    {
        $this->assertLessThanOrEqual(
            SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH,
            strlen(SupportAiReplyEvent::EVENT_DRAFT_REVIEW_MINUTES),
        );
        $this->assertLessThanOrEqual(
            SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH,
            strlen(SupportAiReplyEvent::EVENT_DRAFT_REVIEW_AGGREGATE),
        );
    }

    private function record(string $eventKey, string $outcome, bool $sent = false, array $minutes = []): void
    {
        $result = $this->ledger->recordEvent($this->descriptor($eventKey, $outcome, $sent, $minutes));

        $this->assertSame(DraftReviewMinutesLedger::STATUS_RECORDED, $result['status']);
    }

    /**
     * Дескриптор события журнала: синтетика, текста нет по построению.
     *
     * @return array{event_key: string, draft_key: string, outcome: string, sent: bool, review_minutes: int|float, correction_minutes: int|float, incident_minutes: int|float, occurred_at: CarbonInterface}
     */
    private function descriptor(string $eventKey, string $outcome, bool $sent = false, array $minutes = []): array
    {
        return [
            'event_key' => $eventKey,
            'draft_key' => DraftReviewMinutesLedger::draftKey(SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE, 424242),
            'outcome' => $outcome,
            'sent' => $sent,
            'review_minutes' => $minutes['review'] ?? 0,
            'correction_minutes' => $minutes['correction'] ?? 0,
            'incident_minutes' => $minutes['incident'] ?? 0,
            'occurred_at' => Carbon::parse('2026-11-10 10:00:00'),
        ];
    }

    private function windowFrom(): CarbonInterface
    {
        return Carbon::parse('2026-11-09 00:00:00');
    }

    private function windowTo(): CarbonInterface
    {
        return Carbon::parse('2026-11-15 23:59:59');
    }
}
