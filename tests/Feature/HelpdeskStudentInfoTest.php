<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Helpdesk;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\StudentDiscount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpdeskStudentInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_info_modal_opens_with_payments_promises_and_discounts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create(['name' => 'Студент Тест', 'last_activity_at' => now()->subMinutes(2)]);
        $course = Course::factory()->create(['title' => 'Курс Альфа']);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        PaymentPromise::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'promised_at' => now()->addDays(3),
            'amount' => 3000,
            'status' => 'active',
        ]);

        StudentDiscount::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'type' => StudentDiscount::TYPE_PERCENT,
            'value' => 15,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('openStudentInfo')
            ->assertSet('infoUserId', $student->id)
            ->assertSee('Студент Тест')
            ->assertSee('Курс Альфа')   // встречается в оплатах/обещаниях/скидках
            ->assertSee('5 000 ₽')      // сумма оплаты
            ->assertSee('Активно')      // статус обещания
            ->assertSee('-15 %')        // индивидуальная скидка
            ->call('closeStudentInfo')
            ->assertSet('infoUserId', null);
    }
}
