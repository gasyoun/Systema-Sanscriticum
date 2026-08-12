<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Filament\Resources\AccessAttemptResource\Pages\ListAccessAttempts;
use App\Mail\StudentLoginLinkMail;
use App\Models\AccessAttempt;
use App\Models\User;
use App\Services\Access\LoginLinkNotifier;
use App\Services\Access\StudentUnblockService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Доставка одноразовой ссылки для входа студенту (кнопка «Разблокировать»).
 * Раньше ссылка только показывалась куратору — теперь уходит по каналам.
 */
class LoginLinkNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();   // Telegram/VK/Max наружу не ходят
    }

    public function test_both_channels_enabled_send_the_link(): void
    {
        Mail::fake();
        $student = User::factory()->create([
            'email' => 'stuck@example.com',
            'telegram_id' => '111222333',
        ]);

        $report = app(LoginLinkNotifier::class)->notify($student, 'https://example.test/login-link/abc');

        $this->assertSame('sent', $report['email']);
        $this->assertSame('sent', $report['telegram']);
        $this->assertSame('skipped:no_vk_id', $report['vk']);
        $this->assertSame('skipped:no_max_id', $report['max']);

        Mail::assertQueued(StudentLoginLinkMail::class, fn (StudentLoginLinkMail $mail): bool => $mail->hasTo('stuck@example.com')
            && $mail->loginUrl === 'https://example.test/login-link/abc');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) ($request['text'] ?? ''), 'https://example.test/login-link/abc'));
    }

    public function test_disabled_toggles_are_reported_as_skipped(): void
    {
        Mail::fake();
        $student = User::factory()->create([
            'email' => 'stuck@example.com',
            'telegram_id' => '111222333',
        ]);

        $report = app(LoginLinkNotifier::class)
            ->notify($student, 'https://example.test/login-link/abc', sendEmail: false, sendMessengers: false);

        $this->assertSame(
            ['skipped:disabled', 'skipped:disabled', 'skipped:disabled', 'skipped:disabled'],
            array_values($report)
        );

        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_invalid_email_is_skipped_not_failed(): void
    {
        Mail::fake();
        $student = User::factory()->create(['email' => 'ivan@no-email.com']);

        $report = app(LoginLinkNotifier::class)->notify($student, 'https://example.test/login-link/abc');

        $this->assertSame('skipped:invalid_email', $report['email']);
        $this->assertFalse(LoginLinkNotifier::hasDeliverableEmail($student));
        Mail::assertNothingQueued();
    }

    public function test_temporary_password_never_leaves_in_the_message(): void
    {
        Mail::fake();
        $student = User::factory()->create([
            'email' => 'stuck@example.com',
            'telegram_id' => '111222333',
        ]);

        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null, resetPassword: true);
        $password = (string) $result['password'];
        $this->assertNotSame('', $password);

        app(LoginLinkNotifier::class)->notify($student, $result['login_link']);

        // Пароль остаётся куратору: ни в письме, ни в сообщении бота его нет.
        Mail::assertQueued(
            StudentLoginLinkMail::class,
            fn (StudentLoginLinkMail $mail): bool => ! str_contains($mail->render(), $password)
        );
        Http::assertSent(fn ($request): bool => ! str_contains((string) ($request['text'] ?? ''), $password));
    }

    public function test_link_from_the_notifier_still_logs_the_student_in(): void
    {
        Mail::fake();
        $student = User::factory()->create(['email' => 'stuck@example.com']);

        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $report = app(LoginLinkNotifier::class)->notify($student, $result['login_link']);
        $this->assertSame('sent', $report['email']);

        $sentUrl = null;
        Mail::assertQueued(StudentLoginLinkMail::class, function (StudentLoginLinkMail $mail) use (&$sentUrl): bool {
            $sentUrl = $mail->loginUrl;

            return true;
        });

        $parts = explode('/', (string) $sentUrl);
        $this->get('/login-link/'.end($parts))->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($student);
    }

    /** Кнопка «Разблокировать» в ленте действительно доводит ссылку до студента. */
    public function test_unblock_action_in_the_feed_delivers_the_link(): void
    {
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create([
            'email' => 'stuck@example.com',
            'telegram_id' => '111222333',
        ]);
        $attempt = AccessAttempt::create([
            'user_id' => $student->id,
            'email' => 'stuck@example.com',
            'kind' => AccessAttempt::KIND_LOCKOUT,
        ]);

        Livewire::test(ListAccessAttempts::class)
            ->callTableAction('unblock', $attempt, data: [
                'send_email' => true,
                'send_messengers' => true,
                'reset_password' => false,
            ])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(StudentLoginLinkMail::class, fn (StudentLoginLinkMail $mail): bool => $mail->hasTo('stuck@example.com'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));
        $this->assertNotNull($attempt->fresh()->handled_at);
    }

    /**
     * Отправка студенту — opt-in: до H849-доработки кнопка ему не писала вообще,
     * и куратор, привыкший «нажал → скопировал → отправил сам», иначе продублировал
     * бы сообщение. Умолчания закреплены тестом, чтобы их не вернули незаметно.
     */
    public function test_delivery_toggles_are_off_by_default(): void
    {
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create(['email' => 'stuck@example.com', 'telegram_id' => '111222333']);
        $attempt = AccessAttempt::create([
            'user_id' => $student->id,
            'email' => 'stuck@example.com',
            'kind' => AccessAttempt::KIND_LOCKOUT,
        ]);

        Livewire::test(ListAccessAttempts::class)
            ->mountTableAction('unblock', $attempt)
            ->assertTableActionDataSet([
                'send_email' => false,
                'send_messengers' => false,
                'reset_password' => false,
            ]);
    }

    /** Снятые тумблеры в модалке = ничего студенту не уходит. */
    public function test_unblock_action_respects_disabled_toggles(): void
    {
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));

        $student = User::factory()->create([
            'email' => 'stuck@example.com',
            'telegram_id' => '111222333',
        ]);
        $attempt = AccessAttempt::create([
            'user_id' => $student->id,
            'email' => 'stuck@example.com',
            'kind' => AccessAttempt::KIND_LOCKOUT,
        ]);

        Livewire::test(ListAccessAttempts::class)
            ->callTableAction('unblock', $attempt, data: [
                'send_email' => false,
                'send_messengers' => false,
                'reset_password' => false,
            ])
            ->assertHasNoTableActionErrors();

        Mail::assertNothingQueued();
        Http::assertNothingSent();
        $this->assertNotNull($attempt->fresh()->handled_at);
    }

    public function test_report_summary_is_human_readable(): void
    {
        $summary = LoginLinkNotifier::reportSummary([
            'telegram' => 'sent',
            'email' => 'skipped:invalid_email',
            'vk' => 'skipped:no_vk_id',
            'max' => 'skipped:disabled',
        ]);

        $this->assertSame(
            'Telegram: отправлено · Почта: некорректный адрес · VK: нет контакта · Max: выключено',
            $summary
        );
    }
}
