<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\TeacherCoursePayments;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6145: скоп «Финансы своих курсов» — преподаватель видит платежи своих
 * курсов и свои выплаты, чужих не видит; контур записи остался за «Финансами».
 */
class TeacherCoursePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $kostina;

    private Teacher $other;

    private User $kostinaUser;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->kostina = Teacher::create(['name' => 'Костина Тестовая', 'email' => 'kostina@test.example']);
        $this->other = Teacher::create(['name' => 'Другой Тестовый', 'email' => 'other@test.example']);
        $this->kostinaUser = User::factory()->create([
            'role' => Roles::TEACHER,
            'teacher_id' => $this->kostina->id,
        ]);
    }

    private function pay(Course $course, array $attrs): Payment
    {
        return Payment::withoutEvents(function () use ($course, $attrs): Payment {
            return Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
            ], $attrs));
        });
    }

    public function test_teacher_sees_only_own_course_payments(): void
    {
        $own = Course::factory()->create(['teacher_id' => $this->kostina->id, 'title' => 'Хинди среда']);
        $foreign = Course::factory()->create(['teacher_id' => $this->other->id, 'title' => 'Санскрит пятница']);

        $ownStudent = User::factory()->create(['name' => 'СвояУченица Мария']);
        $foreignStudent = User::factory()->create(['name' => 'ЧужойУченик Пётр']);

        $this->pay($own, ['user_id' => $ownStudent->id, 'amount' => 8000]);
        $this->pay($foreign, ['user_id' => $foreignStudent->id, 'amount' => 9000]);

        $response = $this->actingAs($this->kostinaUser)
            ->get(TeacherCoursePayments::getUrl())
            ->assertSuccessful();

        $response->assertSee('СвояУченица Мария');
        $response->assertSee('Хинди среда');
        $response->assertDontSee('ЧужойУченик Пётр');
        $response->assertDontSee('Санскрит пятница');
    }

    public function test_teacher_direct_payment_on_own_name_is_visible(): void
    {
        $student = User::factory()->create(['name' => 'ПрямаяУченица Анна']);
        $foreignCourse = Course::factory()->create(['teacher_id' => $this->other->id, 'title' => 'Чужой интенсив']);

        // Прямая оплата на счёт Костиной за чужой по course_id курс всё равно её касается
        $this->pay($foreignCourse, [
            'user_id' => $student->id,
            'amount' => 5000,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $this->kostina->id,
        ]);

        $this->actingAs($this->kostinaUser)
            ->get(TeacherCoursePayments::getUrl())
            ->assertSuccessful()
            ->assertSee('ПрямаяУченица Анна');
    }

    public function test_teacher_sees_own_payout_history_and_not_foreign(): void
    {
        TeacherPayout::create([
            'teacher_id' => $this->kostina->id,
            'amount' => 28704,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => now()->parse('2026-09-15'),
            'comment' => 'тестовый аванс Костиной',
        ]);
        TeacherPayout::create([
            'teacher_id' => $this->other->id,
            'amount' => 99999,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => now()->parse('2026-09-20'),
            'comment' => 'чужая выплата не видна',
        ]);

        $response = $this->actingAs($this->kostinaUser)
            ->get(TeacherCoursePayments::getUrl())
            ->assertSuccessful();

        $response->assertSee('28 704');
        $response->assertSee('тестовый аванс Костиной');
        $response->assertDontSee('99 999');
        $response->assertDontSee('чужая выплата не видна');
    }

    public function test_plain_student_cannot_access(): void
    {
        $student = User::factory()->create(['role' => null]);

        $this->actingAs($student)
            ->get(TeacherCoursePayments::getUrl())
            ->assertForbidden();
    }

    public function test_admin_sees_all_teachers_payments(): void
    {
        $a = Course::factory()->create(['teacher_id' => $this->kostina->id, 'title' => 'Хинди среда']);
        $b = Course::factory()->create(['teacher_id' => $this->other->id, 'title' => 'Санскрит пятница']);

        $this->pay($a, ['user_id' => User::factory()->create(['name' => 'СвояУченица Мария'])->id, 'amount' => 8000]);
        $this->pay($b, ['user_id' => User::factory()->create(['name' => 'ЧужойУченик Пётр'])->id, 'amount' => 9000]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);

        $response = $this->actingAs($admin)
            ->get(TeacherCoursePayments::getUrl())
            ->assertSuccessful();

        $response->assertSee('СвояУченица Мария');
        $response->assertSee('ЧужойУченик Пётр');
    }
}
