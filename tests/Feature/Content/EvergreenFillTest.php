<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Content\ContentMonthSeeder;
use App\Services\Content\EvergreenScorer;
use App\Services\Content\VkOrsImporter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EvergreenFillTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): string
    {
        return base_path('tests/fixtures/vk_ors');
    }

    public function test_scorer_filters_by_age_topic_and_promo(): void
    {
        $importer = new VkOrsImporter;
        $rows = $importer->readCsv($this->fixtures(), 'top_posts_by_likes.csv');

        $scorer = new EvergreenScorer;
        $scored = $scorer->score($rows, new DateTimeImmutable('2026-07-25'));

        $ids = array_column($scored, 'id');

        // 23448: on-topic-free promo excerpt ("записаться на онлайн-курс ... с сертификатом") — excluded.
        $this->assertNotContains('23448', $ids);
        // 25187: < 12 months old ("2026-01-25") — excluded.
        $this->assertNotContains('25187', $ids);
        // 14290/16758/19165/90001/90002/90003: old enough + on-topic — included.
        // (19165 matches "текст" via "пуран" in "Вишнудхармоттарапураны" — a genuine
        // Purana-text reference, not a false positive.)
        $this->assertContains('14290', $ids);
        $this->assertContains('16758', $ids);
        $this->assertContains('19165', $ids);
        $this->assertContains('90001', $ids);
        $this->assertContains('90002', $ids);
        $this->assertContains('90003', $ids);
        // 90004: old, non-promo, but no topic keyword match — excluded.
        $this->assertNotContains('90004', $ids);

        // Ranked likes DESC.
        $likes = array_column($scored, 'likes');
        $sorted = $likes;
        rsort($sorted);
        $this->assertSame($sorted, $likes);
    }

    public function test_scorer_excludes_already_used_source_refs(): void
    {
        $importer = new VkOrsImporter;
        $rows = $importer->readCsv($this->fixtures(), 'top_posts_by_likes.csv');

        $scorer = new EvergreenScorer;
        $scored = $scorer->score($rows, new DateTimeImmutable('2026-07-25'), ['90001']);

        $this->assertNotContains('90001', array_column($scored, 'id'));
    }

    public function test_fill_evergreen_schedules_slots_and_candidates(): void
    {
        // Часы заморожены намеренно. Тест засевает сентябрь, а ниже требует
        // `canCancel()` — отменить слот можно лишь дольше чем за 24 часа до
        // публикации. На реальных часах это значит, что тест краснел ровно в
        // последние сутки августа и снова зеленел 1 сентября, безотносительно
        // к коду. Соседние тесты этого файла дату уже пиняют (`2026-07-25`);
        // здесь её просто забыли.
        $this->travelTo(Carbon::create(2026, 8, 1, 9, 0, 0, 'Europe/Moscow'));

        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());

        $before = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('slot_type', ContentCalendarSlot::TYPE_EVERGREEN)
            ->where('status', ContentCalendarSlot::STATUS_DRAFT)
            ->count();
        $this->assertGreaterThan(0, $before);

        $this->artisan('content:fill-evergreen', [
            'month' => '2026-09',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();

        $scheduled = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('slot_type', ContentCalendarSlot::TYPE_EVERGREEN)
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->get();

        // 6 fixture rows pass the scorer's filters (see test above); some evergreen
        // slots stay draft if the seeder created more than that.
        $this->assertGreaterThan(0, $scheduled->count());
        $this->assertLessThanOrEqual(6, $scheduled->count());

        foreach ($scheduled as $slot) {
            $this->assertNotNull($slot->body);
            $this->assertNotNull($slot->publish_at);
            $this->assertSame('vk_ors_post', $slot->source_kind);
            $this->assertNotNull($slot->source_ref);
            $this->assertTrue($slot->canCancel());

            $candidate = ContentCandidate::query()
                ->where('calendar_slot_id', $slot->id)
                ->where('type', ContentCandidate::TYPE_VK_POST)
                ->first();
            $this->assertNotNull($candidate);
            $this->assertSame(ContentCandidate::STATUS_SCHEDULED, $candidate->status);
            $this->assertSame($slot->body, $candidate->body);
        }

        // No duplicate source_ref across scheduled slots.
        $refs = $scheduled->pluck('source_ref')->all();
        $this->assertSame($refs, array_unique($refs));
    }

    public function test_fill_evergreen_is_idempotent_and_deduplicates_across_runs(): void
    {
        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());
        $seeder->seed(2026, 10, $this->fixtures());

        $this->artisan('content:fill-evergreen', [
            'month' => '2026-09',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();

        $septemberRefs = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->pluck('source_ref')
            ->all();

        // Running again for September changes nothing (no more draft evergreen slots
        // left, or none left to fill) — status counts are stable.
        $beforeScheduled = ContentCalendarSlot::query()->where('status', ContentCalendarSlot::STATUS_SCHEDULED)->count();
        $this->artisan('content:fill-evergreen', [
            'month' => '2026-09',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();
        $afterScheduled = ContentCalendarSlot::query()->where('status', ContentCalendarSlot::STATUS_SCHEDULED)->count();
        $this->assertSame($beforeScheduled, $afterScheduled);

        // October fill must not reuse a source post already scheduled for September
        // (de-dupe window is ±6 months of the target month).
        $this->artisan('content:fill-evergreen', [
            'month' => '2026-10',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();

        $octoberRefs = ContentCalendarSlot::query()
            ->forMonth(2026, 10)
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->pluck('source_ref')
            ->all();

        $this->assertEmpty(array_intersect($septemberRefs, $octoberRefs));
    }

    public function test_fill_evergreen_noops_when_flag_off_without_force(): void
    {
        config(['features.content_calendar' => false]);

        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());

        $this->artisan('content:fill-evergreen', ['month' => '2026-09', '--path' => $this->fixtures()])
            ->assertSuccessful();

        $this->assertSame(
            0,
            ContentCalendarSlot::query()->where('status', ContentCalendarSlot::STATUS_SCHEDULED)->count()
        );
    }

    public function test_fill_evergreen_noops_when_no_draft_evergreen_slots(): void
    {
        $this->artisan('content:fill-evergreen', [
            'month' => '2026-09',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ContentCalendarSlot::count());
    }
}
