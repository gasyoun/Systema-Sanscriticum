<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NewsletterMagnetsMail;
use App\Models\Lead;
use App\Models\MagicLinkToken;
use App\Models\SubscriberMagnet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * H324 — подписка на рассылку → кабинетный User через magic-link + полка магнитов.
 *
 * Инварианты доступа: magic-link одноразовый/протухающий/hashed-at-rest; платный
 * доступ не расширяется; всё за флагом newsletter_subscribe (OFF → 404 + виджет
 * скрыт). «Полка подписчика» ортогональна оплатам (никаких групп/уроков/тарифов).
 */
class NewsletterSubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['features.newsletter_subscribe' => true]);
    }

    /** Зашифрованная метка времени рендера формы «N секунд назад» (time-trap). */
    private function timeTrap(int $secondsAgo = 5): string
    {
        return encrypt((string) (now()->timestamp - $secondsAgo));
    }

    /** Валидный «человеческий» payload: пустой honeypot + свежая метка времени. */
    private function humanPayload(string $email, array $overrides = []): array
    {
        return array_merge([
            'email' => $email,
            'is_promo_agreed' => '1',
            'website' => '', // honeypot пуст
            'ff_ts' => $this->timeTrap(),
        ], $overrides);
    }

    public function test_subscribe_creates_lead_and_user_and_queues_mail(): void
    {
        $response = $this->post('/subscribe', $this->humanPayload('New@Example.com'));

        $response->assertRedirect();
        $response->assertSessionHas('newsletter_subscribed', true);

        // User заведён (email нормализован), помечен подписчиком.
        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->newsletter_subscribed_at);

        // Lead-строка для CRM/UTM записана.
        $this->assertDatabaseHas('leads', ['email' => 'new@example.com']);

        // Одноразовый токен выпущен; письмо поставлено в очередь.
        $this->assertDatabaseCount('magic_link_tokens', 1);
        Mail::assertQueued(NewsletterMagnetsMail::class);
    }

    public function test_subscribe_requires_consent(): void
    {
        $this->post('/subscribe', ['email' => 'a@b.com'])
            ->assertSessionHasErrors('is_promo_agreed');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_user_is_reused_not_duplicated(): void
    {
        $existing = User::factory()->create(['email' => 'known@example.com']);

        $this->post('/subscribe', $this->humanPayload('known@example.com'))
            ->assertRedirect();

        $this->assertSame(1, User::where('email', 'known@example.com')->count());
        $this->assertNotNull($existing->fresh()->newsletter_subscribed_at);
    }

    public function test_bot_honeypot_filled_is_silently_dropped(): void
    {
        $response = $this->post('/subscribe', $this->humanPayload('bot@example.com', [
            'website' => 'http://spam.example', // honeypot заполнен ботом
        ]));

        // Анти-enumeration: ответ как при успехе, но ничего не создано.
        $response->assertRedirect();
        $response->assertSessionHas('newsletter_subscribed', true);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('leads', 0);
        Mail::assertNothingQueued();
    }

    public function test_direct_post_without_time_trap_token_is_dropped(): void
    {
        // Прямой POST по эндпоинту без метки рендера формы (типичный спам-бот).
        $this->post('/subscribe', [
            'email' => 'bot@example.com',
            'is_promo_agreed' => '1',
        ])->assertSessionHas('newsletter_subscribed', true);

        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingQueued();
    }

    public function test_instant_submit_is_dropped(): void
    {
        // Метка есть, но сабмит мгновенный (elapsed < min_fill_seconds).
        $this->post('/subscribe', $this->humanPayload('bot@example.com', [
            'ff_ts' => $this->timeTrap(0),
        ]))->assertSessionHas('newsletter_subscribed', true);

        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingQueued();
    }

    public function test_blocked_disposable_domain_is_dropped(): void
    {
        config(['newsletter.antibot.blocked_email_domains' => ['immenseignite.info']]);

        $this->post('/subscribe', $this->humanPayload('gjjefxxi@immenseignite.info'))
            ->assertSessionHas('newsletter_subscribed', true);

        $this->assertDatabaseCount('users', 0);
        Mail::assertNothingQueued();
    }

    public function test_magic_link_logs_in_once_and_only_once(): void
    {
        $user = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter');

        // Первый заход — логинит и ведёт в кабинет.
        $this->get('/magic/'.$plaintext)
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);

        // Токен погашен.
        $this->assertNotNull(MagicLinkToken::first()->consumed_at);

        // Replay из свежей гостевой сессии → 404, без входа.
        auth()->logout();
        $this->get('/magic/'.$plaintext)->assertNotFound();
        $this->assertGuest();
    }

    public function test_expired_magic_link_is_rejected(): void
    {
        $user = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter');
        MagicLinkToken::first()->update(['expires_at' => now()->subMinute()]);

        $this->get('/magic/'.$plaintext)->assertNotFound();
        $this->assertGuest();
    }

    public function test_invalid_magic_token_is_404(): void
    {
        $this->get('/magic/deadbeefdeadbeef')->assertNotFound();
        $this->assertGuest();
    }

    public function test_token_is_hashed_at_rest(): void
    {
        $user = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter');

        // Plaintext НЕ хранится; в БД лежит sha256-hash.
        $stored = MagicLinkToken::first()->token_hash;
        $this->assertNotSame($plaintext, $stored);
        $this->assertSame(hash('sha256', $plaintext), $stored);
    }

    public function test_subscriber_shelf_shows_active_magnets_in_cabinet(): void
    {
        SubscriberMagnet::create(['title' => 'Стартовый PDF', 'url' => 'https://example.com/a.pdf', 'is_active' => true, 'sort_order' => 1]);
        SubscriberMagnet::create(['title' => 'Скрытый', 'url' => 'https://example.com/b.pdf', 'is_active' => false, 'sort_order' => 2]);

        $user = User::factory()->create(['newsletter_subscribed_at' => now()]);

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Полка подписчика')
            ->assertSee('Стартовый PDF')
            ->assertDontSee('Скрытый');
    }

    public function test_non_subscriber_does_not_see_shelf(): void
    {
        SubscriberMagnet::create(['title' => 'Стартовый PDF', 'url' => 'https://example.com/a.pdf', 'is_active' => true]);

        $user = User::factory()->create(['newsletter_subscribed_at' => null]);

        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Полка подписчика');
    }

    public function test_flag_off_404s_routes(): void
    {
        config(['features.newsletter_subscribe' => false]);

        $this->post('/subscribe', ['email' => 'x@y.com', 'is_promo_agreed' => '1'])
            ->assertNotFound();

        $user = User::factory()->create();
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter');
        $this->get('/magic/'.$plaintext)->assertNotFound();
    }

    public function test_floating_popup_renders_on_home_with_student_count(): void
    {
        config([
            'features.newsletter_subscribe' => true,
            'trust.graduates_count' => 5000,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-analytics="newsletter-subscribe-popup"', false)
            ->assertSee('5 000+', false)
            ->assertSee('русскоязычным студентам санскрита', false)
            ->assertSee('utm_source" value="newsletter_popup"', false);
    }

    public function test_floating_popup_hidden_when_flag_off(): void
    {
        config(['features.newsletter_subscribe' => false]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('newsletter-subscribe-popup', false);
    }

    public function test_floating_popup_hidden_for_existing_subscriber(): void
    {
        config(['features.newsletter_subscribe' => true]);

        $user = User::factory()->create(['newsletter_subscribed_at' => now()]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertDontSee('data-analytics="newsletter-subscribe-popup"', false);
    }
}
