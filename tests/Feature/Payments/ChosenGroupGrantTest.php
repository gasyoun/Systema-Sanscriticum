<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\EnrollmentGroupLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Money-контур (20-10-2026): выбранная в личке группа доезжает до платежа
 * только по верной подписи и выдаётся одна — на курсе с несколькими группами.
 */
class ChosenGroupGrantTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $saturday;

    private Group $tuesday;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.com/redirect/abc', 'paymentLinkId' => 'tx_1'],
            ], 200),
        ]);

        $this->course = Course::factory()->create(['title' => 'Грамматика санскрита с нуля — набор 2026']);
        $this->saturday = Group::create(['name' => 'Грамматика 2026 — суббота 11:00', 'status' => 'forming']);
        $this->tuesday = Group::create(['name' => 'Грамматика 2026 — вторник 08:00', 'status' => 'forming']);
        $this->course->groups()->attach([$this->saturday->id, $this->tuesday->id]);
        $this->tariff = Tariff::factory()->for($this->course)->block(1)->create(['price' => 8000]);
    }

    private function sig(Group $group): string
    {
        return EnrollmentGroupLink::signature($this->tariff->id, $group->id);
    }

    public function test_signed_group_is_stored_on_payment(): void
    {
        config(['features.grant_chosen_group' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('payment.create'), [
            'tariff_id' => $this->tariff->id,
            'group_id' => $this->saturday->id,
            'gsig' => $this->sig($this->saturday),
        ]);

        $this->assertSame($this->saturday->id, (int) Payment::query()->value('group_id'));
    }

    public function test_tampered_signature_stores_no_group(): void
    {
        config(['features.grant_chosen_group' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('payment.create'), [
            'tariff_id' => $this->tariff->id,
            'group_id' => $this->tuesday->id,
            'gsig' => $this->sig($this->saturday),
        ]);

        $this->assertDatabaseCount('payments', 1);
        $this->assertNull(Payment::query()->value('group_id'));
    }

    public function test_flag_off_ignores_group_param(): void
    {
        config(['features.grant_chosen_group' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('payment.create'), [
            'tariff_id' => $this->tariff->id,
            'group_id' => $this->saturday->id,
            'gsig' => $this->sig($this->saturday),
        ]);

        $this->assertNull(Payment::query()->value('group_id'));
    }

    public function test_checkout_page_shows_signed_group(): void
    {
        config(['features.grant_chosen_group' => true]);

        $this->actingAs(User::factory()->create())
            ->get(EnrollmentGroupLink::url($this->tariff, $this->saturday))
            ->assertOk()
            ->assertSee('Грамматика 2026 — суббота 11:00')
            ->assertSee('name="group_id"', false);
    }

    public function test_paid_with_chosen_group_grants_only_that_group(): void
    {
        config(['features.grant_chosen_group' => true]);
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'group_id' => $this->saturday->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]);

        $this->assertSame([$this->saturday->id], $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_flag_off_keeps_all_course_groups(): void
    {
        config(['features.grant_chosen_group' => false]);
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'group_id' => $this->saturday->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->saturday->id, $this->tuesday->id],
            $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_foreign_group_on_payment_falls_back_to_course_groups(): void
    {
        config(['features.grant_chosen_group' => true]);
        $foreign = Group::create(['name' => 'Чужая', 'status' => 'active']);
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'group_id' => $foreign->id,
            'amount' => 8000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $ids = $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($foreign->id, $ids);
        $this->assertCount(2, $ids);
    }
}
