<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3765 A2: матрица двух потолков свежести.
 *
 * Свежее (<6 ч)  — можно всё, включая исходящее студенту.
 * Между (6–24 ч) — студенту молчим, куратору показываем.
 * Старое (>24 ч) — тихий пропуск с маркером, как и до H3765.
 */
class SupportDmFreshnessCeilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'services.telegram_support.auto_reply_max_age_hours' => 6,
            'services.telegram_support.hint_max_age_hours' => 24,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function incoming(User $user, string $text, int $hoursAgo): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9401],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9401,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function withZoomClass(User $user): void
    {
        $group = Group::factory()->create(['status' => 'active']);
        $user->groups()->attach($group->id);
        Schedule::query()->create([
            'title' => 'Санскрит',
            'link' => 'https://zoom.example/j/42',
            'start' => now()->addDay(),
            'group_id' => $group->id,
        ]);
    }

    public function test_fresh_message_still_auto_replies_to_the_student(): void
    {
        $user = User::factory()->create();
        $this->withZoomClass($user);

        $result = app(SupportDmAutoReply::class)->handle($this->incoming($user, 'как войти в зум на занятие', 1), $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_message_between_the_two_ceilings_hints_but_never_reaches_the_student(): void
    {
        $user = User::factory()->create();
        $this->withZoomClass($user);

        $result = app(SupportDmAutoReply::class)->handle($this->incoming($user, 'как войти в зум на занятие', 12), $user->id, 'private');

        $this->assertSame('hinted', $result['status'], 'между потолками вопрос обязан дойти до куратора');
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'студенту не должно уйти ничего — это и есть смысл строгого потолка автоответа',
        );

        $hint = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->first();
        $this->assertNotNull($hint);
        $this->assertTrue((bool) ($hint->meta['aged'] ?? false), 'подсказка помечается как «полежавшая»');
    }

    public function test_message_older_than_the_hint_ceiling_is_skipped_silently(): void
    {
        $user = User::factory()->create();
        $this->withZoomClass($user);

        $result = app(SupportDmAutoReply::class)->handle($this->incoming($user, 'как войти в зум на занятие', 48), $user->id, 'private');

        $this->assertSame('stale_skip', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_STALE_SKIP)->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
        Http::assertNothingSent();
    }

    public function test_aged_greeting_is_neither_answered_nor_hinted(): void
    {
        $user = User::factory()->create();

        $result = app(SupportDmAutoReply::class)->handle($this->incoming($user, 'Намасте!', 12), $user->id, 'private');

        $this->assertSame('skip', $result['status'], 'вчерашнее «Намасте» не работа для куратора');
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        Http::assertNothingSent();
    }

    public function test_hint_ceiling_below_send_ceiling_degrades_to_the_old_single_gate(): void
    {
        // Защита от кривой настройки: подсказочный потолок ниже автоответного
        // не должен открывать окно, которого нет.
        config(['services.telegram_support.hint_max_age_hours' => 2]);

        $user = User::factory()->create();
        $this->withZoomClass($user);

        $result = app(SupportDmAutoReply::class)->handle($this->incoming($user, 'как войти в зум на занятие', 12), $user->id, 'private');

        $this->assertSame('stale_skip', $result['status']);
    }
}
