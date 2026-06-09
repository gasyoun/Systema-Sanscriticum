<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Models\User;
use App\Models\UserSession;
use App\Services\Activity\ActivityTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * startSession переиспользует строку по session_id (колонка уникальна).
 * Регрессия: вернувшийся после CloseStaleSessionsJob/logout студент приходит с
 * тем же session_id — INSERT упирался в unique (1062 Duplicate entry).
 */
class StartSessionReuseTest extends TestCase
{
    use RefreshDatabase;

    private function requestWithSession(string $sessionId): Request
    {
        $request = Request::create('/dashboard', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36',
        ]);
        $store = new Store('test_session', new ArraySessionHandler(120), $sessionId);
        $request->setLaravelSession($store);

        return $request;
    }

    /** @test */
    public function reactivates_closed_session_instead_of_inserting_duplicate(): void
    {
        $user = User::factory()->create();
        $sessionId = Str::random(40);

        // Закрытая сессия с тем же session_id (как после CloseStaleSessionsJob).
        $closed = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subMinutes(40),
            'ended_at' => now()->subMinutes(40),
            'duration_seconds' => 1200,
            'pages_viewed' => 7,
            'is_active' => false,
        ]);

        $session = app(ActivityTracker::class)->startSession($user, $this->requestWithSession($sessionId));

        // Никакого дубля: на этот session_id по-прежнему одна строка — та же.
        $this->assertSame(1, UserSession::where('session_id', $sessionId)->count());
        $this->assertSame($closed->id, $session->id);

        // Реактивирована под новый период.
        $session->refresh();
        $this->assertTrue($session->is_active);
        $this->assertNull($session->ended_at);
        $this->assertSame(0, $session->duration_seconds);
        $this->assertSame(0, $session->pages_viewed);
    }

    /** @test */
    public function returns_existing_active_session_without_creating_new(): void
    {
        $user = User::factory()->create();
        $sessionId = Str::random(40);

        $active = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'started_at' => now()->subMinutes(5),
            'last_heartbeat_at' => now()->subMinute(),
            'is_active' => true,
        ]);

        $session = app(ActivityTracker::class)->startSession($user, $this->requestWithSession($sessionId));

        $this->assertSame($active->id, $session->id);
        $this->assertSame(1, UserSession::where('session_id', $sessionId)->count());
    }

    /** @test */
    public function creates_session_when_none_exists(): void
    {
        $user = User::factory()->create();
        $sessionId = Str::random(40);

        $session = app(ActivityTracker::class)->startSession($user, $this->requestWithSession($sessionId));

        $this->assertSame($sessionId, $session->session_id);
        $this->assertTrue($session->is_active);
        $this->assertSame('desktop', $session->device_type);
    }
}
