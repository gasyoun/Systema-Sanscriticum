<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * H4462 — аудит-след тихой перезаписи пароля (инцидент 09-09-2026: smoke-студент
 * id=6857 перезаписан локальным актором при last_login 127.0.0.1, ни одной строки
 * в логах). Мутатор setPasswordAttribute логирует ПЕРЕзапись хеша у существующего
 * пользователя: writer (CLI / HTTP ip), auth_id, session — без пароля и хеша.
 */
class PasswordRewriteAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    /** Log::spy() мокирует LogManager; пишем через Log::channel(...)->info(...),
     * поэтому channel() -> andReturnSelf() держит info() на том же mock. */
    private function spyAuditLog(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
    }

    #[Test]
    public function rewrite_of_existing_user_hash_logs_audit_event(): void
    {
        $user = User::factory()->create(['password' => Hash::make('first-pass')]);

        $this->spyAuditLog();

        $user->forceFill(['password' => 'second-pass'])->save();

        Log::shouldHaveReceived('channel')
            ->withArgs(fn (string $name): bool => $name === config('services.password_audit.channel'))
            ->once();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $event, array $ctx): bool {
                return $event === 'password.rewritten'
                    && is_int($ctx['user_id'] ?? null)
                    && is_string($ctx['email'] ?? null)
                    && str_starts_with((string) ($ctx['writer'] ?? ''), 'cli:')
                    && ($ctx['auth_id'] ?? null) === null
                    && ($ctx['session_id'] ?? null) === null;
            });
    }

    #[Test]
    public function creation_does_not_log(): void
    {
        Log::spy();

        User::factory()->create(['password' => Hash::make('fresh-pass')]);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('channel');
    }

    #[Test]
    public function plain_password_is_hashed_by_mutator_and_login_works(): void
    {
        $user = User::factory()->make(['email' => 'audit@example.com']);
        $user->password = 'plain-secret';
        $user->save();

        $this->assertTrue(Hash::check('plain-secret', $user->fresh()->password));
    }

    #[Test]
    public function already_hashed_value_is_stored_verbatim(): void
    {
        $hash = Hash::make('pre-hashed');
        $user = User::factory()->create(['password' => $hash]);

        $this->assertSame($hash, $user->fresh()->password);
    }

    #[Test]
    public function reassigning_same_hash_is_not_a_rewrite_event(): void
    {
        $hash = Hash::make('stable-pass');
        $user = User::factory()->create(['password' => $hash]);

        Log::spy();

        // Тот же хеш + новый email → save() идёт, а лог перезаписи — нет.
        $user->forceFill(['email' => 'moved@example.com', 'password' => $hash])->save();

        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function ensure_test_student_rewrite_produces_audit_line(): void
    {
        config(['services.test_student.email' => 'smoke@example.com']);
        config(['services.test_student.password' => 'smoke-pass-1']);

        $this->artisan('users:ensure-test-student')->assertSuccessful();

        $this->spyAuditLog();

        config(['services.test_student.password' => 'smoke-pass-2']);
        $this->artisan('users:ensure-test-student')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $event, array $ctx): bool {
                return $event === 'password.rewritten'
                    && str_starts_with((string) ($ctx['writer'] ?? ''), 'cli:')
                    && ($ctx['email'] ?? null) === 'smoke@example.com';
            })
            ->atLeast()->once();
    }
}
