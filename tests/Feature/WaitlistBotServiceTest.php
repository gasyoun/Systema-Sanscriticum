<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\CourseWaitlistItem;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Models\WaitlistVote;
use App\Services\Telegram\WaitlistBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ждун в @samskrte_bot (MG 04-10-2026): /zhdun + callback "wl:*" зеркалят
 * сайт-механику /online/zhdun — registered-only (MG 31-08-2026), те же
 * CourseWaitlistItem/WaitlistVote, флаг features.waitlist_voting гейтит.
 * С H6408 (MG 10-10-2026) голос одним нажатием (wl:vote), пожелание
 * времени — мягкий шаг после (wl:slot / wl:skip).
 */
class WaitlistBotServiceTest extends TestCase
{
    use RefreshDatabase;

    private CourseWaitlistItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.waitlist_voting' => true]);
        MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => 'fake-tg-token',
        ]);
        $this->item = CourseWaitlistItem::create([
            'slug' => 'buhler-guide-wl',
            'course_title' => 'Руководство по Бюлеру',
            'teacher_name' => 'Марцис Гасунс',
            'min_payers' => 8,
            'kind' => 'grammar',
            'status' => CourseWaitlistItem::STATUS_COLLECTING,
            'is_listed' => true,
        ]);
    }

    public function test_zhdun_command_sends_list_with_progress(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun'],
        ]);

        $this->assertTrue($consumed);
        Http::assertSent(function ($request): bool {
            // json_encode экранирует слэш ("0 \/ 8"), поэтому кнопку декодируем.
            $markup = json_decode((string) $request['reply_markup'], true) ?? [];
            $label = $markup['inline_keyboard'][0][0]['text'] ?? '';

            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'Ждун')
                && str_contains($label, 'Руководство по Бюлеру')
                && str_contains($label, '0 / 8');
        });
    }

    /** H6566: «Стать добровольцем» — URL-кнопка на /start и в футере списка, обе через трекед-прослойку /ga/volunteer. */
    public function test_volunteer_url_button_on_welcome_and_list(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $service = new WaitlistBotService;
        $service->sendWelcome('4242');
        $service->sendList('4242');

        $welcomed = false;
        $listed = false;
        foreach (Http::recorded() as [$request]) {
            if (! str_contains($request->url(), '/sendMessage')) {
                continue;
            }
            $markup = json_decode((string) $request['reply_markup'], true) ?? [];
            $hasButton = collect($markup['inline_keyboard'] ?? [])
                ->flatten(1)
                ->contains(fn ($button) => ($button['text'] ?? '') === '🤝 Стать добровольцем'
                    && ($button['url'] ?? '') === 'https://samskrte.ru/ga/volunteer');
            if (str_contains((string) $request['text'], 'Привет! Это бот')) {
                $welcomed = $hasButton;
            }
            if (str_contains((string) $request['text'], 'будущие группы ОРС')) {
                $listed = $hasButton;
            }
        }

        $this->assertTrue($welcomed, 'На /start-приветствии нет кнопки «Стать добровольцем»');
        $this->assertTrue($listed, 'В футере ждун-списка нет кнопки «Стать добровольцем»');
    }

    public function test_vote_callback_casts_vote_and_offers_soft_slot_step(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        User::factory()->create(['telegram_id' => 4242]);

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'wl:vote:'.$this->item->getKey(),
                'message' => ['chat' => ['id' => 4242], 'message_id' => 77],
            ],
        ]);

        $vote = WaitlistVote::query()->sole();
        $this->assertSame($this->item->getKey(), $vote->course_waitlist_item_id);
        $this->assertNull($vote->slot_preference);
        Http::assertSent(function ($request): bool {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true) ?? [];
            $flat = json_encode($markup, JSON_UNESCAPED_UNICODE);

            return str_contains($request->url(), '/editMessageText')
                && str_contains((string) $request['text'], 'Голос учтён')
                && str_contains((string) $request['text'], '1 / 8')
                && str_contains((string) $flat, 'wl:slot:'.$this->item->getKey().':morning')
                && str_contains((string) $flat, 'wl:skip:'.$this->item->getKey())
                && str_contains((string) $flat, 'Пропустить');
        });
    }

    public function test_slot_callback_after_vote_records_preference(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['telegram_id' => 4242]);
        $this->item->castVoteBy($user, null);

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-2',
                'data' => 'wl:slot:'.$this->item->getKey().':morning',
                'message' => ['chat' => ['id' => 4242], 'message_id' => 78],
            ],
        ]);

        $this->assertSame(1, WaitlistVote::count());
        $this->assertSame('morning', WaitlistVote::query()->sole()->slot_preference);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Голос учтён')
            && str_contains((string) $request['text'], 'Утром')
            && str_contains((string) $request['text'], '1 / 8'));
    }

    public function test_skip_callback_closes_without_writing_slot(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['telegram_id' => 4242]);
        $this->item->castVoteBy($user, null);

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-3',
                'data' => 'wl:skip:'.$this->item->getKey(),
                'message' => ['chat' => ['id' => 4242], 'message_id' => 79],
            ],
        ]);

        $this->assertSame(1, WaitlistVote::count());
        $this->assertNull(WaitlistVote::query()->sole()->slot_preference);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Голос учтён')
            && str_contains((string) $request['text'], '1 / 8'));
    }

    public function test_repeat_slot_choice_updates_existing_row(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['telegram_id' => 4242]);
        $this->item->castVoteBy($user, 'morning');

        foreach (['day', 'evening'] as $slot) {
            (new WaitlistBotService)->handleUpdate([
                'callback_query' => [
                    'id' => 'cb-4-'.$slot,
                    'data' => 'wl:slot:'.$this->item->getKey().':'.$slot,
                    'message' => ['chat' => ['id' => 4242], 'message_id' => 80],
                ],
            ]);
        }

        $this->assertSame(1, WaitlistVote::count());
        $this->assertSame('evening', WaitlistVote::query()->sole()->slot_preference);
    }

    public function test_vote_callback_from_unlinked_chat_records_nothing_and_asks_to_bind(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-5',
                'data' => 'wl:vote:'.$this->item->getKey(),
                'message' => ['chat' => ['id' => 999999], 'message_id' => 81],
            ],
        ]);

        $this->assertSame(0, WaitlistVote::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], '/telegram/connect'));
    }

    public function test_legacy_do_callback_still_votes_with_slot(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        User::factory()->create(['telegram_id' => 4242]);

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-6',
                'data' => 'wl:do:'.$this->item->getKey().':evening',
                'message' => ['chat' => ['id' => 4242], 'message_id' => 82],
            ],
        ]);

        $vote = WaitlistVote::query()->sole();
        $this->assertSame('evening', $vote->slot_preference);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Голос учтён')
            && str_contains((string) $request['text'], 'Вечером')
            && str_contains((string) $request['text'], '1 / 8'));
    }

    public function test_mine_lists_user_votes(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $user = User::factory()->create(['telegram_id' => 4242]);
        $this->item->castVoteBy($user, 'evening');

        (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-7',
                'data' => 'wl:mine',
                'message' => ['chat' => ['id' => 4242], 'message_id' => 83],
            ],
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Руководство по Бюлеру')
            && str_contains((string) $request['text'], 'Вечером'));
    }

    public function test_flag_off_leaves_update_unconsumed(): void
    {
        config(['features.waitlist_voting' => false]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun'],
        ]);

        $this->assertFalse($consumed);
        Http::assertNothingSent();
    }

    public function test_job_routes_zhdun_and_welcomes_tokenless_start(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun'],
        ]))->handle();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Ждун'));

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/start'],
        ]))->handle();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '/zhdun'));
    }

    public function test_zhdun_stats_admin_chat_sees_full_voter_breakdown(): void
    {
        config(['services.telegram.curators_chat_id' => '-100777']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $anna = User::factory()->create(['name' => 'Анна', 'telegram_id' => 11]);
        $petr = User::factory()->create(['name' => 'Пётр', 'telegram_id' => 12]);
        $this->item->castVoteBy($anna, 'evening');
        $this->item->castVoteBy($petr, null);

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => -100777], 'text' => '/zhdun-stats'],
        ]);

        $this->assertTrue($consumed);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Руководство по Бюлеру')
            && str_contains((string) $request['text'], '2 / 8')
            && str_contains((string) $request['text'], 'Анна')
            && str_contains((string) $request['text'], 'Вечером')
            && str_contains((string) $request['text'], 'Пётр')
            && str_contains((string) $request['text'], 'любое время'));
    }

    public function test_zhdun_stats_allowlisted_extra_chat_sees_breakdown_and_foreign_still_refused(): void
    {
        config([
            'services.telegram.curators_chat_id' => '-100777',
            'services.telegram.zhdun_stats_chat_ids' => ['-100888'],
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $anna = User::factory()->create(['name' => 'Анна', 'telegram_id' => 11]);
        $this->item->castVoteBy($anna, 'evening');

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => -100888], 'text' => '/zhdun-stats'],
        ]);

        $this->assertTrue($consumed);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Руководство по Бюлеру')
            && str_contains((string) $request['text'], 'Анна'));

        (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun-stats'],
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'только кураторам'));
    }

    public function test_zhdun_stats_other_chat_gets_polite_refusal_without_data(): void
    {
        config(['services.telegram.curators_chat_id' => '-100777']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $anna = User::factory()->create(['name' => 'Анна', 'telegram_id' => 11]);
        $this->item->castVoteBy($anna, 'morning');

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun-stats'],
        ]);

        $this->assertTrue($consumed);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'только кураторам')
            && ! str_contains((string) $request['text'], 'Анна')
            && ! str_contains((string) $request['text'], 'Руководство по Бюлеру')
            && ! str_contains((string) $request['text'], '1 / 8'));
    }

    public function test_zhdun_stats_with_unconfigured_curators_chat_refuses_without_data(): void
    {
        config(['services.telegram.curators_chat_id' => null]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new WaitlistBotService)->handleUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/zhdun-stats'],
        ]);

        $this->assertTrue($consumed);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'только кураторам')
            && ! str_contains((string) $request['text'], 'Руководство по Бюлеру'));
    }

    public function test_start_wl_slug_deep_link_shows_single_course_card(): void
    {
        User::factory()->create(['name' => 'Анна', 'telegram_id' => 4242]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/start wl_buhler-guide-wl'],
        ]))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Руководство по Бюлеру')
            && str_contains((string) $request['reply_markup'], 'Голосовать за этот курс')
            && ! str_contains((string) $request['text'], 'Каллиграфия'));
    }

    public function test_start_wl_unknown_slug_gets_polite_fallback(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new ProcessTelegramMagnetUpdate([
            'message' => ['chat' => ['id' => 4242], 'text' => '/start wl_no-such-course'],
        ]))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'не собирается'));
    }

    public function test_unvote_callback_removes_vote_and_offers_revote(): void
    {
        $anna = User::factory()->create(['name' => 'Анна', 'telegram_id' => 4242]);
        $this->item->castVoteBy($anna, 'evening');
        $this->assertSame(1, $this->item->votesCount());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new WaitlistBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb1',
                'data' => 'wl:unvote:'.$this->item->getKey(),
                'message' => ['chat' => ['id' => 4242], 'message_id' => 55],
            ],
        ]);

        $this->assertTrue($consumed);
        $this->assertSame(0, $this->item->votesCount());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], 'Голос отозван')
            && str_contains((string) $request['reply_markup'], 'Голосовать снова'));
    }

    public function test_course_card_for_voter_shows_unvote_and_slot(): void
    {
        $anna = User::factory()->create(['name' => 'Анна', 'telegram_id' => 4242]);
        $this->item->castVoteBy($anna, 'evening');
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new WaitlistBotService)->sendCourseBySlug('4242', 'buhler-guide-wl');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Вы уже голосовали')
            && str_contains((string) $request['text'], 'Вечером')
            && str_contains((string) $request['reply_markup'], 'Отозвать голос'));
    }
}
