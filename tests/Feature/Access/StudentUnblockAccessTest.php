<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\AccessAttempt;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\Access\StudentUnblockService;
use App\Support\UsedLoginLinkResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentUnblockAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_link_logs_the_student_in_and_is_one_time(): void
    {
        $student = User::factory()->create();

        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $parts = explode('/', $result['login_link']);
        $token = end($parts);

        // Первый заход — авторизует и ведёт в кабинет.
        $this->get('/login-link/'.$token)
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($student);

        // Второй заход тем же токеном без сессии (одноразовость): не голый 404, а
        // страница входа с понятным текстом (7yogik, 29-09-2026). Не авторизует.
        $this->post('/logout');
        $this->get('/login-link/'.$token)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', UsedLoginLinkResponse::MESSAGE);
        $this->assertGuest();
    }

    public function test_reopening_a_used_link_while_logged_in_goes_to_the_cabinet(): void
    {
        $student = User::factory()->create();
        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $parts = explode('/', $result['login_link']);
        $token = end($parts);

        $this->get('/login-link/'.$token);
        $this->assertAuthenticatedAs($student);

        $this->get('/login-link/'.$token)->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($student);
    }

    public function test_used_unknown_and_foreign_tokens_get_the_same_answer(): void
    {
        $student = User::factory()->create();
        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null);
        $parts = explode('/', $result['login_link']);
        $used = end($parts);
        $this->get('/login-link/'.$used);
        $this->post('/logout');

        // Анти-перебор: использованный, выдуманный и чужого назначения — один ответ.
        foreach ([$used, 'totallyUnknownToken123', MagicLinkToken::issueFor($student, 'newsletter')] as $token) {
            $response = $this->get('/login-link/'.$token);
            $response->assertRedirect(route('login'));
            $response->assertSessionHas('status', UsedLoginLinkResponse::MESSAGE);
            $this->assertGuest();
        }
    }

    public function test_newsletter_purpose_token_is_rejected_at_the_admin_route(): void
    {
        $student = User::factory()->create();
        // Токен другого назначения не должен приниматься админ-маршрутом.
        $token = MagicLinkToken::issueFor($student, 'newsletter');

        $this->get('/login-link/'.$token)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull(MagicLinkToken::findActive($token, 'newsletter'), 'Чужой маршрут токен не гасит.');
    }

    public function test_unblock_marks_pending_attempts_handled(): void
    {
        $student = User::factory()->create(['email' => 'stuck@example.com']);
        AccessAttempt::create([
            'user_id' => $student->id,
            'email' => 'stuck@example.com',
            'kind' => AccessAttempt::KIND_RESET_THROTTLED,
        ]);

        app(StudentUnblockService::class)->unblock($student, adminUserId: $student->id);

        $this->assertNull(AccessAttempt::query()->unhandled()->first());
    }

    public function test_optional_password_reset_changes_the_password(): void
    {
        $student = User::factory()->create();
        $oldHash = $student->password;

        $result = app(StudentUnblockService::class)->unblock($student, adminUserId: null, resetPassword: true);

        $this->assertNotNull($result['password']);
        $this->assertNotSame($oldHash, $student->fresh()->password);
    }

    public function test_reset_request_for_unknown_email_is_logged_as_not_found(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertDatabaseHas('access_attempts', [
            'email' => 'nobody@example.com',
            'kind' => AccessAttempt::KIND_RESET_NOT_FOUND,
        ]);
    }

    public function test_reset_request_for_known_email_is_logged_as_sent(): void
    {
        Mail::fake();
        $student = User::factory()->create(['email' => 'known@example.com']);

        $this->post('/forgot-password', ['email' => 'known@example.com']);

        $this->assertDatabaseHas('access_attempts', [
            'email' => 'known@example.com',
            'kind' => AccessAttempt::KIND_RESET_SENT,
        ]);
    }
}
