<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Упрощённая форма заявки: один контакт, без имени.
 * - email-подобный контакт дублируется в поле email (дедуп/письма);
 * - телефон остаётся только в contact, email пустой;
 * - имя необязательно (leads.name nullable);
 * - дедуп телефон-only лидов идёт по contact, а не ловит чужой null-email.
 */
class MinimalLeadFormTest extends TestCase
{
    use RefreshDatabase;

    private LandingPage $landing;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->landing = LandingPage::create([
            'title' => 'Minimal Landing',
            'slug' => 'minimal-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function postContact(string $contact, array $overrides = [])
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        return $this->post('/leads/store', array_merge([
            'contact' => $contact,
            'landing_page_id' => $this->landing->id,
        ], $overrides));
    }

    /** @test */
    public function email_like_contact_is_copied_into_email_field(): void
    {
        $this->postContact('student@example.com')->assertRedirect();

        $lead = Lead::latest('id')->first();
        $this->assertSame('student@example.com', $lead->contact);
        $this->assertSame('student@example.com', $lead->email);
        $this->assertNull($lead->name);
    }

    /** @test */
    public function phone_contact_leaves_email_empty(): void
    {
        $this->postContact('+79991234567')->assertRedirect();

        $lead = Lead::latest('id')->first();
        $this->assertSame('+79991234567', $lead->contact);
        $this->assertNull($lead->email);
        $this->assertNull($lead->name);
    }

    /** @test */
    public function different_phone_only_leads_are_not_deduped(): void
    {
        $this->postContact('+79990000001')->assertRedirect();
        $this->postContact('+79990000002')->assertRedirect();

        // Без починки дедупа второй лид схлопнулся бы по email IS NULL.
        $this->assertSame(2, Lead::where('landing_page_id', $this->landing->id)->count());
    }

    /** @test */
    public function same_phone_on_same_landing_is_deduped(): void
    {
        $this->postContact('+79995550000')->assertRedirect();
        $this->postContact('+79995550000')->assertRedirect();

        $this->assertSame(1, Lead::where('landing_page_id', $this->landing->id)->count());
    }
}
