<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\User;
use App\Services\Access\TelegramLoginService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Telegram-вход» в кабинет (CABINET_ADOPTION_ROADMAP P2, 28-08-2026).
 * Сценарий 1: привязанный студент пишет /start или /вход — получает одноразовую
 * magic-ссылку (purpose tg_login). Сценарий 2 (отдельный флаг, default OFF):
 * непривязанный присылает email заказа — матч среди оплативших, staff исключён.
 * Оба флага OFF (дефолт) — поведение бота байт-в-байт прежнее.
 */
class TelegramCabinetLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    public static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Токен бота нужен, чтобы sendMessage уходил на fake-эндпоинт; ловим
        // ВСЕ исходящие (catch-all) — AI-ветка тоже не должна выходить в сеть.
        config(['services.telegram.student_bot_token' => 'test-bot-token']);
        config(['services.telegram.bot_webhook_secret' => 'test-webhook-secret']);
        self::$sent = [];
        Http::fake(function ($request) {
            self::$sent[] = $request;

            return Http::response(['ok' => true]);
        });
    }

    private function linkedStudent(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'telegram_id' => 111222333,
        ], $attrs));
    }

    private function webhookMessage(int|string $chatId, string $text): array
    {
        return [
            'update_id' => random_int(1, 999999999),
            'message' => [
                'text' => $text,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['username' => 'lena_test'],
            ],
        ];
    }

    private function postUpdate(array $update): void
    {
        // Вебхук фейл-клоузед по секрету (VerifyTelegramBotWebhook) —
        // подписываем как настоящий Telegram (setWebhook secret_token).
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
    public function flags_off_start_of_linked_student_keeps_legacy_hint(): void
    {
        $user = $this->linkedStudent();

        $this->postUpdate($this->webhookMessage($user->telegram_id, '/start'));

        $this->assertStringContainsString('Подключить Telegram', $this->lastBotMessage());
        $this->assertSame(0, MagicLinkToken::count());
    }

    /** @test */
    public function flags_off_login_command_falls_through_to_legacy_paths(): void
    {
        $user = $this->linkedStudent();

        $this->postUpdate($this->webhookMessage($user->telegram_id, '/вход'));

        // Ушло в AI-ветку как обычный вопрос (ChatMessage создан), ссылки нет.
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id]);
    }

    /** @test */
    public function linked_student_gets_one_time_login_link_on_start(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        $user = $this->linkedStudent();

        $this->postUpdate($this->webhookMessage($user->telegram_id, '/start'));

        $token = MagicLinkToken::query()->sole();
        $this->assertSame(TelegramLoginService::MAGIC_PURPOSE, $token->purpose);
        $this->assertTrue($token->isActive());
        $this->assertTrue(now()->addMinutes(14)->lt($token->expires_at));

        $message = $this->lastBotMessage();
        $this->assertStringContainsString('/tg-login/', $message);
        $this->assertStringContainsString('одноразовая', $message);
    }

    /** @test */
    public function login_command_with_argument_works_case_insensitively(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        $user = $this->linkedStudent();

        $this->postUpdate($this->webhookMessage($user->telegram_id, '/Вход пожалуйста'));

        $this->assertSame(1, MagicLinkToken::count());
        $this->assertStringContainsString('/tg-login/', $this->lastBotMessage());
    }

    /** @test */
    public function tg_login_link_consumes_once_logs_in_and_redirects_to_dashboard(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        $user = $this->linkedStudent(['login_count' => 0]);
        $plaintext = MagicLinkToken::issueFor($user, TelegramLoginService::MAGIC_PURPOSE, 15);

        $response = $this->get('/tg-login/'.$plaintext);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $user->fresh()->login_count);

        // Второй клик (replay) — 404.
        $this->get('/tg-login/'.$plaintext)->assertNotFound();
    }

    /** @test */
    public function tg_login_rejects_foreign_purpose_and_unknown_tokens(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        $user = $this->linkedStudent();

        $newsletter = MagicLinkToken::issueFor($user, 'newsletter', 15);
        $this->get('/tg-login/'.$newsletter)->assertNotFound();
        $this->get('/tg-login/totally-unknown-token')->assertNotFound();

        // admin-ссылка не гасится «чужим» маршрутом — осталась живой.
        $admin = MagicLinkToken::issueFor($user, 'admin_unblock', 15);
        $this->assertNotNull(MagicLinkToken::findActive($admin, 'admin_unblock'));
    }

    /** @test */
    public function tg_login_route_is_404_when_flag_off(): void
    {
        $user = $this->linkedStudent();
        $plaintext = MagicLinkToken::issueFor($user, TelegramLoginService::MAGIC_PURPOSE, 15);

        $this->get('/tg-login/'.$plaintext)->assertNotFound();
        $this->assertNotNull(MagicLinkToken::findActive($plaintext, TelegramLoginService::MAGIC_PURPOSE));
    }

    /** @test */
    public function email_from_unlinked_chat_is_ignored_when_email_link_flag_off(): void
    {
        $student = User::factory()->create(['email' => 'paid.student@example.com']);
        Payment::create(['user_id' => $student->id, 'amount' => 5000, 'status' => 'paid']);

        $this->postUpdate($this->webhookMessage(999888777, 'paid.student@example.com'));

        $this->assertNull($student->fresh()->telegram_id);
        $this->assertSame(0, MagicLinkToken::count());
        $this->assertStringContainsString('привяжите свой аккаунт', $this->lastBotMessage());
    }

    /** @test */
    public function email_of_paying_student_binds_chat_and_issues_link(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        config(['features.telegram_cabinet_email_link' => true]);

        $student = User::factory()->create(['email' => 'Paid.Student@Example.com', 'name' => 'Лена']);
        Payment::create(['user_id' => $student->id, 'amount' => 5000, 'status' => 'paid']);

        // Нормализация: пробелы + регистр в сообщении не должны мешать матчу.
        $this->postUpdate($this->webhookMessage(999888777, ' paid.student@example.com '));

        $fresh = $student->fresh();
        $this->assertSame(999888777, (int) $fresh->telegram_id);
        $this->assertNull($fresh->telegram_auth_token);

        $token = MagicLinkToken::query()->sole();
        $this->assertSame(TelegramLoginService::MAGIC_PURPOSE, $token->purpose);

        $message = $this->lastBotMessage();
        $this->assertStringContainsString('Лена', $message);
        $this->assertStringContainsString('/tg-login/', $message);
    }

    /** @test */
    public function email_without_payments_or_staff_email_is_refused_without_leaking_which(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        config(['features.telegram_cabinet_email_link' => true]);

        $noPay = User::factory()->create(['email' => 'no.pays@example.com']);
        $admin = User::factory()->create(['email' => 'boss@example.com', 'role' => Roles::ADMIN]);
        Payment::create(['user_id' => $admin->id, 'amount' => 5000, 'status' => 'paid']);

        $this->postUpdate($this->webhookMessage(555, 'no.pays@example.com'));
        $this->assertStringContainsString('Не нашёл оплат', $this->lastBotMessage());
        $this->assertNull($noPay->fresh()->telegram_id);

        $this->postUpdate($this->webhookMessage(556, 'boss@example.com'));
        $this->assertNull($admin->fresh()->telegram_id);
        $this->assertSame(0, MagicLinkToken::count());
    }

    /** @test */
    public function link_issuing_is_rate_limited_per_chat(): void
    {
        config(['features.telegram_cabinet_login' => true]);
        $user = $this->linkedStudent();

        for ($i = 0; $i < TelegramLoginService::MAX_LINKS_PER_CHAT; $i++) {
            $this->postUpdate($this->webhookMessage($user->telegram_id, '/вход'));
        }

        $this->postUpdate($this->webhookMessage($user->telegram_id, '/вход'));

        $this->assertSame(TelegramLoginService::MAX_LINKS_PER_CHAT, MagicLinkToken::count());
        $this->assertStringContainsString('Слишком много попыток', $this->lastBotMessage());
    }
}
