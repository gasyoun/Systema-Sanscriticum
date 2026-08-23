<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H3314 — Sanctum token expiry + per-credential login throttle.
 *
 * Covers the acceptance lock:
 *  - new mobile tokens carry a 90-day expires_at;
 *  - expired tokens → 401, tokens inside their window still authenticate;
 *  - a token issued pre-change (no expires_at) authenticates until its
 *    computed created_at window closes;
 *  - the 6th wrong-password attempt for one account is locked out even from
 *    a different source IP, on web and API alike;
 *  - successful login clears the counters.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKOUT_MESSAGE = 'Слишком много попыток входа. Попробуйте снова позже.';

    protected function setUp(): void
    {
        parent::setUp();

        // Route-level throttle:10,1 / throttle:5,1 остаётся на проде; в этих
        // тестах выключаем его, чтобы 429 всегда означал именно per-credential
        // lockout (H3314), а не generic IP-лимитер роута.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function makeUser(string $password = 'parol-parol-1'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    private function apiLogin(string $email, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'test',
        ]);
    }

    /** @test */
    public function api_login_mints_token_with_ninety_day_expiry(): void
    {
        $user = $this->makeUser();

        $response = $this->apiLogin($user->email, 'parol-parol-1');

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $row = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->expires_at, 'New tokens must carry an explicit expires_at (H3314).');

        $expiresAt = Carbon::parse($row->expires_at);
        $this->assertTrue($expiresAt->between(
            now()->addDays(89)->subMinutes(5),
            now()->addDays(90)->addMinutes(5),
        ), 'expires_at must sit at ~90 days.');
    }

    /** @test */
    public function expired_token_is_rejected_with_401(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('mobile', ['*'], now()->subDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** @test */
    public function token_without_expires_at_authenticates_within_window(): void
    {
        $user = $this->makeUser();

        // Pre-change style token: no explicit expires_at; validity is governed
        // by the configured created_at window (90 days).
        $fresh = $user->createToken('mobile')->plainTextToken;
        $this->withToken($fresh)->getJson('/api/v1/auth/me')->assertOk();
    }

    /** @test */
    public function token_without_expires_at_is_rejected_once_past_the_computed_window(): void
    {
        $user = $this->makeUser();

        // Same pre-change style but issued 91 days ago: past the computed window.
        $aged = $user->createToken('old-device')->plainTextToken;
        DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('name', 'old-device')
            ->update(['created_at' => now()->subDays(91)]);

        $this->withToken($aged)->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    /** @test */
    public function sixth_wrong_password_attempt_locks_account_even_from_a_different_ip(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin($user->email, 'wrong-pass')->assertStatus(422);
        }

        // Attacker rotates IP — the account-wide counter still locks them out.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77']);

        $this->apiLogin($user->email, 'wrong-pass')
            ->assertStatus(429)
            ->assertJsonPath('message', self::LOCKOUT_MESSAGE);

        // Even the CORRECT password is refused while locked (uniform response).
        $this->apiLogin($user->email, 'parol-parol-1')
            ->assertStatus(429)
            ->assertJsonPath('message', self::LOCKOUT_MESSAGE);
    }

    /** @test */
    public function unknown_email_is_locked_out_with_the_same_uniform_message(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin('nobody@example.test', 'whatever')->assertStatus(422);
        }

        $knownLockout = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin($knownLockout->email, 'wrong-pass')->assertStatus(422);
        }

        $this->apiLogin('nobody@example.test', 'whatever')
            ->assertStatus(429)
            ->assertJsonPath('message', self::LOCKOUT_MESSAGE);

        $this->apiLogin($knownLockout->email, 'wrong-pass')
            ->assertStatus(429)
            ->assertJsonPath('message', self::LOCKOUT_MESSAGE);
    }

    /** @test */
    public function successful_login_resets_the_lockout_counters(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            $this->apiLogin($user->email, 'wrong-pass')->assertStatus(422);
        }

        $this->apiLogin($user->email, 'parol-parol-1')->assertOk();

        // Counter was cleared on success: four more failures must not lock out.
        for ($i = 0; $i < 4; $i++) {
            $this->apiLogin($user->email, 'wrong-pass')->assertStatus(422);
        }
        $this->apiLogin($user->email, 'parol-parol-1')->assertOk();
    }

    /** @test */
    public function web_login_locks_after_threshold_even_from_a_different_ip(): void
    {
        config(['login_throttle.max_attempts' => 3]);
        $user = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-pass',
            ])->assertRedirect('/login');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.90']);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'parol-parol-1',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors();
        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertSame(self::LOCKOUT_MESSAGE, $errors->first('email'));
    }

    /** @test */
    public function shop_login_returns_429_json_on_credential_lockout(): void
    {
        config(['login_throttle.max_attempts' => 3]);
        $user = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/shop/login', [
                'email' => $user->email,
                'password' => 'wrong-pass',
            ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99']);

        $this->postJson('/shop/login', [
            'email' => $user->email,
            'password' => 'parol-parol-1',
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', self::LOCKOUT_MESSAGE);
    }

    /** @test */
    public function prune_command_removes_expired_and_overwindow_tokens_only(): void
    {
        $user = $this->makeUser();

        $liveId = $user->tokens()->create([
            'name' => 'live',
            'token' => hash('sha256', bin2hex(random_bytes(20))),
            'abilities' => ['*'],
            'expires_at' => now()->addDays(30),
        ])->id;

        $expiredId = $user->tokens()->create([
            'name' => 'expired',
            'token' => hash('sha256', bin2hex(random_bytes(20))),
            'abilities' => ['*'],
            'expires_at' => now()->subDay(),
        ])->id;

        $legacyId = $user->tokens()->create([
            'name' => 'legacy-no-expiry',
            'token' => hash('sha256', bin2hex(random_bytes(20))),
            'abilities' => ['*'],
        ]);
        DB::table('personal_access_tokens')
            ->where('id', $legacyId->id)
            ->update(['created_at' => now()->subDays(120)]);

        $this->artisan('tokens:prune-expired')->assertSuccessful();

        $remaining = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->pluck('id');

        $this->assertContains($liveId, $remaining, 'Live token must survive the prune.');
        $this->assertNotContains($expiredId, $remaining);
        $this->assertNotContains($legacyId->id, $remaining);
    }
}
