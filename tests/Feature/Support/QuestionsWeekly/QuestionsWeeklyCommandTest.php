<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionWeeklyDelivery;
use App\Models\SupportQuestionWeeklySnapshot;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H5709 — контракт команды support:questions-weekly: границы недели
 * Europe/Moscow (вкл. UTC-пересекающие метки), dry-run без записей, backfill
 * без отправки, выровненные окна, reconciliation, zero-знаменатели,
 * версия классификатора, exactly-once доставка, PII-free агрегаты.
 */
class QuestionsWeeklyCommandTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    /** Понедельник прошлой недели поEurope/Moscow на фиксированное «сейчас». */
    private \Carbon\CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        // Фиксированное «сейчас»: понедельник 05-10-2026 08:00 MSK —
        // предыдущая ЗАВЕРШЁННАЯ неделя = 28-09..05-10.
        $now = \Carbon\CarbonImmutable::parse('2026-10-05 08:00:00', 'Europe/Moscow');
        \Carbon\CarbonImmutable::setTestNow($now);

        $this->monday = \Carbon\CarbonImmutable::parse('2026-09-28 00:00:00', 'Europe/Moscow');

        $this->account = TelegramSupportAccount::create([
            'name' => 'support',
            'is_enabled' => true,
            'last_synced_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedMessage(\Carbon\CarbonImmutable $sentAt, string $text, array $overrides = []): TelegramSupportMessage
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
                'username' => $overrides['contact_username'] ?? null,
            ],
        );

        // Инжест-семантика: уникальный ключ (account, chat, message) —
        // повторный импорт того же сообщения = updateOrCreate, не вторая строка.
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

    /** Студент: юзер с группой. */
    private function seedStudent(): User
    {
        $user = User::factory()->create();
        $group = \App\Models\Group::create(['name' => 'Группа', 'slug' => 'gr-'.random_int(1, 999999)]);
        \Illuminate\Support\Facades\DB::table('group_user')->insert([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'left_at' => null,
        ]);

        return $user;
    }

    public function test_default_window_is_previous_completed_week_msk(): void
    {
        $student = $this->seedStudent();

        // 1. Внутри недели (вс 23:30 MSK).
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-10-04 23:30:00', 'Europe/Moscow'), 'сколько стоит курс?', ['linked_user_id' => $student->id]);
        // 2. UTC-пересекающая метка: понедельник 01:30 MSK = воскресенье 22:30 UTC прошлой недели — внутри.
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-28 01:30:00', 'Europe/Moscow'), 'когда запись появится?', ['linked_user_id' => $student->id]);
        // 3. Ровно на границе конца (следующий понедельник 00:00 MSK) — НЕ входит.
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-10-05 00:00:00', 'Europe/Moscow'), 'не должна попасть: сколько стоит?', ['linked_user_id' => $student->id]);
        // 4. До окна — не входит.
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-27 23:59:00', 'Europe/Moscow'), 'раньше окна: сколько стоит?', ['linked_user_id' => $student->id]);
        // 5. Исходящий ответ куратора — исключается из входящих.
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-30 10:00:00', 'Europe/Moscow'), 'ответ куратора', ['direction' => 'outgoing']);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::query()->firstOrFail();
        $this->assertSame('2026-09-28', (string) $snapshot->week_start);
        $this->assertSame(2, $snapshot->payload['reconciliation']['incoming_total']); // только 1 и 2
        $this->assertSame(1, $snapshot->payload['totals']['outgoing_excluded']);
        $this->assertSame(2, $snapshot->payload['populations']['student']['questions']);
        $this->assertSame(1, $snapshot->payload['populations']['student']['by_category']['D'] ?? 0);
        $this->assertSame(1, $snapshot->payload['populations']['student']['by_category']['B'] ?? 0);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedMessage($this->monday->addDays(1), 'где мои материалы?', []);

        $this->artisan('support:questions-weekly', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, SupportQuestionWeeklySnapshot::count());
        $this->assertSame(0, SupportQuestionClassification::count());
        $this->assertSame(0, SupportQuestionWeeklyDelivery::count());
    }

    public function test_dry_run_allows_non_aligned_window_but_persist_refuses(): void
    {
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-29 12:00:00', 'Europe/Moscow'), 'сколько стоит?');

        // Не-понедельник и не 7 дней: только dry-run.
        $this->artisan('support:questions-weekly', [
            '--from' => '2026-09-29',
            '--to' => '2026-10-01',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->artisan('support:questions-weekly', [
            '--from' => '2026-09-29',
            '--to' => '2026-10-06',
        ])->expectsOutputToContain('Monday-aligned')->assertFailed();
    }

    public function test_backfill_creates_snapshots_never_deliveries(): void
    {
        $student = $this->seedStudent();
        // Неделя 14-09..20-09 и неделя 21-09..27-09.
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Moscow'), 'не могу зайти в zoom', ['linked_user_id' => $student->id]);
        $this->seedMessage(\Carbon\CarbonImmutable::parse('2026-09-22 10:00:00', 'Europe/Moscow'), 'когда расписание?', ['linked_user_id' => $student->id]);

        Http::fake();
        Http::preventStrayRequests();

        $this->artisan('support:questions-weekly', ['--backfill' => true, '--backfill-from' => '2026-09-14'])->assertSuccessful();

        $this->assertSame(2, SupportQuestionWeeklySnapshot::count());
        $this->assertSame(0, SupportQuestionWeeklyDelivery::count());
        Http::assertNothingSent();
    }

    public function test_duplicate_import_never_double_counts(): void
    {
        $student = $this->seedStudent();
        $base = [
            'telegram_chat_id' => 555,
            'telegram_user_id' => 5551,
            'linked_user_id' => $student->id,
            'telegram_message_id' => 777,
        ];
        $this->seedMessage($this->monday->addDays(1), 'сколько стоит курс?', $base);
        // Инжест-дубль по уникальному ключу: updateOrCreate, не вторая строка.
        $this->seedMessage($this->monday->addDays(1), 'сколько стоит курс?', $base);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::firstOrFail();
        $this->assertSame(1, $snapshot->payload['reconciliation']['incoming_total']);
        $this->assertSame(1, $snapshot->payload['populations']['student']['questions']);
        $this->assertSame(0, $snapshot->payload['reconciliation']['excluded']['duplicate_import']);
        // Идемпотентный повторный прогон не создаёт второй снапшот/классификации.
        $this->artisan('support:questions-weekly')->assertSuccessful();
        $this->assertSame(1, SupportQuestionWeeklySnapshot::count());
        $this->assertSame(1, SupportQuestionClassification::count());
    }

    public function test_exclusions_reconcile_with_source_totals(): void
    {
        $student = $this->seedStudent();
        config(['services.telegram_support.bot_usernames' => ['samskrte_bot']]);

        $this->seedMessage($this->monday->addHours(2), 'сколько стоит курс?', ['linked_user_id' => $student->id]);
        $this->seedMessage($this->monday->addHours(3), '', []); // service
        $this->seedMessage($this->monday->addHours(4), 'отчёт бота', ['contact_username' => 'samskrte_bot']); // bot
        $this->seedMessage($this->monday->addHours(5), 'спасибо, всё ок', []); // not a question
        $this->seedMessage($this->monday->addHours(6), 'ответ поддержки', ['direction' => 'outgoing']);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $payload = SupportQuestionWeeklySnapshot::firstOrFail()->payload;
        $r = $payload['reconciliation'];
        $this->assertSame(4, $r['incoming_total']);
        $this->assertSame(1, $r['excluded']['service_message']);
        $this->assertSame(1, $r['excluded']['bot']);
        $this->assertSame(1, $r['not_questions']);
        $this->assertSame(1, $r['questions']);
        $this->assertTrue($r['sum_check']);
        $this->assertSame(1, $payload['totals']['outgoing_excluded']);
    }

    public function test_zero_denominator_week_is_safe(): void
    {
        // Ни одного входящего: снапшот с null-долями, без деления на ноль.
        $this->artisan('support:questions-weekly')->assertSuccessful();

        $payload = SupportQuestionWeeklySnapshot::firstOrFail()->payload;
        $this->assertSame(0, $payload['reconciliation']['incoming_total']);
        $this->assertNull($payload['populations']['student']['question_share']);
        $this->assertNull($payload['populations']['student']['unclassified_share']);
        $this->assertNull($payload['activity']['questions_per_100_active']);
        $this->assertTrue($payload['reconciliation']['sum_check']);
    }

    public function test_stale_or_errored_sync_marks_snapshot_incomplete(): void
    {
        $this->account->update(['last_sync_error' => 'session is busy']);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $snapshot = SupportQuestionWeeklySnapshot::firstOrFail();
        $this->assertTrue($snapshot->is_incomplete);
        $this->assertSame('sync_error', $snapshot->incompleteness_reason);
    }

    public function test_classifier_version_mismatch_suppresses_comparison(): void
    {
        $student = $this->seedStudent();
        $this->seedMessage($this->monday->addDays(1), 'сколько стоит?', ['linked_user_id' => $student->id]);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        // Подделка «прошлой недели» другой версией классификатора.
        $older = SupportQuestionWeeklySnapshot::firstOrFail()->replicate();
        $older->week_start = $this->monday->modify('-7 days')->toDateString();
        $older->classifier_version = 'qw-1999-v0';
        // payload — array-cast: вложенное присваивание не пишется обратно,
        // перезаписываем массив целиком.
        $payload = $older->payload;
        $payload['classifier_version'] = 'qw-1999-v0';
        $older->payload = $payload;
        $older->save();

        $result = \App\Services\SupportQuestions\WeeklyQuestionAnalytics::comparisonReady(
            SupportQuestionWeeklySnapshot::latest('week_start')->first()->payload,
            $older->payload,
        );

        $this->assertFalse($result['ready']);
        $this->assertSame('classifier version differs', $result['reason']);
    }

    public function test_payload_contains_no_pii(): void
    {
        $student = $this->seedStudent();
        $student->update(['name' => 'Иван Петров', 'email' => 'ivan.pi@example.com']);
        $this->seedMessage($this->monday->addDays(1), 'моя почта ivan.pi@example.com, подскажите по материалам', ['linked_user_id' => $student->id]);

        $this->artisan('support:questions-weekly')->assertSuccessful();

        $json = json_encode(SupportQuestionWeeklySnapshot::firstOrFail()->payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Иван Петров', $json);
        $this->assertStringNotContainsString('ivan.pi@example.com', $json);
        $this->assertStringNotContainsString('подскажите по материалам', $json);
    }

    public function test_send_delivers_exactly_once_and_rerun_suppresses(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        \App\Models\MarketingSetting::create(['tg_bot_token' => '123:abc']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);

        $this->artisan('support:questions-weekly', ['--send' => true])->assertSuccessful();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('acknowledged', $delivery->state);
        $this->assertSame(4242, $delivery->telegram_message_id);
        Http::assertSentCount(1);

        // Повторный --send: снапшот пересчитан, НОВОГО поста нет.
        $this->artisan('support:questions-weekly', ['--send' => true])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(1, SupportQuestionWeeklyDelivery::count());
    }

    public function test_send_after_refusal_is_not_delivered_and_retry_allowed(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        \App\Models\MarketingSetting::create(['tg_bot_token' => '123:abc']);

        // Второй Http::fake() не заменяет стаб URL — переключаем ответ
        // замыканием с мутируемым флагом.
        $telegramOk = false;
        Http::fake(function () use (&$telegramOk) {
            return $telegramOk
                ? Http::response(['ok' => true, 'result' => ['message_id' => 7777]])
                : Http::response(['ok' => false, 'description' => 'chat not found'], 400);
        });

        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();

        $delivery = SupportQuestionWeeklyDelivery::firstOrFail();
        $this->assertSame('not_delivered', $delivery->state);

        // Telegram отказал → доставки не было → повтор разрешён (не blind retry).
        $telegramOk = true;
        $this->artisan('support:questions-weekly', ['--send' => true])->assertSuccessful();
        $this->assertSame('acknowledged', SupportQuestionWeeklyDelivery::firstOrFail()->state);
    }

    public function test_unknown_delivery_state_requires_reconciliation_no_blind_retry(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-100200300']);
        \App\Models\MarketingSetting::create(['tg_bot_token' => '123:abc']);

        SupportQuestionWeeklyDelivery::create([
            'week_start' => $this->monday->toDateString(),
            'state' => 'unknown',
            'claimed_at' => now()->subMinutes(10),
        ]);

        Http::fake();
        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed();
        Http::assertNothingSent();

        // Ручное разрешение: сообщение нашли в чате.
        $this->artisan('support:questions-weekly', [
            '--reconcile' => 'sent',
            '--from' => $this->monday->toDateString(),
        ])->assertSuccessful();

        $this->assertSame('acknowledged', SupportQuestionWeeklyDelivery::firstOrFail()->state);
    }

    public function test_missing_care_chat_is_a_blocker_not_invented_recipient(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '']);

        $this->artisan('support:questions-weekly', ['--send' => true])->assertFailed()
            ->expectsOutputToContain('not configured');
    }

    public function test_activity_denominator_needs_lessons_in_window(): void
    {
        $student = $this->seedStudent();
        $this->seedMessage($this->monday->addDays(1), 'где ссылка на zoom?', ['linked_user_id' => $student->id]);

        // Занятий в окне нет → нормирование недоступно.
        $this->artisan('support:questions-weekly')->assertSuccessful();
        $this->assertNull(SupportQuestionWeeklySnapshot::firstOrFail()->payload['activity']['questions_per_100_active']);

        // Добавляем занятие группы в окно → знаменатель появляется.
        $groupRow = \Illuminate\Support\Facades\DB::table('group_user')->where('user_id', $student->id)->first();
        \App\Models\Lesson::create([
            'course_id' => 'c1',
            'title' => 'Занятие',
            'lesson_date' => $this->monday->addDays(2)->toDateString(),
            'group_id' => $groupRow->group_id,
        ]);

        $this->artisan('support:questions-weekly')->assertSuccessful();
        $activity = SupportQuestionWeeklySnapshot::firstOrFail()->payload['activity'];
        $this->assertSame(1, $activity['active_students']);
        // json-round-trip в payload превращает 100.0 в int 100.
        $this->assertSame(100, $activity['questions_per_100_active']);
    }
}
