<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Services\Content\ContentMonthSeeder;
use App\Services\Content\VkOrsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentCalendarSeedTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): string
    {
        return base_path('tests/fixtures/vk_ors');
    }

    public function test_importer_reads_fixture_activity(): void
    {
        $importer = new VkOrsImporter;
        $rows = $importer->readCsv($this->fixtures(), 'activity_by_month.csv');
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('ym', $rows[0]);
        $this->assertArrayHasKey('posts', $rows[0]);
    }

    public function test_seed_month_creates_at_least_20_slots(): void
    {
        $seeder = new ContentMonthSeeder;
        $result = $seeder->seed(2026, 9, $this->fixtures());

        $this->assertGreaterThanOrEqual(20, $result['target']);
        $this->assertSame($result['target'], $result['created']);
        $this->assertSame(
            $result['created'],
            ContentCalendarSlot::query()->forMonth(2026, 9)->count()
        );
        $this->assertGreaterThan(
            0,
            ContentCandidate::query()->where('type', ContentCandidate::TYPE_VK_POST)->count()
        );
    }

    public function test_seed_month_is_idempotent_when_target_met(): void
    {
        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());
        $second = $seeder->seed(2026, 9, $this->fixtures());

        $this->assertSame(0, $second['created']);
        $this->assertGreaterThanOrEqual(20, $second['skipped']);
    }

    public function test_keep_sets_scheduled_and_publish_at(): void
    {
        $slot = ContentCalendarSlot::create([
            'slot_date' => '2026-09-15',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_DRAFT,
            'title' => 'Test',
        ]);

        $slot->markKept();
        $slot->refresh();

        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status);
        $this->assertNotNull($slot->publish_at);
        $this->assertTrue($slot->canCancel());
    }

    public function test_cancel_blocked_inside_last_24_hours(): void
    {
        $slot = ContentCalendarSlot::create([
            'slot_date' => '2026-09-15',
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => now('Europe/Moscow')->addHours(12),
        ]);

        $this->assertFalse($slot->canCancel());
    }

    public function test_artisan_seed_noops_when_flag_off_without_force(): void
    {
        config(['features.content_calendar' => false]);

        $this->artisan('content:seed-month', ['month' => '2026-09', '--path' => $this->fixtures()])
            ->assertSuccessful();

        $this->assertSame(0, ContentCalendarSlot::count());
    }

    public function test_artisan_seed_with_force_flag(): void
    {
        config(['features.content_calendar' => false]);

        $this->artisan('content:seed-month', [
            'month' => '2026-09',
            '--path' => $this->fixtures(),
            '--force-flag' => true,
        ])->assertSuccessful();

        $this->assertGreaterThanOrEqual(20, ContentCalendarSlot::count());
    }

    public function test_import_command_copies_to_storage(): void
    {
        $this->artisan('content:import-vk-ors', [
            '--path' => $this->fixtures(),
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFileExists(storage_path('app/vk_ors/activity_by_month.csv'));
    }
}
