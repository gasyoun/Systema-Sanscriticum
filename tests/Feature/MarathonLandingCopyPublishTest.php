<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Support\MarathonLandingCopy;
use App\Support\TelegramChannelEcho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H1067 — A-first landing copy on /online/konsultaciya + LandingPage upsert + channel dry-run.
 */
class MarathonLandingCopyPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg-token',
        ]);
    }

    public function test_default_variant_is_a_and_show_renders_a_hero(): void
    {
        // H2010: while ab_test_until is in the future, the live 50/50 split
        // ignores config('marathon_landing_copy.variant'). End the experiment
        // so these tests assert the post-cutoff / config-driven path.
        config([
            'marathon_landing_copy.variant' => 'a',
            'marathon_landing_copy.ab_test_until' => '2020-01-01',
        ]);

        $this->assertSame('a', MarathonLandingCopy::variantKey());

        $this->get(route('marathon.show'))
            ->assertOk()
            ->assertSee('Санскрит кажется недоступным', false)
            ->assertSee('Что вас останавливало', false)
            ->assertSee('Частые вопросы', false)
            ->assertDontSee('Пойми, как устроен санскрит, и выбери свой курс', false);
    }

    public function test_variant_b_renders_outcome_hero(): void
    {
        config([
            'marathon_landing_copy.variant' => 'b',
            'marathon_landing_copy.ab_test_until' => '2020-01-01',
        ]);

        $this->get(route('marathon.show'))
            ->assertOk()
            ->assertSee('Пойми, как устроен санскрит, и выбери свой курс', false)
            ->assertSee('Что у вас будет через три дня', false);
    }

    public function test_apply_landing_copy_command_upserts_row(): void
    {
        $this->artisan('marathon:apply-landing-copy', ['variant' => 'a'])
            ->assertSuccessful();

        $page = LandingPage::where('slug', config('marathon.landing_slug'))->first();
        $this->assertNotNull($page);
        // Variant A stores the fear-focused hero_title in content[0], subtitle = long subhead.
        $this->assertStringContainsString('недоступным', (string) data_get($page->content, '0.data.title', ''));
        $this->assertSame('Записаться', $page->button_text);
        $this->assertIsArray($page->content);
        $this->assertNotEmpty($page->content);

        $this->artisan('marathon:apply-landing-copy', ['variant' => 'b'])
            ->assertSuccessful();

        $page->refresh();
        $this->assertStringContainsString('Пойми, как устроен', (string) data_get($page->content, '0.data.title', ''));
    }

    /** H445 Phase 5 — January deva-cohort landing upsert (separate slug, single ruled copy). */
    public function test_apply_landing_copy_january_flag_upserts_separate_row(): void
    {
        $this->artisan('marathon:apply-landing-copy', ['--january' => true])
            ->assertSuccessful();

        $januaryPage = LandingPage::where('slug', config('marathon.january_landing_slug'))->first();
        $this->assertNotNull($januaryPage);
        $this->assertStringContainsString('деванагари', (string) data_get($januaryPage->content, '0.data.title', ''));

        // Never touches the zero-cohort row.
        $this->assertNull(LandingPage::where('slug', config('marathon.landing_slug'))->first());
    }

    public function test_channel_posts_dry_run_does_not_hit_telegram(): void
    {
        Http::fake();

        $this->artisan('marathon:publish-channel-posts', ['--post' => '1'])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN');

        Http::assertNothingSent();
    }

    public function test_channel_post_5_skipped_without_testimonial(): void
    {
        config(['marathon.testimonial' => null]);

        $this->artisan('marathon:publish-channel-posts', ['--post' => '5'])
            ->assertSuccessful()
            ->expectsOutputToContain('MARATHON_TESTIMONIAL');
    }

    public function test_live_channel_post_sends_via_magnet_bot(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->artisan('marathon:publish-channel-posts', [
            '--post' => '1',
            '--live' => true,
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'fake-tg-token')
                && str_contains($request->url(), 'sendMessage')
                && ($request['chat_id'] ?? null) === '@samskrte';
        });
    }

    public function test_live_one_shot_post_does_not_resend_on_a_second_run(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        $this->artisan('marathon:publish-channel-posts', ['--post' => '1', '--live' => true])
            ->assertSuccessful();
        $this->artisan('marathon:publish-channel-posts', ['--post' => '1', '--live' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('already sent');

        Http::assertSentCount(1);
        $this->assertSame(
            1,
            DB::table('marathon_channel_posts_sent')->where('post_number', 1)->count()
        );
    }

    public function test_evergreen_post_resends_on_a_new_week_but_not_within_the_same_week(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00')); // Monday, ISO week 37
        $this->artisan('marathon:publish-channel-posts', ['--post' => '3', '--live' => true])
            ->assertSuccessful();
        $this->artisan('marathon:publish-channel-posts', ['--post' => '3', '--live' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('already sent');

        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00')); // next Monday, ISO week 38
        $this->artisan('marathon:publish-channel-posts', ['--post' => '3', '--live' => true])
            ->assertSuccessful();

        Http::assertSentCount(2);
        Carbon::setTestNow();
    }

    /**
     * H3617 — cross-sender dedup: identical text already echoed from the
     * channel (Telegram-native scheduled post, manual admin post) → refuse
     * the live send, no Telegram call, no markSent row.
     */
    public function test_live_send_refused_when_channel_echo_saw_identical_text(): void
    {
        Http::fake();

        $text = MarathonLandingCopy::resolvePostText(1);
        TelegramChannelEcho::record('@samskrte', $text, 602);

        $this->artisan('marathon:publish-channel-posts', ['--post' => '1', '--live' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('echo sensor');

        Http::assertNothingSent();
        $this->assertSame(
            0,
            DB::table('marathon_channel_posts_sent')->where('post_number', 1)->count()
        );
    }

    /** H3617 — echo refusal is per-text: a different text still sends. */
    public function test_echo_of_a_different_text_does_not_block_the_send(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        TelegramChannelEcho::record('@samskrte', 'Совершенно другой текст поста', 601);

        $this->artisan('marathon:publish-channel-posts', ['--post' => '1', '--live' => true])
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    /** H3617 — refusal window is 24 h: an older echo no longer blocks. */
    public function test_echo_older_than_24_hours_does_not_block_the_send(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:05'));
        $text = MarathonLandingCopy::resolvePostText(1);
        TelegramChannelEcho::record('@samskrte', $text, 602);

        Carbon::setTestNow(Carbon::parse('2026-08-29 10:00:06')); // 25h later
        $this->artisan('marathon:publish-channel-posts', ['--post' => '1', '--live' => true])
            ->assertSuccessful();

        Http::assertSentCount(1);
        Carbon::setTestNow();
    }

    /** H3617 — the magnet webhook job records a channel_post echo. */
    public function test_magnet_webhook_job_records_channel_post_echo(): void
    {
        $update = [
            'update_id' => 231995323,
            'channel_post' => [
                'message_id' => 602,
                'sender_chat' => ['id' => -1001762848803, 'type' => 'channel'],
                'chat' => ['id' => -1001762848803, 'title' => 'samskrte', 'type' => 'channel'],
                'date' => 1787900405,
                'text' => 'Консультация по онлайн-курсам открыта 👋',
            ],
        ];

        (new ProcessTelegramMagnetUpdate($update))->handle();

        $this->assertTrue(TelegramChannelEcho::seenRecently('-1001762848803', 'Консультация по онлайн-курсам открыта 👋'));
        // Non-text channel posts (no text/caption) are silently skipped.
        (new ProcessTelegramMagnetUpdate([
            'update_id' => 231995324,
            'channel_post' => ['message_id' => 603, 'chat' => ['id' => -1001762848803, 'type' => 'channel']],
        ]))->handle();

        $this->assertFalse(TelegramChannelEcho::seenRecently('-1001762848803', ''));
    }
}
