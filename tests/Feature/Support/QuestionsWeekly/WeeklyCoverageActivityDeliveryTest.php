<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\SupportQuestionWeeklyDelivery;
use App\Models\SupportQuestionWeeklySnapshot;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\TelegramSupportScanDay;
use App\Models\User;
use App\Services\SupportQuestions\WeeklyQuestionAnalytics;
use App\Services\SupportQuestions\WeeklyReportDeliverer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5768 — контрпримеры верификатора H5709: полнота по доказательствам
 * скана инжестера (не по «входящим каждый день»), тихий просканенный день,
 * отсутствующий/устаревший второй источник, отпечаток охвата источников,
 * эксклюзивный конец окна нормирования, согласованная когорта и семантика
 * доставки (подавление без квитанции ≠ доставка).
 */
class WeeklyCoverageActivityDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // Понедельник 05-10-2026 08:00 MSK: завершённая неделя 28-09..05-10.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 08:00:00', 'Europe/Moscow'));
        $this->monday = CarbonImmutable::parse('2026-09-28 00:00:00', 'Europe/Moscow');

        $this->account = TelegramSupportAccount::create([
            'name' => 'support',
            'is_enabled' => true,
            'last_synced_at' => now(),
            'last_successful_sync_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedMessage(CarbonImmutable $sentAt, string $text, array $overrides = []): TelegramSupportMessage
    {
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $overrides['telegram_chat_id'] ?? random_int(10000, 99999)],
            ['type' => $overrides['type'] ?? 'private'],
        );
        $contact = TelegramSupportContact::firstOrCreate(
            ['telegram_user_id' => $overrides['telegram_user_id'] ?? random_int(100000, 999999)],
            [
                'telegram_support_chat_id' => $chat->id,
                'linked_user_id' => $overrides['linked_user_id'] ?? null,
            ],
        );

        return TelegramSupportMessage::updateOrCreate(
            [
                'telegram_support_account_id' => $this->account->id,
                'telegram_chat_id' => $chat->telegram_chat_id,
                'telegram_message_id' => $overrides['telegram_message_id'] ?? random_int(1, 900000),
            ],
            [
                'telegram_support_chat_id' => $chat->id,
                'telegram_support_contact_id' => $contact->id,
                'direction' => $overrides['direction'] ?? 'incoming',
                'text' => $text,
                'sent_at' => $sentAt->timezone(config('app.timezone')),
            ],
        );
    }

    /** Дневные доказательства успешного скана на всё окно. */
    private function seedScanDays(string $accountName, ?int $days = null, ?CarbonImmutable $from = null): void
    {
        $from ??= $this->monday;
        foreach (range(0, ($days ?? 7) - 1) as $i) {
            TelegramSupportScanDay::create([
                'account_name' => $accountName,
                'day' => $from->addDays($i)->toDateString(),
                'successful_runs' => 12,
                'peers_polled_max' => 5,
                'first_success_at' => now(),
                'last_success_at' => now(),
            ]);
        }
    }

    private function seedStudentWithGroup(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Группа', 'slug' => 'gr-'.random_int(1, 999999)]);
        DB::table('group_user')->insert(['group_id' => $group->id, 'user_id' => $user->id, 'left_at' => null]);

        return [$user, $group];
    }

    // ---- Покрытие источников (контрпримеры [P1] #3) ----

    public function test_quiet_fully_scanned_week_is_complete(): void
    {
        // Тихая неделя: ни одного входящего, но все 7 дней успешно
        // просканены — полнота есть, «входящих каждый день» больше не
        // требуется (прошлая семантика пометила бы неделю неполной).
        $this->seedScanDays('support');

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::firstOrFail();
        $this->assertFalse($snapshot->is_incomplete);
        $this->assertNull($snapshot->incompleteness_reason);
        $this->assertSame(7, $snapshot->payload['coverage']['scan_evidence']['support']['days_scanned']);
        $this->assertSame(0, $snapshot->payload['coverage']['days_with_incoming']);
        $this->assertSame('ingester_successful_scan_days', $snapshot->payload['coverage']['basis']);
    }

    public function test_historical_window_without_scan_evidence_is_incomplete(): void
    {
        // Исторический бэкфилл: доказательств скана нет (таблица моложе
        // окна) — честный incomplete, а не «полный по входящим».
        $this->seedMessage(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Moscow'), 'сколько стоит?');
        $this->seedMessage(CarbonImmutable::parse('2026-09-22 10:00:00', 'Europe/Moscow'), 'когда запись?');

        $this->artisan('support:questions-weekly', ['--backfill' => true, '--backfill-from' => '2026-09-14'])->assertSuccessful();

        $snapshots = SupportQuestionWeeklySnapshot::query()->orderBy('week_start')->get();
        $this->assertSame(2, $snapshots->count());
        foreach ($snapshots as $snapshot) {
            $this->assertTrue($snapshot->is_incomplete);
            $this->assertSame('scan_evidence_missing', $snapshot->incompleteness_reason);
        }
    }

    public function test_missing_configured_source_with_daily_activity_is_incomplete(): void
    {
        // Контрпример: входящие КАЖДЫЙ день недели, но настроен второй
        // источник без единого скана — прошлая семантика сказала бы
        // «полный», новая честно требует доказательства по каждому источнику.
        TelegramSupportAccount::create([
            'name' => 'support2',
            'is_enabled' => true,
            'last_synced_at' => now(),
            'last_successful_sync_at' => now(),
        ]);
        $this->seedScanDays('support');
        foreach (range(0, 6) as $i) {
            $this->seedMessage($this->monday->addDays($i)->addHours(10), 'сколько стоит курс №'.$i.'?');
        }

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::firstOrFail();
        $this->assertTrue($snapshot->is_incomplete);
        $this->assertSame('scan_evidence_missing', $snapshot->incompleteness_reason);
        $this->assertSame(7, $snapshot->payload['coverage']['days_with_incoming']); // активность была
        $this->assertCount(7, $snapshot->payload['coverage']['scan_evidence']['support2']['days_missing']);
        $this->assertSame(['support', 'support2'], $snapshot->payload['coverage']['source_scope']['accounts']);
    }

    public function test_stale_second_source_tail_is_incomplete(): void
    {
        // Второй источник сканится, но его последний успешный синк был
        // ДО конца окна — хвост окна мог быть обрезан.
        TelegramSupportAccount::create([
            'name' => 'support2',
            'is_enabled' => true,
            'last_synced_at' => now(),
            'last_successful_sync_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'Europe/Moscow'),
        ]);
        $this->seedScanDays('support');
        $this->seedScanDays('support2');

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::firstOrFail();
        $this->assertTrue($snapshot->is_incomplete);
        $this->assertSame('sync_tail_stale', $snapshot->incompleteness_reason);
    }

    public function test_source_scope_fingerprint_gates_comparison(): void
    {
        // Сравнение окон требует ОДИНАКОВОГО охвата источников: включение
        // второго аккаунта меняет отпечаток — дельты подавляются.
        $this->seedScanDays('support');
        $this->artisan('support:questions-weekly')->assertSuccessful();

        $current = SupportQuestionWeeklySnapshot::firstOrFail()->payload;

        $previous = $current;
        $previous['coverage']['source_scope']['fingerprint'] = 'another-scope';
        $this->assertFalse(WeeklyQuestionAnalytics::comparisonReady($current, $previous)['ready']);
        $this->assertSame('source scope differs', WeeklyQuestionAnalytics::comparisonReady($current, $previous)['reason']);

        // Снапшот до H5768 (без coverage_version) несравним по семантике.
        $legacy = $current;
        unset($legacy['coverage_version']);
        $this->assertSame(
            'coverage semantics differ',
            WeeklyQuestionAnalytics::comparisonReady($current, $legacy)['reason'],
        );

        // Одинаковый охват и версия — сравнение готово.
        $this->assertTrue(WeeklyQuestionAnalytics::comparisonReady($current, $current)['ready']);
    }

    // ---- Нормирование (контрпример [P2] #4) ----

    public function test_next_monday_lesson_excluded_from_activity_denominator(): void
    {
        [$student, $group] = $this->seedStudentWithGroup();
        $this->seedMessage($this->monday->addDays(1), 'где ссылка на zoom?', ['linked_user_id' => $student->id]);
        $this->seedScanDays('support');

        // Занятие РОВНО на следующем понедельнике (эксклюзивный конец):
        // в знаменатель не входит — прошлый whereBetween его включал.
        Lesson::create([
            'course_id' => 'c1',
            'title' => 'Занятие следующей недели',
            'lesson_date' => $this->monday->addDays(7)->toDateString(),
            'group_id' => $group->id,
        ]);

        $this->artisan('support:questions-weekly')->assertSuccessful();
        $activity = SupportQuestionWeeklySnapshot::firstOrFail()->payload['activity'];
        $this->assertSame(0, $activity['active_students']);
        $this->assertNull($activity['questions_per_100_active']);

        // Занятие воскресенья той же недели — входит.
        Lesson::create([
            'course_id' => 'c1',
            'title' => 'Занятие недели',
            'lesson_date' => $this->monday->addDays(6)->toDateString(),
            'group_id' => $group->id,
        ]);
        $this->artisan('support:questions-weekly')->assertSuccessful();
        $activity = SupportQuestionWeeklySnapshot::firstOrFail()->payload['activity'];
        $this->assertSame(1, $activity['active_students']);
        $this->assertSame(100, $activity['questions_per_100_active']);
    }

    public function test_activity_numerator_matches_activity_cohort(): void
    {
        // Согласование когорт: студент Б (группа, но БЕЗ занятия в окне)
        // задаёт 2 вопроса — они видны в questions_all_students, но в
        // нормирование не входят: числитель и знаменатель — одна когорта.
        [$studentA, $groupA] = $this->seedStudentWithGroup();
        [$studentB, $groupB] = $this->seedStudentWithGroup();
        $this->seedScanDays('support');

        $this->seedMessage($this->monday->addDays(1), 'где ссылка на zoom?', ['linked_user_id' => $studentA->id]);
        $this->seedMessage($this->monday->addDays(2), 'сколько стоит курс?', ['linked_user_id' => $studentB->id]);
        $this->seedMessage($this->monday->addDays(3), 'когда вернёте деньги?', ['linked_user_id' => $studentB->id]);

        Lesson::create([
            'course_id' => 'c1',
            'title' => 'Занятие группы А',
            'lesson_date' => $this->monday->addDays(2)->toDateString(),
            'group_id' => $groupA->id,
        ]);

        $this->artisan('support:questions-weekly')->assertSuccessful();
        $activity = SupportQuestionWeeklySnapshot::firstOrFail()->payload['activity'];

        $this->assertSame(1, $activity['active_students']);
        $this->assertSame(3, $activity['questions_all_students']);
        $this->assertSame(1, $activity['questions_matched_cohort']);
        $this->assertSame(100, $activity['questions_per_100_active']);
    }

    // ---- Доставка (контрпримеры [P1] #2) ----

    public function test_dedup_suppression_without_receipt_stays_unknown(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Pre-send клейм гарда без квитанции: подавление — НЕ доставка.
        app()->bind(WeeklyReportDeliverer::class, SuppressedGuardDeliverer::class);
        Http::fake();
        Http::preventStrayRequests();

        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('unknown', $delivery->state);
        $this->assertNull($delivery->telegram_message_id);
        $this->assertSame('dedup_guard', $delivery->meta['suppressed']);
        Http::assertNothingSent();

        // Слепой повтор запрещён: unknown блокирует следующий --send.
        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();
        Http::assertNothingSent();

        // Реальная квитанция найдена в чате → reconcile=sent с message_id.
        $this->artisan('support:questions-weekly', [
            '--reconcile' => 'sent',
            '--from' => $this->monday->toDateString(),
            '--reconcile-message-id' => '5551',
        ])->assertSuccessful();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('acknowledged', $delivery->state);
        $this->assertSame(5551, $delivery->telegram_message_id);
    }

    public function test_partial_send_is_unknown_and_keeps_receipt_hint(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Отчёт из двух чанков: первый ушёл (message_id 111), второй
        // отклонён Telegram — частичная доставка = unknown, не not_delivered.
        $called = 0;
        Http::fake(function () use (&$called) {
            $called++;

            return $called === 1
                ? Http::response(['ok' => true, 'result' => ['message_id' => 111]])
                : Http::response(['ok' => false, 'description' => 'too long'], 400);
        });

        $deliverer = app(WeeklyReportDeliverer::class);
        $html = str_repeat('а', 3000)."\n\n".str_repeat('б', 3000);

        try {
            $deliverer->deliver($this->monday->toDateString(), $html);
            $this->fail('Partial send must end in unknown, not success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NOT happen', $e->getMessage());
        }

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('unknown', $delivery->state);
        $this->assertSame(111, $delivery->telegram_message_id); // след частичной доставки
        $this->assertTrue((bool) $delivery->meta['partial_send']);

        // Повторный --send заблокирован состоянием unknown.
        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();
        $this->assertSame(2, $called); // новых вызовов Telegram нет
    }

    public function test_uncertain_connection_is_unknown_not_delivered(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Обрыв после попытки: доставлено или нет — неизвестно.
        Http::fake(function (): never {
            throw new ConnectionException('connection lost');
        });

        $deliverer = app(WeeklyReportDeliverer::class);
        try {
            $deliverer->deliver($this->monday->toDateString(), 'сводка');
            $this->fail('Uncertain send must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('UNKNOWN', $e->getMessage());
        }

        $this->assertSame('unknown', SupportQuestionWeeklyDelivery::firstOrFail()->state);
    }

    public function test_concurrent_send_with_live_claim_is_refused(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Живой клейм другого процесса: второй --send уходит без сети.
        SupportQuestionWeeklyDelivery::create([
            'week_start' => $this->monday->toDateString(),
            'state' => 'claimed',
            'claimed_at' => now()->subMinute(),
        ]);

        Http::fake();
        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_reconcile_cannot_reset_real_acknowledgement(): void
    {
        // Неделя реально доставлена (message_id есть): reconcile=not_sent
        // не может вернуть её в retryable состояние.
        SupportQuestionWeeklyDelivery::create([
            'week_start' => $this->monday->toDateString(),
            'state' => 'acknowledged',
            'claimed_at' => now()->subHour(),
            'sent_at' => now()->subHour(),
            'telegram_message_id' => 4242,
        ]);

        $this->artisan('support:questions-weekly', [
            '--reconcile' => 'not_sent',
            '--from' => $this->monday->toDateString(),
        ])->assertFailed();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('acknowledged', $delivery->state);
        $this->assertSame(4242, $delivery->telegram_message_id);
    }

    public function test_legacy_acknowledged_without_receipt_downgrades_to_unknown(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Строка эпохи «подавление = acknowledged» без message_id:
        // сбрасывается в unknown и требует reconciliation.
        SupportQuestionWeeklyDelivery::create([
            'week_start' => $this->monday->toDateString(),
            'state' => 'acknowledged',
            'claimed_at' => now()->subHour(),
            'suppress_reason' => 'dedup_guard',
        ]);

        Http::fake();
        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();
        Http::assertNothingSent();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('unknown', $delivery->state);
        $this->assertNull($delivery->telegram_message_id);
    }
}

/**
 * Подмена дедуп-гарда: claim() всегда false — «тот же текст уже уходил»,
 * квитанции нет. Именно контрпример верификатора: подавление без receipt.
 */
class SuppressedGuardDeliverer extends WeeklyReportDeliverer
{
    protected function guardClaim(string $chatId, string $chunk): bool
    {
        return false;
    }
}
