<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Group;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\PilotStreamInquiryCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6176 (E026, hermes-24-01a10bca): acceptance-обёртка счётчика обращений
 * пилотного потока 341/79 («Грамматика по Кочергиной, гр. 53»).
 *
 * Сценарии миссии: положительный (обращения пилота считаются), повтор
 * события (N не растёт), чужой поток (вне выборки), неизвестная
 * принадлежность (отдельная корзина); плюс сохранение агрегата без текста
 * учеников и атрибуция на момент обращения.
 *
 * Фикстуры — только синтетика (SQLite in-memory, фабрики/forceCreate),
 * ни одной production-записи и ни одного реального сообщения; тексты
 * фикстур — сентинел, который обязан отсутствовать в сохранённых агрегатах.
 * Ожидаемые значения зафиксированы литералами.
 *
 * Точка входа `php artisan test --filter=H6176HermesAcceptanceTest`
 * зарегистрирована по правилам owning repo (tests/Feature/Support,
 * PSR-4 Tests\Feature\Support, автодискавери phpunit.xml).
 */
class H6176HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** Сентинел текста ученика — не должен попасть ни в один сохранённый агрегат. */
    private const SENTINEL = 'Х6176-СЕНТИНЕЛ-ТЕКСТ-УЧЕНИКА';

    private PilotStreamInquiryCounter $counter;

    private TelegramSupportAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));

        $this->counter = new PilotStreamInquiryCounter;
        $this->account = TelegramSupportAccount::create(['name' => 'h6176-fixture']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pilot_stream_inquiries_are_counted(): void
    {
        $this->pilotStreamFixture();
        $alice = $this->pilotStudent();

        $this->tgInquiry($alice, 1001, '2026-11-10 10:00:00');
        $this->tgInquiry($alice, 1002, '2026-11-10 11:00:00');
        $this->webInquiry($alice, '2026-11-11 09:00:00');

        $aggregate = $this->counter->countWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );

        $this->assertSame(341, $aggregate['course_id']);
        $this->assertSame(79, $aggregate['group_id']);
        $this->assertSame(3, $aggregate['pilot_n']);
        $this->assertSame(0, $aggregate['other_stream_n']);
        $this->assertSame(0, $aggregate['unknown_attribution_n']);
        $this->assertSame(0, $aggregate['duplicates_ignored']);
        $this->assertSame(3, $aggregate['total_distinct']);
    }

    public function test_repeated_event_does_not_increase_n(): void
    {
        $this->pilotStreamFixture();
        $alice = $this->pilotStudent();

        $this->tgInquiry($alice, 2001, '2026-11-10 10:00:00');

        // Двойная подача одного и того же события (ретрай/повтор синка).
        $events = $this->counter->inquiryEventsForWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );
        $double = $events->concat($events)->all();

        $aggregate = $this->counter->tally($double);

        $this->assertSame(1, $aggregate['pilot_n']);
        $this->assertSame(1, $aggregate['duplicates_ignored']);
        $this->assertSame(1, $aggregate['total_distinct']);
    }

    public function test_foreign_stream_does_not_enter_the_sample(): void
    {
        $this->pilotStreamFixture();
        $alice = $this->pilotStudent();
        $stranger = $this->foreignStreamStudent();

        $this->tgInquiry($alice, 3001, '2026-11-10 10:00:00');
        $this->tgInquiry($stranger, 3002, '2026-11-10 10:05:00');
        $this->webInquiry($stranger, '2026-11-10 10:06:00');

        $aggregate = $this->counter->countWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );

        // Чужой поток не попадает в выборку: N = только pilot_n.
        $this->assertSame(1, $aggregate['pilot_n']);
        $this->assertSame(2, $aggregate['other_stream_n']);
        $this->assertSame(0, $aggregate['unknown_attribution_n']);
    }

    public function test_unknown_attribution_is_separated(): void
    {
        $this->pilotStreamFixture();
        $this->foreignStreamFixture();

        // 1) Непривязанный TG-чат без контакта.
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => -100617601],
            ['linked_user_id' => null, 'last_message_at' => now()],
        );
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => 4001,
            'direction' => 'incoming',
            'text' => self::SENTINEL,
            'sent_at' => '2026-11-10 12:00:00',
        ]);

        // 2) Пользователь без единого членства в группах.
        $hermit = User::factory()->create();
        $this->webInquiry($hermit, '2026-11-10 12:01:00');

        // 3) Неоднозначная принадлежность: пилот + чужой поток одновременно.
        $ambiguous = User::factory()->create();
        $ambiguous->groups()->attach(PilotStreamInquiryCounter::PILOT_GROUP_ID, ['created_at' => '2026-09-01 00:00:00']);
        $ambiguous->groups()->attach(80, ['created_at' => '2026-09-01 00:00:00']);
        $this->webInquiry($ambiguous, '2026-11-10 12:02:00');

        $aggregate = $this->counter->countWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );

        $this->assertSame(0, $aggregate['pilot_n']);
        $this->assertSame(0, $aggregate['other_stream_n']);
        $this->assertSame(3, $aggregate['unknown_attribution_n']);
        $this->assertSame(3, $aggregate['total_distinct']);
    }

    public function test_aggregate_is_stored_without_student_text(): void
    {
        $this->pilotStreamFixture();
        $alice = $this->pilotStudent();

        $this->tgInquiry($alice, 5001, '2026-11-10 10:00:00');
        $this->webInquiry($alice, '2026-11-10 10:01:00');

        $aggregate = $this->counter->countWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );
        $row = $this->counter->recordAggregate($aggregate);

        $this->assertSame(
            SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE,
            $row->event_type,
        );
        $this->assertNull($row->telegram_support_message_id);

        // meta — ровно счётчики + ключ окна, никакого текста.
        $expectedKeys = array_merge(PilotStreamInquiryCounter::AGGREGATE_KEYS, ['window_key']);
        sort($expectedKeys);
        $actualKeys = array_keys($row->meta);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        // Ни сентинел текста ученика, ни иных строк-текстов в агрегате.
        $this->assertStringNotContainsString(self::SENTINEL, (string) json_encode($row->meta, JSON_UNESCAPED_UNICODE));

        // Во всём журнале событий сентинела нет.
        foreach (SupportAiReplyEvent::all() as $event) {
            $this->assertStringNotContainsString(self::SENTINEL, (string) json_encode($event->toArray(), JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_repeated_aggregate_record_keeps_one_row(): void
    {
        $this->pilotStreamFixture();
        $alice = $this->pilotStudent();
        $this->tgInquiry($alice, 6001, '2026-11-10 10:00:00');

        $from = Carbon::parse('2026-11-09 00:00:00');
        $to = Carbon::parse('2026-11-15 23:59:59');

        $first = $this->counter->recordAggregate($this->counter->countWindow($from, $to));
        $second = $this->counter->recordAggregate($this->counter->countWindow($from, $to));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()
                ->where('event_type', SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE)
                ->count(),
        );
    }

    public function test_membership_is_evaluated_at_event_time(): void
    {
        $this->pilotStreamFixture();

        // Вступил в пилот-группу ПОСЛЕ обращения.
        $late = User::factory()->create();
        $late->groups()->attach(PilotStreamInquiryCounter::PILOT_GROUP_ID, ['created_at' => '2026-11-11 00:00:00']);
        $this->webInquiry($late, '2026-11-10 10:00:00');

        // Вышел из пилот-группы ДО обращения.
        $left = User::factory()->create();
        $left->groups()->attach(PilotStreamInquiryCounter::PILOT_GROUP_ID, [
            'created_at' => '2026-09-01 00:00:00',
            'left_at' => '2026-11-01 00:00:00',
        ]);
        $this->webInquiry($left, '2026-11-10 10:01:00');

        $aggregate = $this->counter->countWindow(
            Carbon::parse('2026-11-09 00:00:00'),
            Carbon::parse('2026-11-15 23:59:59'),
        );

        $this->assertSame(0, $aggregate['pilot_n']);
        $this->assertSame(0, $aggregate['other_stream_n']);
        $this->assertSame(2, $aggregate['unknown_attribution_n']);
    }

    /**
     * Обёртка над существующим валидатором длины event_type
     * (SupportAiReplyEventTypeLengthTest): новая константа обязана влезать в
     * колонку support_ai_reply_events.event_type.
     */
    public function test_new_event_type_constant_respects_existing_length_validator(): void
    {
        $this->assertLessThanOrEqual(
            SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH,
            strlen(SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE),
        );
        $this->assertGreaterThan(0, strlen(SupportAiReplyEvent::EVENT_PILOT_INQUIRY_AGGREGATE));
    }

    /**
     * Публичная конфигурация пилот-потока: course 341 / group 79 (базлайн
     * H6120 §2), синтетические строки, связка course_group.
     */
    private function pilotStreamFixture(): void
    {
        $course = Course::forceCreate([
            'id' => PilotStreamInquiryCounter::PILOT_COURSE_ID,
            'title' => 'Грамматика по Кочергиной (фикстура H6176)',
            'slug' => 'h6176-pilot-course',
            'format' => 'recorded',
            'is_visible' => true,
            'is_active' => true,
        ]);
        $group = Group::forceCreate([
            'id' => PilotStreamInquiryCounter::PILOT_GROUP_ID,
            'name' => 'Гр. 53 (фикстура H6176)',
            'slug' => 'h6176-pilot-group',
        ]);
        $course->groups()->attach($group->id);
    }

    /** Чужой поток: course 342 / group 80 — не должен попадать в выборку. */
    private function foreignStreamFixture(): void
    {
        $course = Course::forceCreate([
            'id' => 342,
            'title' => 'Чужой курс (фикстура H6176)',
            'slug' => 'h6176-foreign-course',
            'format' => 'recorded',
            'is_visible' => true,
            'is_active' => true,
        ]);
        $group = Group::forceCreate([
            'id' => 80,
            'name' => 'Чужая группа (фикстура H6176)',
            'slug' => 'h6176-foreign-group',
        ]);
        $course->groups()->attach($group->id);
    }

    private function pilotStudent(): User
    {
        $user = User::factory()->create();
        $user->groups()->attach(PilotStreamInquiryCounter::PILOT_GROUP_ID, [
            'created_at' => '2026-09-01 00:00:00',
        ]);

        return $user;
    }

    private function foreignStreamStudent(): User
    {
        $this->foreignStreamFixture();
        $user = User::factory()->create();
        $user->groups()->attach(80, ['created_at' => '2026-09-01 00:00:00']);

        return $user;
    }

    private function tgInquiry(User $user, int $messageId, string $sentAt): void
    {
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => -100617600 + $user->id],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $messageId,
            'direction' => 'incoming',
            'text' => self::SENTINEL,
            'sent_at' => $sentAt,
        ]);
    }

    private function webInquiry(User $user, string $createdAt): void
    {
        $message = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => self::SENTINEL,
            'is_read' => true,
        ]);
        $message->forceFill(['created_at' => $createdAt])->save();
    }
}
