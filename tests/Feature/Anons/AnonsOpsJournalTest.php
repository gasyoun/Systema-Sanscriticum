<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\AnonsLinkClick;
use App\Models\AnonsPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6095: anons:ops journal-add / journal-fill / journal-list — машинный
 * журнал размещений. Ключи и UTM выводятся из config/tracked_links.php
 * (в тестах — фикстурный набор + один canary на реальных ключах up26).
 */
final class AnonsOpsJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tracked_links.links', [
            'up26-ors-c' => [
                'destination' => '/webinar-upanishady-2026',
                'utm' => [
                    'utm_source' => 'telegram_samskrte',
                    'utm_medium' => 'owned_channel',
                    'utm_campaign' => 'upanishady_webinar_oct_2026',
                    'utm_content' => 'u26_c',
                    'utm_term' => 'philosophy',
                ],
            ],
            'up26-ors-p' => [
                'destination' => '/k/tolkovaniia-upanisad-2-potok-2026',
                'utm' => [
                    'utm_source' => 'telegram_samskrte',
                    'utm_medium' => 'owned_channel',
                    'utm_campaign' => 'upanishady_webinar_oct_2026',
                    'utm_content' => 'u26_p',
                    'utm_term' => 'philosophy',
                ],
            ],
        ]);
    }

    public function test_journal_add_derives_placement_from_config(): void
    {
        $this->artisan('anons:ops', ['op' => 'journal-add', '--link' => 'up26-ors-c'])
            ->assertExitCode(0);

        $row = AnonsPlacement::query()->where('link', 'up26-ors-c')->firstOrFail();
        $this->assertSame('up26', $row->campaign);
        $this->assertSame('c', $row->creative);
        $this->assertSame('ors', $row->channel);
        $this->assertSame('/webinar-upanishady-2026', $row->destination);
        $this->assertSame('u26_c', $row->utm['utm_content']);
    }

    public function test_journal_add_is_idempotent_by_link(): void
    {
        foreach ([1, 2] as $run) {
            $this->artisan('anons:ops', ['op' => 'journal-add', '--link' => 'up26-ors-p'])
                ->assertExitCode(0);
        }

        $this->assertSame(1, AnonsPlacement::query()->where('link', 'up26-ors-p')->count());
    }

    public function test_journal_add_campaign_bulk_and_story_keys_refused(): void
    {
        $this->artisan('anons:ops', ['op' => 'journal-add', '--campaign' => 'up26'])
            ->assertExitCode(0);
        $this->assertSame(2, AnonsPlacement::query()->count());

        config()->set('tracked_links.links', array_merge(config('tracked_links.links'), [
            'up26-ors-st-gita-20261005-01' => ['destination' => '/x', 'utm' => []],
        ]));
        $this->artisan('anons:ops', ['op' => 'journal-add', '--link' => 'up26-ors-st-gita-20261005-01'])
            ->assertExitCode(1);
        $this->assertSame(2, AnonsPlacement::query()->count());
    }

    public function test_journal_fill_sets_tail_and_counts_click_windows(): void
    {
        $this->artisan('anons:ops', ['op' => 'journal-add', '--link' => 'up26-ors-c'])
            ->assertExitCode(0);

        $published = Carbon::parse('2026-10-04 22:43:00');
        // внутри 24h — 2; ровно +24h — 1 (включительная граница); внутри 72h — 1;
        // ровно +72h — 1 (включительная граница); до публикации — не считается
        foreach ([
            '2026-10-04 23:00:00', '2026-10-05 20:00:00', '2026-10-05 22:43:00',
            '2026-10-06 18:00:00', '2026-10-07 22:43:00', '2026-10-04 20:00:00',
        ] as $at) {
            AnonsLinkClick::create(['link' => 'up26-ors-c', 'clicked_at' => Carbon::parse($at)]);
        }
        AnonsLinkClick::create(['link' => 'other-key', 'clicked_at' => $published->copy()->addHours(2)]);

        $this->artisan('anons:ops', ['op' => 'journal-fill', '--link' => 'up26-ors-c',
            '--permalink' => 'https://t.me/samskrte/633',
            '--published-at' => '2026-10-04 22:43:00',
        ])->assertExitCode(0);

        $row = AnonsPlacement::query()->where('link', 'up26-ors-c')->firstOrFail();
        $this->assertSame('https://t.me/samskrte/633', $row->permalink);
        $this->assertSame('2026-10-04 22:43:00', $row->published_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $row->clicks_24h);
        $this->assertSame(5, $row->clicks_72h);
    }

    public function test_journal_fill_requires_existing_row(): void
    {
        $this->artisan('anons:ops', ['op' => 'journal-fill', '--link' => 'nope-ors-c'])
            ->assertExitCode(1);
    }

    /** Canary на наших данных: живые ключи up26 лежат в продовом конфиге. */
    public function test_up26_keys_present_in_tracked_links_config(): void
    {
        $real = require config_path('tracked_links.php');

        $this->assertIsArray($real['links']['up26-ors-c'] ?? null);
        $this->assertIsArray($real['links']['up26-ors-p'] ?? null);
    }
}
