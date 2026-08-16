<?php

declare(strict_types=1);

namespace Tests\Feature\Nalopakhyana;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Tariff;
use App\Models\User;
use App\Support\NalaCheckoutBillingUi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H2166 — Nalopākhyāna Course+Tariff scaffold.
 *
 * Test-only proof that Nalopākhyāna launches on the SAME generic one-shot
 * checkout every other Systema course uses (H2105's ShopController /
 * CheckoutController / PaymentController -> TochkaPaymentService path) —
 * zero new payment code. NO real production Course/Tariff row is created
 * here; that stays human ops in Filament (D6/D7, same discipline as H2105).
 *
 * The second half proves the (inert, disabled) Mode-A "auto-pay monthly"
 * checkout radio (App\Support\NalaCheckoutBillingUi) stays hidden unless
 * BOTH features.tochka_recurring AND features.nala_subscriptions_live are
 * on for the Nala course specifically — never for any other course, and
 * never enabling the radio itself (H2026 Phase 1 is a separate handoff).
 */
class NalopakhyanaCourseScaffoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /**
     * Test-only scaffold course/tariff — NOT the real production row.
     */
    private function nalaCourse(): Course
    {
        $course = Course::factory()->create([
            'slug' => 'nalopakhyana',
            'title' => 'Налопакхьяна (тест)',
            'format' => 'live',
        ]);

        // Real production Course row (title/price/teacher/Group/Schedule) is human
        // ops in Filament, same discipline as H2105 (D6/D7) — a Group here is only
        // the minimum PaymentController::createPayment requires to accept the
        // checkout ("group доступа ещё не настроена" guard), not a real cohort.
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        config(['cohort_courses.nalopakhyana.course_slug' => $course->slug]);

        return $course;
    }

    private function nalaTariff(Course $course): Tariff
    {
        return Tariff::factory()->for($course)->create([
            'title' => 'Весь курс целиком',
            'type' => 'full',
            'price' => 15000,
            'is_active' => true,
        ]);
    }

    public function test_one_shot_checkout_creates_pending_payment_and_redirects(): void
    {
        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        Http::fake(['*' => Http::response([
            'Data' => ['paymentLink' => 'https://pay.tochka/nala1', 'paymentLinkId' => 'pl_nala1'],
        ], 200)]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka/nala1');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'tariff' => $tariff->accessKey(),
            'status' => 'pending',
        ]);
    }

    public function test_checkout_page_renders_for_nala_tariff(): void
    {
        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertSee($course->title);
    }

    public function test_open_ended_course_block_scaffold_allows_null_ends_at(): void
    {
        // Optional work-step 4: one CourseBlock with ends_at=null proves the schema
        // needs no fabricated go-live/end date for an open-ended, teacher-led course
        // (D6/D10 discipline — no invented dates). Picked "include" over "skip"
        // because H2168 (reader wiring) may want block-based materialization
        // consistency with the other cohort courses; logged in .ai_state.md.
        $course = $this->nalaCourse();

        $block = CourseBlock::factory()->for($course)->create([
            'number' => 1,
            'starts_at' => now(),
            'ends_at' => null,
        ]);

        $this->assertNull($block->fresh()->ends_at);
        $this->assertSame($course->id, $block->course_id);
    }

    public function test_subscription_radio_hidden_by_default(): void
    {
        config([
            'features.tochka_recurring' => false,
            'features.nala_subscriptions_live' => false,
        ]);

        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertDontSee('nala_billing_mode', false)
            ->assertDontSee('Автоплатёж ежемесячно');
    }

    public function test_subscription_radio_hidden_when_only_master_flag_on(): void
    {
        config([
            'features.tochka_recurring' => true,
            'features.nala_subscriptions_live' => false,
        ]);

        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertDontSee('nala_billing_mode', false);
    }

    public function test_subscription_radio_hidden_when_only_nala_flag_on(): void
    {
        config([
            'features.tochka_recurring' => false,
            'features.nala_subscriptions_live' => true,
        ]);

        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertDontSee('nala_billing_mode', false);
    }

    public function test_subscription_radio_visible_but_disabled_when_both_flags_on_for_nala(): void
    {
        config([
            'features.tochka_recurring' => true,
            'features.nala_subscriptions_live' => true,
        ]);

        $course = $this->nalaCourse();
        $tariff = $this->nalaTariff($course);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('checkout.show', $tariff));

        $response->assertOk()
            ->assertSee('nala_billing_mode', false)
            ->assertSee('Автоплатёж ежемесячно');

        // Inert: the auto-pay option itself stays disabled in the DOM — H2026 Phase 1
        // (real Tochka subscription create/webhook) is a separate, ungated handoff.
        $response->assertSee(
            '<input type="radio" name="nala_billing_mode" value="auto_monthly" disabled',
            false
        );
    }

    public function test_subscription_radio_stays_hidden_for_a_different_course_even_with_both_flags_on(): void
    {
        config([
            'features.tochka_recurring' => true,
            'features.nala_subscriptions_live' => true,
        ]);

        // A different course (e.g. Subhāṣita) must never inherit Nala's kill-switch —
        // NALA_SUBSCRIPTIONS_LIVE is course-specific, not a global recurring toggle.
        $otherCourse = Course::factory()->create(['slug' => 'subhashita']);
        $group = Group::factory()->create();
        $otherCourse->groups()->attach($group->id);
        config(['cohort_courses.nalopakhyana.course_slug' => 'nalopakhyana']);

        $tariff = Tariff::factory()->for($otherCourse)->create(['type' => 'full', 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertDontSee('nala_billing_mode', false);
    }

    public function test_nala_checkout_billing_ui_helper_requires_all_three_conditions(): void
    {
        $course = $this->nalaCourse();

        config(['features.tochka_recurring' => false, 'features.nala_subscriptions_live' => false]);
        $this->assertFalse(NalaCheckoutBillingUi::subscriptionRadioVisibleFor($course));

        config(['features.tochka_recurring' => true, 'features.nala_subscriptions_live' => false]);
        $this->assertFalse(NalaCheckoutBillingUi::subscriptionRadioVisibleFor($course));

        config(['features.tochka_recurring' => false, 'features.nala_subscriptions_live' => true]);
        $this->assertFalse(NalaCheckoutBillingUi::subscriptionRadioVisibleFor($course));

        config(['features.tochka_recurring' => true, 'features.nala_subscriptions_live' => true]);
        $this->assertTrue(NalaCheckoutBillingUi::subscriptionRadioVisibleFor($course));

        $this->assertFalse(NalaCheckoutBillingUi::subscriptionRadioVisibleFor(null));
    }

    public function test_nala_subscriptions_live_flag_defaults_off_in_config(): void
    {
        config(['features.nala_subscriptions_live' => false]);

        $this->assertFalse((bool) config('features.nala_subscriptions_live'));
    }
}
