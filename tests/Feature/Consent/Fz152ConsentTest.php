<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use App\Models\Consent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 152-ФЗ: журнал согласий, галочки ПДн в формах, рассылка только по явной
 * галочке. Серверная обязательность — за флагами pd_consent_enforce /
 * checkout_pd_consent_enforce (дефолт OFF).
 */
class Fz152ConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('lead-submit:127.0.0.1');
    }

    private function leadPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Иван',
            'contact' => '+79991234567',
            'email' => 'lead'.uniqid().'@example.com',
            'form_name' => 'test_form',
        ], $overrides);
    }

    public function test_flags_default_off(): void
    {
        $this->assertFalse((bool) config('features.pd_consent_enforce'));
        $this->assertFalse((bool) config('features.checkout_pd_consent_enforce'));
    }

    public function test_lead_with_pd_and_promo_consent_is_journaled_with_doc_version(): void
    {
        $this->post('/leads/store', $this->leadPayload([
            'email' => 'journal@example.com',
            'pd_consent' => '1',
            'is_promo_agreed' => '1',
        ]))->assertRedirect(route('thank.you'));

        $pd = Consent::where('type', Consent::TYPE_PD)->where('email', 'journal@example.com')->firstOrFail();
        $this->assertSame(Consent::ACTION_GIVEN, $pd->action);
        $this->assertSame(config('consent.documents.pd.version'), $pd->doc_version);
        $this->assertSame('lead:test_form', $pd->source);
        $this->assertNotNull($pd->lead_id);
        $this->assertSame('127.0.0.1', $pd->ip_address);

        $this->assertDatabaseHas('consents', ['type' => Consent::TYPE_PROMO, 'email' => 'journal@example.com', 'action' => Consent::ACTION_GIVEN]);
    }

    public function test_lead_without_pd_consent_passes_when_flag_off_and_writes_no_pd_row(): void
    {
        $this->post('/leads/store', $this->leadPayload(['email' => 'nopd@example.com']))
            ->assertRedirect(route('thank.you'));

        $this->assertDatabaseHas('leads', ['email' => 'nopd@example.com']);
        $this->assertDatabaseMissing('consents', ['email' => 'nopd@example.com', 'type' => Consent::TYPE_PD]);
    }

    public function test_lead_without_pd_consent_is_rejected_when_flag_on(): void
    {
        config()->set('features.pd_consent_enforce', true);

        $this->post('/leads/store', $this->leadPayload(['email' => 'reject@example.com']))
            ->assertSessionHasErrors('pd_consent');

        $this->assertDatabaseMissing('leads', ['email' => 'reject@example.com']);
    }

    public function test_new_user_does_not_get_email_announcements_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertFalse((bool) $user->fresh()->wants_email_announcements);
        $this->assertFalse((bool) $user->fresh()->wants_messenger_announcements);
    }

    public function test_register_subscribes_only_with_explicit_promo_box(): void
    {
        config()->set('features.guest_registration', true);

        $this->post('/register', [
            'email' => 'nopromo@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'pd_consent' => '1',
        ])->assertRedirect();

        $user = User::where('email', 'nopromo@example.com')->firstOrFail();
        $this->assertFalse((bool) $user->wants_email_announcements);
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => Consent::TYPE_PD, 'source' => 'register']);
        $this->assertDatabaseMissing('consents', ['user_id' => $user->id, 'type' => Consent::TYPE_PROMO]);

        auth()->logout();

        $this->post('/register', [
            'email' => 'promo@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'pd_consent' => '1',
            'is_promo_agreed' => '1',
        ])->assertRedirect();

        $promoUser = User::where('email', 'promo@example.com')->firstOrFail();
        $this->assertTrue((bool) $promoUser->wants_email_announcements);
        $this->assertDatabaseHas('consents', ['user_id' => $promoUser->id, 'type' => Consent::TYPE_PROMO]);
    }

    public function test_register_page_shows_pd_consent_box(): void
    {
        config()->set('features.guest_registration', true);

        $this->get('/register')
            ->assertOk()
            ->assertSee('name="pd_consent"', false)
            ->assertSee(route('docs.show', 'soglasie-pd'), false);
    }

    public function test_promo_component_is_never_prechecked(): void
    {
        $html = $this->blade('<x-consent.promo name="wants_announcements" />');

        $html->assertSee('name="wants_announcements"', false);
        $html->assertDontSee('checked', false);
    }

    public function test_pd_component_required_follows_prop(): void
    {
        $this->blade('<x-consent.pd />')->assertSee('required', false);
        $this->blade('<x-consent.pd :required="false" />')->assertDontSee('required', false);
    }

    public function test_recorder_never_breaks_the_form_when_journal_fails(): void
    {
        Schema::drop('consents');

        $this->post('/leads/store', $this->leadPayload(['email' => 'survive@example.com', 'pd_consent' => '1']))
            ->assertRedirect(route('thank.you'));

        $this->assertDatabaseHas('leads', ['email' => 'survive@example.com']);
    }
}
