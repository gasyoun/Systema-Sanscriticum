<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1294 — просьба о рекомендации. Три поверхности: кабинет (основная вкладка),
 * страница успешной оплаты (пик доверия), именной баннер приглашенного на
 * главной. Тексты только читают состояние — механика награды (ReferralService)
 * покрыта ReferralProgramTest и здесь не дублируется.
 */
class ReferralAskSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    public function test_cabinet_shows_recommendation_ask_with_credit_explained(): void
    {
        config(['referral.credit_amount' => 500]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dvaram');

        $response->assertOk();
        $response->assertSee('Порекомендовать школу');
        $response->assertSee('в знак благодарности');
        // Ссылка-приглашение сгенерирована лениво и отображена.
        $response->assertSee($user->fresh()->referral_code);
        // Бонусная рамка старого блока ушла.
        $response->assertDontSee('Приглашайте друзей');
    }

    public function test_cabinet_hides_zero_counters(): void
    {
        $user = User::factory()->create(['referral_credit' => 0]);

        $response = $this->actingAs($user)->get('/dvaram');

        $response->assertOk();
        // Нулевые счетчики не рендерятся: ноль читается как упрек.
        $response->assertDontSee('Пришли по вашей рекомендации');
        $response->assertDontSee('Начислено за рекомендации');
    }

    public function test_success_page_confirmed_state_carries_referral_ask(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $response = $this->actingAs($user)->get('/payment/success');

        $response->assertOk();
        $response->assertSee('Порекомендовать школу');
        $response->assertSee('в знак благодарности');
    }

    public function test_success_page_pending_state_has_no_referral_ask(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'pending',
        ]));

        $response = $this->actingAs($user)->get('/payment/success');

        $response->assertOk();
        // Пока оплата не подтверждена, просьба неуместна — читатель ждет доступ.
        $response->assertDontSee('Порекомендовать школу');
    }

    public function test_home_greets_referred_visitor_by_referrer_name(): void
    {
        $referrer = User::factory()->create(['name' => 'Ирина Петрова']);
        $code = $referrer->referralCode();

        $response = $this->get('/?ref='.$code);

        $response->assertOk();
        $response->assertSee('Ирина Петрова');
        $response->assertSee('рекомендует вам нашу школу');
    }

    public function test_home_shows_no_banner_without_or_with_invalid_ref(): void
    {
        $this->get('/')->assertOk()->assertDontSee('рекомендует вам нашу школу');

        $this->get('/?ref=NOPE')->assertOk()->assertDontSee('рекомендует вам нашу школу');
    }
}
