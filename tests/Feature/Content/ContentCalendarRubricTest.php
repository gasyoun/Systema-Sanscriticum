<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Filament\Resources\ContentCalendarRubricResource;
use App\Filament\Resources\ContentCalendarSlotResource;
use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\RubricScheduler;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Рубрики календаря (28-10-2026): расписание + очередь; пост без даты встаёт
 * на ближайшее свободное время рубрики.
 */
class ContentCalendarRubricTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Среда, 28.10.2026, 13:00 МСК.
        Carbon::setTestNow(Carbon::parse('2026-10-28 13:00:00', 'Europe/Moscow'));
        config([
            'features.content_calendar' => true,
            'content_calendar.destinations' => [
                'tg_a' => ['platform' => 'telegram', 'label' => 'TG A', 'chat_id' => '@a'],
                'tg_story' => ['platform' => 'tg_business_story', 'label' => 'Сторис', 'enabled' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rubric(array $attrs = []): ContentCalendarRubric
    {
        return ContentCalendarRubric::create($attrs + [
            'name' => 'Анонс курсов',
            'prefix' => '🗣 Анонс курсов · ',
            'schedule' => [['days' => [1, 3, 5], 'time' => '18:00']],
        ]);
    }

    private function rubricPost(ContentCalendarRubric $rubric, string $at, string $status = ContentCalendarSlot::STATUS_SCHEDULED): ContentCalendarSlot
    {
        return ContentCalendarSlot::create([
            'slot_date' => substr($at, 0, 10),
            'slot_type' => ContentCalendarSlot::TYPE_MANUAL,
            'rubric_id' => $rubric->id,
            'status' => $status,
            'publish_at' => $at,
            'title' => 'p',
            'body' => '<p>x</p>',
        ]);
    }

    public function test_occurrences_follow_days_and_times_across_the_week(): void
    {
        $rubric = $this->rubric(['schedule' => [
            ['days' => ['1', '3', '5'], 'time' => '18:00'],
            ['days' => [3], 'time' => '12:00'],
        ]]);

        $at = array_map(
            static fn (Carbon $c): string => $c->format('D d.m H:i'),
            app(RubricScheduler::class)->occurrences($rubric, now(), 5),
        );

        // Среда 12:00 уже прошла.
        $this->assertSame(['Wed 28.10 18:00', 'Fri 30.10 18:00', 'Mon 02.11 18:00', 'Wed 04.11 12:00', 'Wed 04.11 18:00'], $at);
        $this->assertSame('пн, ср, пт 18:00; ср 12:00', $rubric->scheduleLabel());
    }

    public function test_next_free_skips_taken_runs_and_reuses_canceled_ones(): void
    {
        $rubric = $this->rubric();
        $scheduler = app(RubricScheduler::class);

        $this->assertSame('2026-10-28 18:00', $scheduler->nextFreeAt($rubric)?->format('Y-m-d H:i'));

        $this->rubricPost($rubric, '2026-10-28 18:00:00');
        $this->rubricPost($rubric, '2026-10-30 18:00:00', ContentCalendarSlot::STATUS_CANCELED);
        $this->assertSame('2026-10-30 18:00', $scheduler->nextFreeAt($rubric)?->format('Y-m-d H:i'));

        // Другая рубрика на то же время не мешает.
        $this->rubricPost($this->rubric(['name' => 'Другая']), '2026-10-30 18:00:00');
        $this->assertSame('2026-10-30 18:00', $scheduler->nextFreeAt($rubric)?->format('Y-m-d H:i'));

        $this->assertNull($scheduler->nextFreeAt($this->rubric(['name' => 'Пустая', 'schedule' => []])));
    }

    public function test_reflow_packs_unpublished_posts_onto_consecutive_runs(): void
    {
        $rubric = $this->rubric();
        $a = $this->rubricPost($rubric, '2026-11-02 18:00:00');
        $b = $this->rubricPost($rubric, '2026-11-06 18:00:00');
        $done = $this->rubricPost($rubric, '2026-10-26 18:00:00', ContentCalendarSlot::STATUS_PUBLISHED);

        $this->assertSame(2, app(RubricScheduler::class)->reflow($rubric));

        $this->assertSame('2026-10-28 18:00', $a->refresh()->publish_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-28', $a->slot_date->toDateString());
        $this->assertSame('2026-10-30 18:00', $b->refresh()->publish_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 18:00', $done->refresh()->publish_at->format('Y-m-d H:i'));
    }

    public function test_prefix_is_added_once(): void
    {
        $rubric = $this->rubric();

        $this->assertSame('<p>🗣 Анонс курсов · <strong>Нала</strong></p><p>текст</p>', $rubric->applyPrefix('<p><strong>Нала</strong></p><p>текст</p>'));
        $this->assertSame('<p>🗣 Анонс курсов · Нала</p>', $rubric->applyPrefix('<p>🗣 Анонс курсов · Нала</p>'));
        $this->assertSame(0, $rubric->prefixLength('<p>🗣 Анонс курсов · Нала</p>'));
        $this->assertSame(18, $rubric->prefixLength('<p>Нала</p>'));
    }

    public function test_post_without_date_lands_on_next_free_run_with_prefix_and_story(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric();
        $this->rubricPost($rubric, '2026-10-28 18:00:00');

        Livewire::test(ContentCalendarSlotResource\Pages\CreateContentCalendarSlot::class)
            ->fillForm([
                'rubric_id' => $rubric->id,
                'title' => 'Нала',
                'body' => '<p><strong>Сказания о Нале</strong></p><p>Идёт набор.</p>',
            ])
            ->assertFormSet(['publish_at' => null])
            ->set('data.media_path', UploadedFile::fake()->image('cover.jpg', 1080, 1350))
            ->call('create')
            ->assertHasNoFormErrors();

        $slot = ContentCalendarSlot::query()->where('title', 'Нала')->sole();
        $this->assertSame($rubric->id, $slot->rubric_id);
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status);
        $this->assertSame('2026-10-30 18:00', $slot->publish_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-30', $slot->slot_date->toDateString());
        $this->assertStringStartsWith('<p>🗣 Анонс курсов · <strong>Сказания о Нале</strong>', (string) $slot->body);

        $this->assertStringStartsWith('content-calendar/stories/', (string) $slot->story_media_path);
        $story = imagecreatefromstring(Storage::disk(ContentCalendarSlot::MEDIA_DISK)->get($slot->story_media_path));
        $this->assertSame([1080, 1920], [imagesx($story), imagesy($story)]);
        $this->assertContains('tg_story', $slot->targetDestinations());
    }

    public function test_manual_date_wins_and_rubric_without_story_makes_none(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric(['with_story' => false]);

        Livewire::test(ContentCalendarSlotResource\Pages\CreateContentCalendarSlot::class)
            ->fillForm(['rubric_id' => $rubric->id])
            ->fillForm([
                'publish_at' => '2026-11-11 09:15:00',
                'title' => 'Ручной',
                'body' => '<p>текст</p>',
            ])
            ->set('data.media_path', UploadedFile::fake()->image('cover.jpg'))
            ->call('create')
            ->assertHasNoFormErrors();

        $slot = ContentCalendarSlot::query()->where('title', 'Ручной')->sole();
        $this->assertSame('2026-11-11 09:15', $slot->publish_at->format('Y-m-d H:i'));
        $this->assertNull($slot->story_media_path);
    }

    public function test_prefix_counts_toward_the_caption_limit(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric();

        Livewire::test(ContentCalendarSlotResource\Pages\CreateContentCalendarSlot::class)
            ->fillForm([
                'rubric_id' => $rubric->id,
                'title' => 'Длинный',
                'body' => '<p>'.str_repeat('а', 1010).'</p>',
            ])
            ->set('data.media_path', UploadedFile::fake()->image('cover.jpg'))
            ->call('create')
            ->assertHasFormErrors(['media_path']);
    }

    public function test_schedule_change_reflows_the_queue(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric();
        $slot = $this->rubricPost($rubric, '2026-10-30 18:00:00');

        Livewire::test(ContentCalendarRubricResource\Pages\EditContentCalendarRubric::class, ['record' => $rubric->getRouteKey()])
            ->assertSuccessful()
            ->set('data.schedule', [['days' => ['4'], 'time' => '10:00']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-10-29 10:00', $slot->refresh()->publish_at->format('Y-m-d H:i'));
    }

    public function test_rubric_list_renders(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $this->rubric();

        Livewire::test(ContentCalendarRubricResource\Pages\ListContentCalendarRubrics::class)
            ->assertSuccessful()
            ->assertSee('пн, ср, пт 18:00')
            ->assertSee('28.10.2026 18:00');
    }

    private function schoolDestinations(): void
    {
        config(['content_calendar.destinations' => [
            'tg_opoveshchenie' => ['platform' => 'telegram', 'label' => 'TG · Оповещение', 'chat_id' => '@o'],
            'tg_ors' => ['platform' => 'telegram', 'label' => 'TG · ОРС', 'chat_id' => '@r'],
            'tg_institut' => ['platform' => 'telegram', 'label' => 'TG · Институт', 'chat_id' => '@i'],
            'vk_opoveshchenie' => ['platform' => 'vk', 'label' => 'VK · Оповещение', 'group_id' => 1],
            'vk_ors' => ['platform' => 'vk', 'label' => 'VK · ОРС', 'group_id' => 0],
            'tg_story_business' => ['platform' => 'tg_business_story', 'label' => 'Сторис · TG', 'enabled' => true],
            'vk_story_opoveshchenie' => ['platform' => 'vk_story', 'label' => 'Сторис · VK Оповещение', 'group_id' => 1],
        ]]);
    }

    public function test_channels_expand_to_tg_vk_and_stories_of_enabled_destinations(): void
    {
        $this->schoolDestinations();

        $rubric = $this->rubric(['channels' => ['opoveshchenie', 'ors']]);
        $this->assertSame(['tg_opoveshchenie', 'tg_ors', 'vk_opoveshchenie', 'tg_story_business', 'vk_story_opoveshchenie'], $rubric->destinationKeys());
        $this->assertSame('Оповещение, ОРС', $rubric->channelsLabel());

        $noStory = $this->rubric(['name' => 'Без сторис', 'channels' => ['institut'], 'with_story' => false]);
        $this->assertSame(['tg_institut'], $noStory->destinationKeys());

        $this->assertNull($this->rubric(['name' => 'Без каналов'])->destinationKeys());

        $this->assertSame(
            'Оповещение: TG, VK, сторис VK · ОРС: TG · сторис TG',
            ContentCalendarSlot::destinationSummary(['tg_opoveshchenie', 'tg_ors', 'vk_opoveshchenie', 'vk_story_opoveshchenie', 'tg_story_business']),
        );
    }

    public function test_choosing_a_rubric_ticks_its_channels_and_list_shows_where_the_post_goes(): void
    {
        $this->schoolDestinations();
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric(['channels' => ['ors'], 'with_story' => false]);

        Livewire::test(ContentCalendarSlotResource\Pages\CreateContentCalendarSlot::class)
            ->fillForm(['rubric_id' => $rubric->id])
            ->assertFormSet(['destinations' => ['tg_ors']])
            ->fillForm(['title' => 'ОРС пост', 'body' => '<p>текст</p>'])
            ->call('create')
            ->assertHasNoFormErrors();

        $slot = ContentCalendarSlot::query()->where('title', 'ОРС пост')->sole();
        $this->assertSame(['tg_ors'], $slot->destinations);

        Livewire::test(ContentCalendarSlotResource\Pages\ListContentCalendarSlots::class)
            ->assertSuccessful()
            ->assertSee('ОРС: TG');
    }

    private function draft(ContentCalendarRubric $rubric, string $date, array $attrs = []): ContentCalendarSlot
    {
        return ContentCalendarSlot::create($attrs + [
            'slot_date' => $date,
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'rubric_id' => $rubric->id,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'source_kind' => 'copy_packet',
            'title' => 'черновик '.$date,
            'body' => '<p><strong>Ловушка</strong></p>',
        ]);
    }

    /** Импортированные черновики рубрики (copy_packet, 31-10-2026) — в очередь кнопкой рубрики. */
    public function test_rubric_drafts_are_enqueued_in_slot_date_order_with_prefix_and_story(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric();
        $this->rubricPost($rubric, '2026-10-28 18:00:00');
        $cover = UploadedFile::fake()->image('cover.jpg', 1080, 1350)->store('content-calendar', ContentCalendarSlot::MEDIA_DISK);
        $late = $this->draft($rubric, '2026-11-25', ['media_path' => $cover]);
        $early = $this->draft($rubric, '2026-11-11');
        $done = $this->rubricPost($rubric, '2026-10-26 18:00:00', ContentCalendarSlot::STATUS_PUBLISHED);

        Livewire::test(ContentCalendarRubricResource\Pages\ListContentCalendarRubrics::class)
            ->assertSee('Ждут (черновики)')
            ->callTableAction('enqueueDrafts', $rubric)
            ->assertNotified('Поставлено в очередь: 2');

        $early->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $early->status);
        $this->assertSame('2026-10-30 18:00', $early->publish_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-30', $early->slot_date->toDateString());
        $this->assertStringStartsWith('<p>🗣 Анонс курсов · ', (string) $early->body);
        $this->assertNull($early->story_media_path);

        $late->refresh();
        $this->assertSame('2026-11-02 18:00', $late->publish_at->format('Y-m-d H:i'));
        $this->assertStringStartsWith('content-calendar/stories/', (string) $late->story_media_path);

        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $done->refresh()->status);
    }

    public function test_single_and_bulk_enqueue_from_the_slot_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $rubric = $this->rubric();
        $a = $this->draft($rubric, '2026-11-11');
        $b = $this->draft($rubric, '2026-11-12');
        $c = $this->draft($rubric, '2026-11-13');
        $noRubric = ContentCalendarSlot::create(['slot_date' => '2026-11-14', 'status' => ContentCalendarSlot::STATUS_DRAFT, 'title' => 'без рубрики']);

        $list = Livewire::test(ContentCalendarSlotResource\Pages\ListContentCalendarSlots::class)
            ->assertTableActionHidden('enqueue', $noRubric)
            ->callTableAction('enqueue', $b)
            ->assertNotified('Пост в очереди: 28.10.2026 18:00');
        $list->assertTableActionHidden('enqueue', $b->refresh())
            ->callTableBulkAction('enqueue', [$c, $a, $noRubric])
            ->assertNotified('Поставлено в очередь: 2');

        $this->assertSame('2026-10-30 18:00', $a->refresh()->publish_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-02 18:00', $c->refresh()->publish_at->format('Y-m-d H:i'));
        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $noRubric->refresh()->status);
    }

    public function test_gap_alert_points_to_waiting_drafts(): void
    {
        $sent = [];
        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('notifyAdmins')->andReturnUsing(function (string $text) use (&$sent): array {
                $sent[] = $text;

                return [];
            });
        });
        $rubric = $this->rubric();
        $this->draft($rubric, '2026-11-11');

        $this->artisan('content:rubric-gaps')->assertSuccessful();

        $this->assertStringContainsString('1 черновиков', $sent[0]);
    }

    public function test_gap_alert_is_sent_once_per_run(): void
    {
        $sent = [];
        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('notifyAdmins')->andReturnUsing(function (string $text) use (&$sent): array {
                $sent[] = $text;

                return [];
            });
        });
        $rubric = $this->rubric();
        $this->rubric(['name' => 'Выключенная', 'is_active' => false]);

        $this->artisan('content:rubric-gaps')->assertSuccessful();
        $this->artisan('content:rubric-gaps')->assertSuccessful();

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('«Анонс курсов»', $sent[0]);
        $this->assertStringContainsString('28.10 18:00', $sent[0]);

        // Пост на этот выход есть — молчит.
        $this->rubricPost($rubric, '2026-10-28 18:00:00');
        $rubric->forceFill(['last_empty_alert_for' => null])->save();
        $this->artisan('content:rubric-gaps')->assertSuccessful();
        $this->assertCount(1, $sent);
    }
}
