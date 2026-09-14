<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherDirectPaymentsLedger;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4627: страница «Прямые оплаты преподавателям» — единый реестр всех
 * received_account=teacher_personal платежей (ручные + из анкеты), поиск
 * для ответов ученикам и защита от двойного занесения (дубль-гард в модалке
 * подтверждения — scopePriorDirectForUser).
 */
class TeacherDirectPaymentsLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function finance_roles_can_access_but_students_cannot(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        $this->assertTrue(TeacherDirectPaymentsLedger::canAccess());
        $this->assertTrue(TeacherDirectPaymentsLedger::shouldRegisterNavigation());

        // Пользователь без финансвой роли (дефолтная фабрика, role null) — доступа нет.
        $this->actingAs(User::factory()->create());
        $this->assertFalse(TeacherDirectPaymentsLedger::canAccess());
    }

    /** @test */
    public function page_lists_manual_and_claim_teacher_payments_with_lookup_columns(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $teacher = Teacher::factory()->create(['name' => 'Edgar Leitan']);
        $tariff = Tariff::factory()->for(Course::factory()->create(['title' => 'Бхагавад-гита читаем']))->block(2)->create(['price' => 4800]);
        $student = User::factory()->create(['name' => 'Говоруха Марина']);

        // Старый ручной платёж (до анкеты): provider null, received=teacher.
        $manual = Payment::create([
            'user_id' => $student->id,
            'course_id' => $tariff->course_id,
            'amount' => 4800,
            'status' => 'paid',
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
        ]);

        // Новый — через анкету: pending teacher_transfer.
        $claim = Payment::create([
            'user_id' => $student->id,
            'course_id' => $tariff->course_id,
            'amount' => 4800,
            'foreign_amount' => 70,
            'foreign_currency' => 'EUR',
            'status' => 'pending',
            'provider' => Payment::PROVIDER_TEACHER_TRANSFER,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $teacher->id,
        ]);

        $this->actingAs($manager)
            ->get('/admin/teacher-direct-payments')
            ->assertSuccessful()
            ->assertSee('Прямые оплаты преподавателям')
            ->assertSee('Говоруха Марина')
            ->assertSee('Edgar Leitan')
            ->assertSee('Бхагавад-гита читаем')
            ->assertSee('анкета /teacher-pay')
            ->assertSee('внесён вручную');

        $this->assertSame($manual->id, Payment::query()->whereKey($manual->id)->where('received_account', Payment::RECEIVED_TEACHER)->firstOrFail()->id);
        $this->assertSame($claim->id, Payment::query()->whereKey($claim->id)->where('received_account', Payment::RECEIVED_TEACHER)->firstOrFail()->id);
    }

    /** @test */
    public function duplicate_guard_returns_only_fresh_paid_direct_payments_of_same_student(): void
    {
        $teacher = Teacher::factory()->create();
        $student = User::factory()->create();
        $other = User::factory()->create();
        $base = ['course_id' => null, 'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $teacher->id];

        $fresh = Payment::create(['user_id' => $student->id, 'amount' => 4800, 'status' => 'paid'] + $base);
        Payment::create(['user_id' => $student->id, 'amount' => 4800, 'status' => 'paid', 'created_at' => now()->subDays(90)] + $base);
        Payment::create(['user_id' => $student->id, 'amount' => 4800, 'status' => 'canceled'] + $base);
        Payment::create(['user_id' => $other->id, 'amount' => 4800, 'status' => 'paid'] + $base);

        $found = Payment::query()->priorDirectForUser($student->id, 999999)->pluck('id');

        $this->assertSame([$fresh->id], $found->all());
    }
}
