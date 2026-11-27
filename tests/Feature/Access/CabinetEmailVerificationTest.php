<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Mail\CabinetEmailVerificationMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\Access\CabinetEmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Мягкое подтверждение почты для кабинета из /кабинет (27-11-2026). */
class CabinetEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.telegram_cabinet_provision' => true,
            'features.telegram_cabinet_login' => true,
            'features.cabinet_email_verification' => true,
            'services.telegram.student_bot_token' => 'test-bot-token',
            'services.telegram.bot_webhook_secret' => 'test-webhook-secret',
        ]);
        Http::fake(fn () => Http::response(['ok' => true]));
        Mail::fake();
    }

    private function provision(string $email): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1, 999999999),
            'message' => [
                'text' => '/кабинет '.$email,
                'chat' => ['id' => 778899001, 'type' => 'private'],
                'from' => ['username' => 'new_student'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    private function verificationUrl(): string
    {
        $url = null;
        Mail::assertQueued(CabinetEmailVerificationMail::class, function (CabinetEmailVerificationMail $mail) use (&$url): bool {
            $url = $mail->verifyUrl;

            return true;
        });

        return (string) parse_url((string) $url, PHP_URL_PATH);
    }

    public function test_provision_sends_verification_mail_and_keeps_login_available(): void
    {
        $this->provision('verify-me@example.com');

        $user = User::where('email', 'verify-me@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(1, MagicLinkToken::where('user_id', $user->id)
            ->where('purpose', CabinetEmailVerification::PURPOSE)->count());
        $this->assertNotNull($user->telegram_id);

        $url = $this->verificationUrl();
        $this->get($url)->assertRedirect(route('student.dashboard'));

        // Ссылка из письма подтверждает и пускает в кабинет.
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_verification_link_is_single_use_and_purpose_scoped(): void
    {
        $user = User::factory()->create();
        $token = MagicLinkToken::issueFor($user, 'newsletter');
        $this->get('/verify-email/'.$token)->assertNotFound();

        $this->provision('replay-me@example.com');
        $url = $this->verificationUrl();
        $this->get($url)->assertRedirect(route('student.dashboard'));
        $this->get($url)->assertNotFound();
    }

    public function test_flag_off_sends_nothing_and_route_is_closed(): void
    {
        config(['features.cabinet_email_verification' => false]);
        $user = User::factory()->create();
        $token = MagicLinkToken::issueFor($user, CabinetEmailVerification::PURPOSE);

        $this->get('/verify-email/'.$token)->assertNotFound();
        Mail::assertNothingQueued();
    }
}
