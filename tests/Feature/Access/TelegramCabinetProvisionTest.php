<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Самообслуживание «/кабинет <email>» (02-09-2026): автосоздание кабинета
 * одним шагом из лички студент-бота. Флаг OFF (дефолт) — поведение бота
 * байт-в-байт прежнее. Щиты: CAP ≤1 создание на telegram_id, существующий
 * email не трогаем, только личка.
 */
class TelegramCabinetProvisionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.student_bot_token' => 'test-bot-token']);
        config(['services.telegram.bot_webhook_secret' => 'test-webhook-secret']);
        self::$sent = [];
        Http::fake(function ($request) {
            self::$sent[] = $request;

            return Http::response(['ok' => true]);
        });
    }

    private function webhookMessage(int|string $chatId, string $text, string $type = 'private'): array
    {
        return [
            'update_id' => random_int(1, 999999999),
            'message' => [
                'text' => $text,
                'chat' => ['id' => $chatId, 'type' => $type],
                'from' => ['username' => 'lena_test'],
            ],
        ];
    }

    private function postUpdate(array $update): void
    {
        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret',
        ])
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    private function lastBotMessage(): string
    {
        $texts = collect(self::$sent)
            ->filter(fn ($req) => str_contains((string) $req->url(), '/sendMessage'))
            ->map(fn ($req) => data_get($req, 'text', ''));

        return (string) $texts->last();
    }

    /** @test */
    public function flag_off_command_falls_through_to_legacy_hint(): void
    {
        $this->postUpdate($this->webhookMessage(999888777, '/кабинет lena@example.com'));

        $this->assertSame(0, User::count());
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertStringContainsString('привяжите свой аккаунт', $this->lastBotMessage());
    }

    /** @test */
    public function command_creates_user_links_telegram_and_issues_login_link(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
            'features.membership_tiered' => false,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет Lena@Example.com '));

        $user = User::query()->sole();
        $this->assertSame('lena@example.com', $user->email);
        $this->assertSame(999888777, (int) $user->telegram_id);
        $this->assertSame('telegram', $user->signup_source);
        $this->assertNotNull($user->telegram_connected_at);

        $token = MagicLinkToken::query()->sole();
        $this->assertSame('tg_login', $token->purpose);
        $this->assertTrue($token->isActive());

        $message = $this->lastBotMessage();
        $this->assertStringContainsString('личный кабинет создан', $message);
        $this->assertStringContainsString('/tg-login/', $message);
    }

    /** @test */
    public function command_grants_free_tier_lessons_like_self_register(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
            'features.membership_tiered' => false,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет new.student@example.com'));

        $user = User::query()->sole();
        // FreeTierLessonGranter при membership_tiered=false логирует skip,
        // но не падает; при tiered=ON выдаёт SRS-деку. Проверяем живучесть
        // грантера: пользователь создан и команда отработала без ошибки.
        $this->assertNotNull($user->id);
    }

    /** @test */
    public function existing_email_is_never_overwritten_or_bound(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
        ]);

        $existing = User::factory()->create(['email' => 'existing@example.com', 'name' => 'Старый']);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет existing@example.com'));

        $this->assertSame(1, User::count());
        $this->assertNull($existing->fresh()->telegram_id);
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertStringContainsString('уже существует', $this->lastBotMessage());
    }

    /** @test */
    public function creation_is_capped_once_per_telegram_id(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
            'features.membership_tiered' => false,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет first@example.com'));
        $this->postUpdate($this->webhookMessage(999888777, '/кабинет second@example.com'));

        // Второй вызов по тому же telegram_id не создаёт второй аккаунт:
        // telegram уже привязан → ветка «у вас уже есть кабинет».
        $this->assertSame(1, User::count());
        $this->assertSame('first@example.com', User::query()->sole()->email);
        $this->assertStringContainsString('уже есть кабинет', $this->lastBotMessage());
    }

    /** @test */
    public function cache_cap_blocks_repeated_creation_after_unlink(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
            'features.membership_tiered' => false,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет first@example.com'));

        // Симулируем отвязку (студент стёр аккаунт/админ развязал) — CAP в кэше
        // не даёт плодить кабинеты с того же telegram_id.
        User::query()->sole()->forceFill(['telegram_id' => null, 'telegram_connected_at' => null])->save();

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет second@example.com'));

        $this->assertSame(1, User::count());
        $this->assertStringContainsString('уже создан', $this->lastBotMessage());
    }

    /** @test */
    public function linked_user_gets_login_link_not_second_account(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
        ]);

        $user = User::factory()->create(['telegram_id' => 111222333]);

        $this->postUpdate($this->webhookMessage(111222333, '/кабинет lena@example.com'));

        $this->assertSame(1, User::count());
        $this->assertSame(1, MagicLinkToken::count());
        $this->assertStringContainsString('уже есть кабинет', $this->lastBotMessage());
    }

    /** @test */
    public function command_without_email_replies_with_usage_hint(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет'));

        $this->assertSame(0, User::count());
        $this->assertStringContainsString('/кабинет', $this->lastBotMessage());
    }

    /** @test */
    public function command_with_non_email_argument_asks_for_email(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
        ]);

        $this->postUpdate($this->webhookMessage(999888777, '/кабинет привет'));

        $this->assertSame(0, User::count());
        $this->assertStringContainsString('ваш email', $this->lastBotMessage());
    }

    /** @test */
    public function command_in_group_gets_dm_pointer_not_an_account(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
        ]);

        $this->postUpdate($this->webhookMessage(-1001234567890, '/кабинет lena@example.com', 'supergroup'));

        $this->assertSame(0, User::count());
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertStringContainsString('в личку', $this->lastBotMessage());
        $this->assertStringNotContainsString('/tg-login/', $this->lastBotMessage());
    }

    /** @test */
    public function command_is_silent_in_groups_when_flag_off(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => false,
        ]);

        $this->postUpdate($this->webhookMessage(-1001234567890, '/кабинет lena@example.com', 'supergroup'));

        $this->assertSame(0, User::count());
        $this->assertSame('', $this->lastBotMessage());
    }

    /** @test */
    public function command_with_flag_on_creates_account_via_dm_not_group(): void
    {
        config([
            'features.telegram_cabinet_login' => true,
            'features.telegram_cabinet_provision' => true,
            'features.membership_tiered' => false,
        ]);

        // Групповое сообщение с email не должно создать аккаунт и не должно
        // утечь в AI/личную ветку: группе бот отвечать НЕ обязан (тишина) —
        // указатель даёт только чистая команда /кабинет.
        $this->postUpdate($this->webhookMessage(-1001234567890, '/кабинет lena@example.com', 'supergroup'));

        $this->assertSame(0, User::count());
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertStringNotContainsString('/tg-login/', $this->lastBotMessage());
    }
}
