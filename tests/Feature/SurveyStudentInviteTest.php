<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SurveyStudentInviteTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'student-purchase-2026-09';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'surveys.enabled' => true,
            'services.telegram.student_bot_token' => 'test-token',
            'services.telegram.student_bot_username' => 'samskrtamru_bot',
            'app.url' => 'https://samskrte.ru',
        ]);
    }

    /** @test */
    public function unknown_slug_and_disabled_surveys_fail_fast(): void
    {
        config(['surveys.enabled' => false]);
        $this->artisan('surveys:send-student-invites')->assertExitCode(1);

        config(['surveys.enabled' => true]);
        $this->artisan('surveys:send-student-invites', ['--slug' => 'no-such-wave'])
            ->assertExitCode(1);

        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function report_only_run_sends_nothing_and_writes_no_rows(): void
    {
        Http::fake();
        $this->currentStudent();

        $this->artisan('surveys:send-student-invites')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function exclusion_classes_leave_only_really_eligible_students(): void
    {
        $this->fakeTelegramOk();

        $eligible = $this->currentStudent();

        $this->currentStudent(['role' => 'teacher']);
        $this->currentStudent(['role' => 'admin']);
        $this->currentStudent(['wants_messenger_announcements' => false]);
        $this->currentStudent(['telegram_id' => null]);

        $responder = $this->currentStudent();
        SurveyResponse::create(['survey_slug' => 'post3m', 'answers' => [], 'user_id' => $responder->id]);

        $this->currentStudent(['cabinet_invite_sent_at' => now()]);

        $supportInvited = $this->currentStudent();
        DB::table('telegram_support_contacts')->insert([
            'telegram_user_id' => 900001,
            'linked_user_id' => $supportInvited->id,
            'link_invited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitSurveyed = $this->currentStudent();
        $course = Course::factory()->create(['exit_survey_triggered_at' => now()]);
        DB::table('course_user')->insert([
            'course_id' => $course->id,
            'user_id' => $exitSurveyed->id,
            'status' => 'Учится',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alreadyInvited = $this->currentStudent();
        SurveyInvitation::create([
            'survey_slug' => self::SLUG,
            'user_id' => $alreadyInvited->id,
            'telegram_chat_id' => (int) $alreadyInvited->telegram_id,
            'status' => SurveyInvitation::STATUS_SENT,
        ]);

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        // 2 строки: сид уже приглашённого + ровно одна новая отправка.
        $this->assertSame(2, SurveyInvitation::count());
        $new = SurveyInvitation::whereNotNull('telegram_message_id')->sole();
        $this->assertSame($eligible->id, $new->user_id);
    }

    /** @test */
    public function send_logs_message_id_and_rerun_does_not_duplicate(): void
    {
        $this->fakeTelegramOk();

        $this->currentStudent();
        $this->currentStudent();

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        Http::assertSentCount(3);
        $rows = SurveyInvitation::where('status', SurveyInvitation::STATUS_SENT)->get();
        $this->assertSame(2, $rows->count());
        $this->assertSame([4242, 4242], $rows->pluck('telegram_message_id')->all());
        $this->assertNotNull($rows->first()->sent_at);

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        Http::assertSentCount(3);
        $this->assertSame(2, SurveyInvitation::count());
    }

    /** @test */
    public function invitation_text_carries_survey_link_and_promises_no_reward(): void
    {
        $this->fakeTelegramOk();
        $this->currentStudent();

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, 'https://samskrte.ru/anketa/'.self::SLUG)
                && str_contains($text, 'Заполнить опрос')
                && ! str_contains($text, 'наград')
                && ! str_contains($text, 'приз');
        });
    }

    /** @test */
    public function bot_identity_mismatch_aborts_before_any_send(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 778, 'username' => 'some_other_bot', 'is_bot' => true]]),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->currentStudent();

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(1);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
        $this->assertSame(0, SurveyInvitation::count());
    }

    /** @test */
    public function telegram_refusal_marks_failed_and_next_run_retries_once(): void
    {
        $this->currentStudent();

        $blocked = true;
        Http::fake(function ($request) use (&$blocked) {
            if (str_contains($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'samskrtamru_bot', 'is_bot' => true]]);
            }

            if ($blocked) {
                return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 4243]]);
        });

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_FAILED, $row->status);
        $this->assertNull($row->telegram_message_id);
        $this->assertStringContainsString('Forbidden', (string) $row->error);

        $blocked = false;

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        $row->refresh();
        $this->assertSame(SurveyInvitation::STATUS_SENT, $row->status);
        $this->assertSame(4243, $row->telegram_message_id);
        $this->assertSame(1, SurveyInvitation::count());
    }

    /** @test */
    public function connection_timeout_marks_unknown_and_blocks_blind_retry(): void
    {
        $this->currentStudent();

        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'samskrtamru_bot', 'is_bot' => true]]),
            'api.telegram.org/bot*/sendMessage' => fn () => throw new ConnectionException('cURL error 28: https://api.telegram.org/bot123:SECRET/sendMessage'),
        ]);

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        $row = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitation::STATUS_UNKNOWN, $row->status);
        $this->assertNull($row->telegram_message_id);
        $this->assertStringNotContainsString('SECRET', (string) $row->error);
        $this->assertStringContainsString('bot[redacted]', (string) $row->error);

        $this->artisan('surveys:send-student-invites', ['--send' => true])->assertExitCode(0);

        $row->refresh();
        $this->assertSame(SurveyInvitation::STATUS_UNKNOWN, $row->status);
        $this->assertNull($row->telegram_message_id);
    }

    /** Telegram API: getMe confirms the expected bot, sendMessage answers success. */
    private function fakeTelegramOk(int $messageId = 4242): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'username' => 'samskrtamru_bot', 'is_bot' => true]]),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => $messageId]]),
        ]);
    }

    private function currentStudent(array $attrs = []): User
    {
        $defaults = [
            'wants_messenger_announcements' => true,
            'role' => null,
            'is_admin' => false,
        ];

        $user = User::factory()->create(array_merge($defaults, $attrs));

        if (! array_key_exists('telegram_id', $attrs)) {
            $user->forceFill(['telegram_id' => 510000000 + $user->id])->save();
        }

        $group = Group::factory()->create(['status' => 'active']);
        $group->users()->attach($user->id);

        return $user->refresh();
    }
}
