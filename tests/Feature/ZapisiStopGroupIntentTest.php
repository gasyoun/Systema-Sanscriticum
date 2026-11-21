<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Telegram\StopGroupCallbackService;
use App\Services\Telegram\StopGroupIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H8526: «мёртвая группа». Точные фразы останова от учителя/менеджера →
 * кнопки [Серия окончена] [Каникулы] [Не останавливать]; применение — только
 * явный тап; студентам и незнакомцам — тишина; kicked/left → автоархив без
 * прощального поста; reminder в archived-группу не уходит.
 */
class ZapisiStopGroupIntentTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100777';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]]),
        ]);
        config()->set('services.telegram.student_bot_token', 'test-token');
        config()->set('services.telegram.bot_token', 'admin-token');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'message_id' => 910,
            'from' => ['id' => 1001],
            'text' => 'стоп',
        ], $overrides);
    }

    private function seedTeacherWorld(string $role = 'teacher', int $telegramId = 1001): Group
    {
        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Гр.57', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        User::create([
            'name' => 'Препод',
            'email' => 'own-'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
            'role' => $role,
            'teacher_id' => $role === 'teacher' ? $teacher->id : null,
            'telegram_id' => $telegramId,
        ]);

        return $group;
    }

    private function tapCallback(string $data, int $telegramId = 1001): array
    {
        return [
            'id' => 'cbq1',
            'data' => $data,
            'from' => ['id' => $telegramId],
            'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
        ];
    }

    public function test_silent_for_unrecognized_sender(): void
    {
        $group = Group::create(['name' => 'Гр.57', 'telegram_chat_id' => self::CHAT_ID]);

        app(StopGroupIntentService::class)->handle($this->message(['from' => ['id' => 424242]]));

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame('forming', $group->fresh()->status);
    }

    public function test_teacher_gets_stop_buttons_and_nothing_applies(): void
    {
        $group = $this->seedTeacherWorld();

        app(StopGroupIntentService::class)->handle($this->message());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) $request['text'], 'Остановить бота')
            && str_contains((string) $request['reply_markup'], 'srx:'.$group->id)
            && str_contains((string) $request['reply_markup'], 'srv:'.$group->id));
        $this->assertNotSame('archived', $group->fresh()->status);
        $this->assertFalse((bool) $group->fresh()->is_on_vacation);
    }

    public function test_variants_of_stop_phrase_match(): void
    {
        $this->seedTeacherWorld();

        foreach (['серия окончена', 'отмена всех занятий', 'группа закрыта', 'занятий больше нет'] as $phrase) {
            Http::fake([
                'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]]),
            ]);
            app(StopGroupIntentService::class)->handle($this->message(['text' => $phrase, 'message_id' => random_int(1, 999999)]));
        }

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendMessage'));
    }

    public function test_archive_applied_on_tap_by_teacher(): void
    {
        $group = $this->seedTeacherWorld();

        $handled = app(StopGroupCallbackService::class)->handle($this->tapCallback('srx:'.$group->id));

        $this->assertTrue($handled);
        $this->assertSame('archived', $group->fresh()->status);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => str_contains($job->text, 'Бот остановлен'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'editMessageText'));
    }

    public function test_pause_tap_sets_open_vacation(): void
    {
        $group = $this->seedTeacherWorld();

        app(StopGroupCallbackService::class)->handle($this->tapCallback('srv:'.$group->id));

        $this->assertTrue((bool) $group->fresh()->is_on_vacation);
        $this->assertNull($group->fresh()->vacation_resume_date);
        $this->assertNotSame('archived', $group->fresh()->status);
    }

    public function test_keep_tap_changes_nothing(): void
    {
        $group = $this->seedTeacherWorld();

        app(StopGroupCallbackService::class)->handle($this->tapCallback('srxn'));

        $this->assertSame('forming', $group->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_foreign_tap_rejected(): void
    {
        $group = $this->seedTeacherWorld();
        User::create([
            'name' => 'Чужой Препод',
            'email' => 'other-'.uniqid().'@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'teacher_id' => Teacher::create(['name' => 'Препод Чужой'])->id,
            'telegram_id' => 3003,
        ]);

        app(StopGroupCallbackService::class)->handle($this->tapCallback('srx:'.$group->id, 3003));

        $this->assertSame('forming', $group->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_kick_auto_archives_without_farewell(): void
    {
        config(['services.telegram_harvest.store_path' => storage_path('framework/testing/zapisi-'.uniqid())]);
        $group = $this->seedTeacherWorld();

        (new ProcessTelegramZapisiUpdate([
            'my_chat_member' => [
                'chat' => ['id' => (int) self::CHAT_ID],
                'new_chat_member' => ['status' => 'kicked'],
            ],
        ]))->handle();

        $this->assertSame('archived', $group->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_readding_bot_does_not_resurrect(): void
    {
        config(['services.telegram_harvest.store_path' => storage_path('framework/testing/zapisi-'.uniqid())]);
        $group = $this->seedTeacherWorld();
        $group->status = Group::STATUS_ARCHIVED;
        $group->save();

        (new ProcessTelegramZapisiUpdate([
            'my_chat_member' => [
                'chat' => ['id' => (int) self::CHAT_ID],
                'new_chat_member' => ['status' => 'member'],
            ],
        ]))->handle();

        $this->assertSame('archived', $group->fresh()->status);
    }

    public function test_reminder_skipped_for_archived_group(): void
    {
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_reminder_lead_minutes' => 60]);

        $group = Group::create(['name' => 'Гр.57', 'telegram_chat_id' => '-10057', 'status' => 'archived']);
        $schedule = Schedule::create([
            'title' => 'Грамматика по Кочергиной',
            'start' => now()->addMinutes(10),
            'group_id' => $group->id,
            'zoom_join_url' => 'https://zoom.us/j/57',
        ]);

        $this->artisan('zapisi:remind-classes')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($schedule->fresh()->zapisi_reminded_at);
    }
}
