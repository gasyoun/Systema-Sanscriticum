<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\AccessAttempt;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\Access\StudentUnblockService;
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
        $token = str_replace(url('/login-link/'), '', $result['login_link']);

        // Первый заход — авторизует и ведёт в кабинет.
        $this->get('/login-link/'.$token)
            ->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($student);

        // Второй заход тем же токеном — 404 (одноразовость).
        $this->post('/logout');
        $this->get('/login-link/'.$token)->assertNotFound();
    }

    public function test_newsletter_purpose_token_is_rejected_at_the_admin_route(): void
    {
        $student = User::factory()->create();
        // Токен другого назначения не должен приниматься админ-маршрутом.
        $token = MagicLinkToken::issueFor($student, 'newsletter');

        $this->get('/login-link/'.$token)->assertNotFound();
        $this->assertGuest();
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
