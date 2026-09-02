<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3907 — the student's «Мои платежи» table on the /dvaram dashboard shows the
 * payer_note of a payment (e.g. the 2 000 ₽ supplement invoices #14268–#14284
 * explain WHY they exist — «доплата: тариф повышен до 8 000 с 01.09»); a
 * payment without a note renders no note block.
 */
class StudentPaymentsPayerNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payment_shows_its_payer_note_to_the_student(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['price' => 8000, 'type' => 'block', 'block_number' => 3]);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'tariff' => $tariff->price,
            'amount' => 2000,
            'status' => 'pending',
            'start_block' => 3,
            'end_block' => 3,
            'payer_note' => 'Доплата за блок 3: оплачено 6000 до повышения тарифа до 8000 с 01.09.2026',
        ]);

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Доплата за блок 3: оплачено 6000 до повышения тарифа до 8000 с 01.09.2026', false);
    }

    public function test_payment_without_note_renders_no_note_block(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'status' => 'paid',
        ]);

        $html = $this->actingAs($user)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Доплата за блок 3', $html);
    }
}
