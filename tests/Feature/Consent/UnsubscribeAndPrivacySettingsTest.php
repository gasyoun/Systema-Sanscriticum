<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Mail\ContentDigestMail;
use App\Mail\StudentLoginLinkMail;
use App\Models\Consent;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\User;
use App\Support\Unsubscribe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 152-ФЗ / 38-ФЗ ст. 18: отписка по подписанной ссылке (GET — подтверждение,
 * POST — отписка, One-Click RFC 8058), List-Unsubscribe только в рекламных
 * письмах, переключатели рассылки и запрос на удаление данных в кабинете.
 */
class UnsubscribeAndPrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedUser(): User
    {
        return User::factory()->create([
            'email' => 'reader@example.com',
            'wants_email_announcements' => true,
            'wants_messenger_announcements' => true,
            'newsletter_subscribed_at' => now(),
        ]);
    }

    public function test_get_shows_confirmation_and_does_not_unsubscribe(): void
    {
        $user = $this->subscribedUser();

        $this->get(Unsubscribe::url($user->email))
            ->assertOk()
            ->assertSee('Отписаться от рассылки?');

        $this->assertTrue((bool) $user->fresh()->wants_email_announcements);
    }

    public function test_unsigned_or_tampered_link_is_rejected(): void
    {
        $this->subscribedUser();

        $this->get('/otpiska?e='.Unsubscribe::encode('reader@example.com'))->assertForbidden();
        $this->post('/otpiska?e='.Unsubscribe::encode('reader@example.com'))->assertForbidden();
    }

    public function test_post_unsubscribes_user_and_leads_and_journals_withdrawal(): void
    {
        $user = $this->subscribedUser();
        Lead::create(['name' => 'R', 'contact' => 'reader@example.com', 'email' => 'reader@example.com', 'is_promo_agreed' => true]);

        $this->post(Unsubscribe::url('Reader@Example.com'))
            ->assertOk()
            ->assertSee('Вы отписаны');

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->wants_email_announcements);
        $this->assertNull($fresh->newsletter_subscribed_at);
        // Мессенджеры — отдельное согласие, ссылка из письма его не трогает.
        $this->assertTrue((bool) $fresh->wants_messenger_announcements);
        $this->assertFalse((bool) Lead::where('email', 'reader@example.com')->value('is_promo_agreed'));
        $this->assertDatabaseHas('consents', [
            'user_id' => $user->id,
            'type' => Consent::TYPE_PROMO,
            'action' => Consent::ACTION_WITHDRAWN,
            'source' => 'unsubscribe:email',
        ]);
        // Транзакционная почта не блокируется.
        $this->assertDatabaseMissing('suppressed_emails', ['email' => 'reader@example.com']);
    }

    public function test_one_click_post_returns_200_without_csrf(): void
    {
        $user = $this->subscribedUser();

        $this->post(Unsubscribe::url($user->email), ['List-Unsubscribe' => 'One-Click'])
            ->assertOk();

        $this->assertFalse((bool) $user->fresh()->wants_email_announcements);
    }

    public function test_marketing_mail_carries_list_unsubscribe_header_and_footer_link(): void
    {
        $mail = (new ContentDigestMail('Дайджест', 'Текст'))->to('reader@example.com');

        $headers = $mail->headers()->text;
        $this->assertStringContainsString('/otpiska?', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        $html = $mail->render();
        $this->assertStringContainsString('Отписаться от рассылки', $html);
        $this->assertStringContainsString('/otpiska?', $html);
    }

    public function test_transactional_mail_has_no_unsubscribe(): void
    {
        $user = User::factory()->create();
        $mail = new StudentLoginLinkMail($user, 'https://example.com/login');

        $this->assertFalse(method_exists($mail, 'unsubscribeUrl'));
    }

    public function test_cabinet_toggles_update_flags_and_journal(): void
    {
        $user = $this->subscribedUser();

        $this->actingAs($user)
            ->post(route('student.notifications.update'), [])
            ->assertRedirect();

        $this->assertFalse((bool) $user->fresh()->wants_email_announcements);
        $this->assertFalse((bool) $user->fresh()->wants_messenger_announcements);
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => Consent::TYPE_PROMO, 'action' => Consent::ACTION_WITHDRAWN, 'source' => 'cabinet:settings']);

        $this->actingAs($user)
            ->post(route('student.notifications.update'), ['email_announcements' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) $user->fresh()->wants_email_announcements);
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => Consent::TYPE_PROMO, 'action' => Consent::ACTION_GIVEN, 'source' => 'cabinet:settings']);
    }

    public function test_deletion_request_creates_curator_task_and_journals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('student.pd-deletion.request'), [])
            ->assertSessionHasErrors('confirm');

        $this->actingAs($user)
            ->post(route('student.pd-deletion.request'), ['confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHas('privacy_status');

        $task = FollowUpTask::query()->latest('id')->firstOrFail();
        $this->assertSame(FollowUpTask::TYPE_OTHER, $task->type);
        $this->assertStringContainsString('удаление персональных данных', $task->note);
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => Consent::TYPE_PD, 'action' => Consent::ACTION_WITHDRAWN]);
    }

    public function test_messages_page_shows_privacy_settings(): void
    {
        $user = $this->subscribedUser();

        $this->actingAs($user)
            ->get(route('student.messages'))
            ->assertOk()
            ->assertSee('Рассылки и персональные данные')
            ->assertSee('name="email_announcements"', false);
    }
}
