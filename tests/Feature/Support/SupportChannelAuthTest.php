<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Auth\GuestChatUser;
use App\Models\SupportConversation;
use App\Models\User;
use App\Support\Roles;
use App\Support\SupportChannelAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Предикат авторизации приватного канала support.conversation.{id} (H536 Phase 2).
 * Тестируется в отрыве от запущенного Reverb.
 */
class SupportChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_with_matching_token_is_authorized(): void
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-owner',
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertTrue(SupportChannelAuth::canAccess(new GuestChatUser('tok-owner'), $thread->id));
    }

    public function test_guest_with_wrong_token_is_rejected(): void
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-owner',
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertFalse(SupportChannelAuth::canAccess(new GuestChatUser('tok-intruder'), $thread->id));
    }

    public function test_owning_student_is_authorized_but_other_student_is_not(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thread = SupportConversation::create([
            'user_id' => $owner->id,
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertTrue(SupportChannelAuth::canAccess($owner, $thread->id));
        $this->assertFalse(SupportChannelAuth::canAccess($other, $thread->id));
    }

    public function test_admin_operator_may_listen_to_any_conversation(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $guestThread = SupportConversation::create([
            'guest_token' => 'tok-x',
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertTrue(SupportChannelAuth::canAccess($admin, $guestThread->id));
    }

    public function test_null_user_is_rejected(): void
    {
        $thread = SupportConversation::create([
            'guest_token' => 'tok-owner',
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertFalse(SupportChannelAuth::canAccess(null, $thread->id));
    }

    public function test_guest_token_is_not_matched_against_a_user_conversation(): void
    {
        // Тред залогиненного пользователя не должен «подхватываться» гостем даже с
        // пустым/чужим токеном — guest_token у него NULL.
        $user = User::factory()->create();
        $thread = SupportConversation::create([
            'user_id' => $user->id,
            'status' => SupportConversation::STATUS_OPEN,
        ]);

        $this->assertFalse(SupportChannelAuth::canAccess(new GuestChatUser(''), $thread->id));
        $this->assertFalse(SupportChannelAuth::canAccess(new GuestChatUser('anything'), $thread->id));
    }
}
