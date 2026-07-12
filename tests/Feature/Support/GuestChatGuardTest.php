<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Auth\GuestChatUser;
use App\Support\GuestChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Гостевой broadcast-guard `chat_guest` и session-токен GuestChat (H536 Phase 2).
 */
class GuestChatGuardTest extends TestCase
{
    public function test_guest_chat_token_is_created_once_and_persisted(): void
    {
        $this->assertNull(GuestChat::currentToken());

        $token = GuestChat::token();

        $this->assertNotEmpty($token);
        $this->assertSame($token, GuestChat::token());       // стабилен в рамках сессии
        $this->assertSame($token, GuestChat::currentToken());
    }

    public function test_chat_guest_guard_resolves_guest_user_from_session_token(): void
    {
        $request = Request::create('/broadcasting/auth', 'POST');
        $session = app('session')->driver();
        $session->put(GuestChat::SESSION_KEY, 'sess-tok-123');
        $request->setLaravelSession($session);

        app()->instance('request', $request);
        Auth::forgetGuards();

        $user = Auth::guard('chat_guest')->user();

        $this->assertInstanceOf(GuestChatUser::class, $user);
        $this->assertSame('sess-tok-123', $user->getAuthIdentifier());
    }

    public function test_chat_guest_guard_is_null_without_a_token(): void
    {
        $request = Request::create('/broadcasting/auth', 'POST');
        $request->setLaravelSession(app('session')->driver());

        app()->instance('request', $request);
        Auth::forgetGuards();

        $this->assertNull(Auth::guard('chat_guest')->user());
    }
}
