<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Лист ожидания одним кликом для вошедшего ученика:
 * - поля не вводятся — имя/контакт/почта берутся из профиля;
 * - повторный клик не создаёт дубль (дедуп email/contact + landing_page_id);
 * - промо-галочка дополнительно включает wants_*_announcements у юзера (additive);
 * - гость в эндпоинт не попадает.
 */
class OneClickWaitlistTest extends TestCase
{
    use RefreshDatabase;

    private LandingPage $landing;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->landing = LandingPage::create([
            'title' => 'Waitlist',
            'slug' => 'waitlist-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function postOneClick(User $user, array $overrides = [])
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        return $this->actingAs($user)->post('/leads/one-click', array_merge([
            'landing_page_id' => $this->landing->id,
        ], $overrides));
    }

    /** @test */
    public function logged_in_student_joins_without_typing_anything(): void
    {
        $user = User::factory()->create([
            'name' => 'Мария И',
            'email' => 'maria@example.com',
            'phone' => '+79990001122',
        ]);

        $this->postOneClick($user)->assertRedirect();

        $lead = Lead::latest('id')->first();
        $this->assertSame($user->id, $lead->user_id);
        $this->assertSame('Мария И', $lead->name);
        // Телефон старше почты в цепочке контактов; email дублируется отдельным полем.
        $this->assertSame('+79990001122', $lead->contact);
        $this->assertSame('maria@example.com', $lead->email);
        $this->assertFalse((bool) $lead->is_promo_agreed);
        $this->assertSame('[one-click]', $lead->utm_content);
    }

    /** @test */
    public function promo_tick_flips_user_announcement_flags_and_stays_additive(): void
    {
        $user = User::factory()->create([
            'wants_email_announcements' => false,
            'wants_messenger_announcements' => false,
        ]);

        $this->postOneClick($user, ['is_promo_agreed' => '1'])->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->wants_email_announcements);
        $this->assertTrue($user->wants_messenger_announcements);
        $this->assertTrue((bool) Lead::latest('id')->first()->is_promo_agreed);
    }

    /** @test */
    public function second_click_does_not_duplicate_the_lead(): void
    {
        $user = User::factory()->create();

        $this->postOneClick($user)->assertRedirect();
        $this->postOneClick($user)->assertRedirect();

        $this->assertSame(1, Lead::where('landing_page_id', $this->landing->id)->count());
    }

    /** @test */
    public function telegram_username_is_used_when_phone_is_empty(): void
    {
        $user = User::factory()->create(['phone' => null, 'telegram_username' => 'maria_i']);

        $this->postOneClick($user)->assertRedirect();

        $this->assertSame('@maria_i', Lead::latest('id')->first()->contact);
    }

    /** @test */
    public function guest_cannot_use_one_click_endpoint(): void
    {
        RateLimiter::clear('lead-submit:127.0.0.1');

        $this->post('/leads/one-click', ['landing_page_id' => $this->landing->id])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Lead::count());
    }
}
