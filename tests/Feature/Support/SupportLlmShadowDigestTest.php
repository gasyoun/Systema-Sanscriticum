<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\MarketingSetting;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDailyDigest;
use App\Services\Support\SupportDmAutoReply;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4429 (рулинг MG 08-09-2026: «live после недели тени, без переспрашивания»):
 *  - утренняя сводка поддержки несёт блок «LLM-тень» (счётчики, отказы,
 *    пример черновика, расход, прогресс серии);
 *  - support:llm-live-enable включает живой режим ровно на 7-й продуктивный
 *    день, отказывает раньше, при выключенном флаге ветки, и не дублируется;
 *  - включение пишется штампом support_llm_live_enabled_at + аудит-событием
 *    dm_llm_live_enabled и читается конвейером H4404 (llmLive()).
 */
class SupportLlmShadowDigestTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $yesterday;

    private const DRAFT = 'Здравствуйте. Школа открывается за полчаса до начала занятия.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->yesterday = CarbonImmutable::now('Europe/Moscow')->subDay()->startOfDay();

        config([
            'app.timezone' => 'Europe/Moscow',
            'features.support_dm_llm_drafts' => true,
            'features.support_dm_llm_drafts_live' => false,
            'features.support_daily_digest' => true,
            'services.openrouter.pricing' => [
                'deepseek/deepseek-chat' => ['prompt_per_1m' => 0.2, 'completion_per_1m' => 0.8],
            ],
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_digest_renders_shadow_section_with_counts_excerpt_and_streak(): void
    {
        $this->seedShadowEvent($this->yesterday->setTime(10, 15), [
            'draft' => self::DRAFT,
            'model' => 'deepseek/deepseek-chat',
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50],
        ]);
        $this->seedRefusedEvent($this->yesterday->setTime(10, 30), 'money');

        $code = \Artisan::call('support:daily-digest', [
            '--dry' => true,
            '--date' => $this->yesterday->toDateString(),
        ]);

        $this->assertSame(0, $code);
        $out = \Artisan::output();
        $this->assertStringContainsString('LLM-ветка (тень)', $out);
        $this->assertStringContainsString('Сформулировано (тень): 1', $out);
        $this->assertStringContainsString('Отказы R3: money 1', $out);
        $this->assertStringContainsString('Пример (deepseek/deepseek-chat): '.self::DRAFT, $out);
        $this->assertStringContainsString('Расход за день: $0.0001', $out);
        $this->assertStringContainsString('Дней тени подряд: 1 из 7', $out);
        Http::assertNothingSent();
    }

    public function test_digest_skips_llm_section_when_branch_flag_is_off(): void
    {
        config(['features.support_dm_llm_drafts' => false]);
        $this->seedShadowEvent($this->yesterday->setTime(10, 15), ['draft' => self::DRAFT, 'model' => null, 'usage' => null]);

        \Artisan::call('support:daily-digest', [
            '--dry' => true,
            '--date' => $this->yesterday->toDateString(),
        ]);

        $this->assertStringNotContainsString('LLM-ветка', \Artisan::output());
    }

    public function test_digest_has_no_llm_section_on_a_quiet_day(): void
    {
        \Artisan::call('support:daily-digest', [
            '--dry' => true,
            '--date' => $this->yesterday->toDateString(),
        ]);

        $this->assertStringNotContainsString('LLM-ветка (тень)', \Artisan::output());
    }

    public function test_live_enable_refuses_below_the_streak(): void
    {
        for ($i = 6; $i >= 1; $i--) {
            $this->seedShadowEvent($this->yesterday->subDays($i - 1)->setTime(10, 0), ['draft' => self::DRAFT, 'model' => null, 'usage' => null]);
        }

        $this->artisan('support:llm-live-enable', ['--dry' => true])
            ->expectsOutputToContain('серия тени 6 из 7 дней')
            ->assertExitCode(0);

        $this->assertNull(MarketingSetting::query()->first()?->support_llm_live_enabled_at);
    }

    public function test_live_enable_refuses_when_branch_flag_is_off(): void
    {
        config(['features.support_dm_llm_drafts' => false]);

        $this->artisan('support:llm-live-enable')
            ->expectsOutputToContain('SUPPORT_DM_LLM_DRAFTS выключен')
            ->assertExitCode(0);

        $this->assertNull(MarketingSetting::query()->first()?->support_llm_live_enabled_at);
    }

    public function test_live_enable_fires_on_the_seventh_productive_day(): void
    {
        for ($i = 7; $i >= 1; $i--) {
            $this->seedShadowEvent($this->yesterday->subDays($i - 1)->setTime(10, 0), ['draft' => self::DRAFT, 'model' => null, 'usage' => null]);
        }

        \Artisan::call('support:llm-live-enable');
        $out = \Artisan::output();
        $this->assertStringContainsString('ВКЛЮЧЁН', $out, 'команда должна включить живой режим: '.$out);

        $stamp = MarketingSetting::query()->first()?->support_llm_live_enabled_at;
        $this->assertNotNull($stamp, 'штамп живого включения должен быть записан');

        $this->assertTrue(SupportDailyDigest::llmLive(), 'llmLive() обязан видеть штамп — конвейер H4404 уходит в живой режим');

        $audit = SupportAiReplyEvent::query()->where('event_type', 'dm_llm_live_enabled')->first();
        $this->assertNotNull($audit);
        $this->assertSame(7, $audit->meta['streak_days']);
        $this->assertSame(7, $audit->meta['required_days']);
    }

    public function test_live_enable_is_idempotent(): void
    {
        for ($i = 7; $i >= 1; $i--) {
            $this->seedShadowEvent($this->yesterday->subDays($i - 1)->setTime(10, 0), ['draft' => self::DRAFT, 'model' => null, 'usage' => null]);
        }

        $this->artisan('support:llm-live-enable')->assertExitCode(0);
        $this->artisan('support:llm-live-enable')
            ->expectsOutputToContain('уже включён')
            ->assertExitCode(0);

        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', 'dm_llm_live_enabled')->count());
    }

    public function test_empty_day_resets_the_streak(): void
    {
        // 6 дней подряд + ПУСТОЙ день вчерашним — серия обнуляется.
        for ($i = 7; $i >= 2; $i--) {
            $this->seedShadowEvent($this->yesterday->subDays($i - 1)->setTime(10, 0), ['draft' => self::DRAFT, 'model' => null, 'usage' => null]);
        }

        $this->artisan('support:llm-live-enable', ['--dry' => true])
            ->expectsOutputToContain('серия тени 0 из 7 дней')
            ->assertExitCode(0);
    }

    public function test_env_live_flag_still_works_alongside_the_stamp(): void
    {
        config(['features.support_dm_llm_drafts_live' => true]);

        $this->assertTrue(SupportDailyDigest::llmLive());
    }

    public function test_kernel_schedules_live_enable_at_nine_moscow(): void
    {
        $event = $this->eventFor('support:llm-live-enable');

        $this->assertNotNull($event, 'support:llm-live-enable должен быть в расписании.');
        $this->assertSame('0 9 * * *', $event->expression);
    }

    private function seedShadowEvent(CarbonImmutable $at, array $meta): void
    {
        $incoming = $this->seedIncoming($at);

        $event = new SupportAiReplyEvent([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND,
            'meta' => array_merge([
                'via' => SupportDmAutoReply::VIA,
                'prompt_version' => SupportDmAutoReply::LLM_PROMPT_VERSION,
            ], $meta),
        ]);
        $event->created_at = $at;
        $event->updated_at = $at;
        $event->save();
    }

    private function seedRefusedEvent(CarbonImmutable $at, string $reason): void
    {
        $incoming = $this->seedIncoming($at);

        $event = new SupportAiReplyEvent([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => SupportDmAutoReply::EVENT_LLM_REFUSED,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'reason' => $reason],
        ]);
        $event->created_at = $at;
        $event->updated_at = $at;
        $event->save();
    }

    private function seedIncoming(CarbonImmutable $at): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::query()->firstOrCreate(['name' => 'support']);
        $user = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => random_int(10000, 99000)],
            ['linked_user_id' => $user->id, 'last_message_at' => $at],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'подскажите, до скольки открывается здание школы',
            'sent_at' => $at,
        ]);
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
