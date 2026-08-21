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
use App\Services\Support\SupportOutgoingAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportDmAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    private function incoming(User $user, string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
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

    public function test_flag_off_is_noop(): void
    {
        config(['features.support_dm_auto_reply' => false]);
        Http::fake();

        $user = User::factory()->create();
        $this->withZoomClass($user);
        $incoming = $this->incoming($user, 'как войти в зум на занятие');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('off', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        Http::assertNothingSent();
    }

    public function test_simple_zoom_queues_pending_ai_outgoing(): void
    {
        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create();
        $this->withZoomClass($user);
        $incoming = $this->incoming($user, 'как войти в зум на занятие');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $this->assertSame('A', $result['category']);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertSame('ai', $outgoing->responder_type);
        $this->assertSame('sent', $outgoing->ai_state);
        $this->assertTrue($outgoing->raw_payload['pending_delivery']);
        $this->assertSame(SupportDmAutoReply::VIA, $outgoing->raw_payload['via']);
        $this->assertStringContainsString('https://zoom.example/j/42', (string) $outgoing->text);

        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count());
        Http::assertNothingSent();
    }

    public function test_payment_question_hints_admins_and_does_not_send_to_student(): void
    {
        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111,222',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame('D', $result['category']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) ($request['text'] ?? ''), 'Сложный вопрос'));
    }

    public function test_duplicate_incoming_does_not_resend(): void
    {
        config(['features.support_dm_auto_reply' => true]);
        Http::fake();

        $user = User::factory()->create();
        $this->withZoomClass($user);
        $incoming = $this->incoming($user, 'ссылка на зум пожалуйста');

        $first = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');
        $second = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_apple_in_text_is_gorbachenko_else_gasuns(): void
    {
        $attr = app(SupportOutgoingAttribution::class);

        $this->assertSame('🍎', $attr->markerFromOutgoingText('Добрый день 🍎 вот ссылка'));
        $this->assertSame('gasuns', $attr->markerFromOutgoingText('Добрый день, вот ссылка'));
    }
}
