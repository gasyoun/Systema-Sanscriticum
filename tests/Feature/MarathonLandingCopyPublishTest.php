<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Support\MarathonLandingCopy;
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
}
