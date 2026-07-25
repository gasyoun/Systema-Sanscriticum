<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Models\Course;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\Schedule;
use App\Services\Content\ContentMonthSeeder;
use App\Services\Content\ForwardDraftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForwardDraftFillTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): string
    {
        return base_path('tests/fixtures/vk_ors');
    }

    public function test_fill_forward_drafts_empty_slots_without_scheduling(): void
    {
        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());

        $before = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
            ->where('status', ContentCalendarSlot::STATUS_EMPTY)
            ->count();
        $this->assertGreaterThan(0, $before);

        $this->artisan('content:fill-forward', [
            'month' => '2026-09',
            '--force-flag' => true,
        ])->assertSuccessful();

        $drafted = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
            ->where('status', ContentCalendarSlot::STATUS_DRAFT)
            ->get();

        $this->assertGreaterThan(0, $drafted->count());
        $this->assertSame(0, ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->count(), 'D12: forward NEW copy must never auto-schedule');

        foreach ($drafted as $slot) {
            $this->assertNotNull($slot->body);
            $this->assertNull($slot->publish_at);
            $this->assertNotNull($slot->meta['forward_kind'] ?? null);

            $candidate = ContentCandidate::query()
                ->where('calendar_slot_id', $slot->id)
                ->where('type', ContentCandidate::TYPE_VK_POST)
                ->first();
            $this->assertNotNull($candidate);
            $this->assertSame(ContentCandidate::STATUS_DRAFT, $candidate->status);
            $this->assertSame($slot->body, $candidate->body);
        }
    }

    public function test_fill_forward_rotates_all_four_kinds(): void
    {
        ContentCalendarSlot::insert(array_map(fn (int $i) => [
            'channel' => 'vk_wall',
            'slot_date' => "2026-09-0{$i}",
            'slot_type' => ContentCalendarSlot::TYPE_FORWARD,
            'status' => ContentCalendarSlot::STATUS_EMPTY,
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 4)));

        $this->artisan('content:fill-forward', [
            'month' => '2026-09',
            '--force-flag' => true,
        ])->assertSuccessful();

        $kinds = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->pluck('meta')
            ->map(fn ($meta) => $meta['forward_kind'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($kinds);
        $expected = ForwardDraftGenerator::kinds();
        sort($expected);
        $this->assertSame($expected, $kinds);
    }

    public function test_dictionary_tip_uses_real_word_when_present(): void
    {
        $dictionary = Dictionary::create(['name' => 'Test Dict', 'is_active' => true]);
        DictionaryWord::create([
            'dictionary_id' => $dictionary->id,
            'devanagari' => 'सत्',
            'iast' => 'sat',
            'translation' => 'истина, бытие',
        ]);

        ContentCalendarSlot::create([
            'slot_date' => '2026-09-05',
            'slot_type' => ContentCalendarSlot::TYPE_FORWARD,
            'status' => ContentCalendarSlot::STATUS_EMPTY,
        ]);

        // Force only the dictionary_tip kind by isolating a single slot at index 1.
        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('dictionary_tip', seed: 1);

        $this->assertNotNull($draft);
        $this->assertSame('dictionary_word', $draft['meta']['source']);
        $this->assertStringContainsString('सत्', $draft['body']);
        $this->assertStringContainsString('истина', $draft['body']);
    }

    public function test_dictionary_tip_falls_back_when_no_words(): void
    {
        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('dictionary_tip', seed: 0);

        $this->assertNotNull($draft);
        $this->assertSame('template', $draft['meta']['source']);
    }

    public function test_event_promo_uses_upcoming_schedule_when_present(): void
    {
        $course = Course::factory()->create(['title' => 'Санскрит для начинающих']);
        Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'start' => now()->addDays(3),
        ]);

        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('event_promo');

        $this->assertNotNull($draft);
        $this->assertSame('schedule', $draft['meta']['source']);
        $this->assertStringContainsString('Санскрит для начинающих', $draft['body']);
    }

    public function test_event_promo_falls_back_when_no_upcoming_schedule(): void
    {
        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('event_promo');

        $this->assertNotNull($draft);
        $this->assertSame('template', $draft['meta']['source']);
    }

    public function test_faq_micro_answer_uses_safe_faq_section(): void
    {
        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('faq_micro_answer', seed: 0);

        $this->assertNotNull($draft);
        $this->assertSame('faq_knowledge_base', $draft['meta']['source']);
        // Never a payment/policy-section entry (D24 fence spirit: no money copy).
        $this->assertStringNotContainsString('Оплата', $draft['title']);
    }

    public function test_curator_ai_polish_used_when_key_configured(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'CuratorAi-polished text']]],
                'model' => 'test-model',
            ]),
        ]);

        $generator = app(ForwardDraftGenerator::class);
        $draft = $generator->draft('reading_group_tease');

        $this->assertSame('CuratorAi-polished text', $draft['body']);
    }

    public function test_fill_forward_noops_when_flag_off_without_force(): void
    {
        config(['features.content_calendar' => false]);

        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());

        $this->artisan('content:fill-forward', ['month' => '2026-09'])
            ->assertSuccessful();

        // Scoped to forward slots: W1's seeder already leaves evergreen slots
        // in status=draft regardless of this command, so a blanket count would
        // false-positive.
        $this->assertSame(
            0,
            ContentCalendarSlot::query()
                ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
                ->where('status', ContentCalendarSlot::STATUS_DRAFT)
                ->count()
        );
    }

    public function test_fill_forward_respects_limit_option(): void
    {
        $seeder = new ContentMonthSeeder;
        $seeder->seed(2026, 9, $this->fixtures());

        $this->artisan('content:fill-forward', [
            'month' => '2026-09',
            '--force-flag' => true,
            '--limit' => 1,
        ])->assertSuccessful();

        $drafted = ContentCalendarSlot::query()
            ->forMonth(2026, 9)
            ->where('slot_type', ContentCalendarSlot::TYPE_FORWARD)
            ->where('status', ContentCalendarSlot::STATUS_DRAFT)
            ->count();

        $this->assertSame(1, $drafted);
    }

    public function test_fill_forward_noops_when_no_empty_forward_slots(): void
    {
        $this->artisan('content:fill-forward', [
            'month' => '2026-09',
            '--force-flag' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ContentCalendarSlot::count());
    }
}
