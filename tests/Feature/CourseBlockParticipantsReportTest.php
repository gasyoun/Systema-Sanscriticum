<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CourseBlockParticipants;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\User;
use App\Services\CourseBlockParticipantsReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CourseBlockParticipantsReportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function counts_group_participants_and_paid_by_block_and_half(): void
    {
        $course = Course::factory()->create();
        foreach ([1, 2, 3] as $n) {
            CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'title' => "Б{$n}"]);
        }

        $group = Group::create(['name' => 'Группа A']);
        $course->groups()->attach($group->id);

        [$a, $b, $c, $d, $e] = User::factory()->count(5)->create();
        $group->users()->attach([$a->id, $b->id, $c->id, $d->id]); // e не в группе

        // Без событий: иначе PaymentObserver::grantAccess авто-добавит студентов
        // в группу курса и состав участников станет неконтролируемым в тесте.
        $pay = fn (User $u, array $attr) => Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $u->id, 'course_id' => $course->id, 'amount' => 100, 'status' => 'paid',
        ], $attr)));

        $pay($a, ['tariff' => 'full']);                                  // все блоки, обе половины
        $pay($b, ['tariff' => 'block_2']);                              // блок 2 целиком
        $pay($c, ['tariff' => 'block_3_h1']);                          // блок 3, только 1-я половина
        $pay($d, ['tariff' => 'block_1', 'start_block' => 1, 'end_block' => 2]); // диапазон 1–2
        $pay($e, ['tariff' => 'block_2', 'is_conditional' => true]);    // «обещанный» — не считаем

        $report = app(CourseBlockParticipantsReport::class)->forCourse($course);

        $this->assertSame(4, $report['participants_total']);
        $this->assertSame(4, $report['paid_any']); // a,b,c,d (e исключён)

        $byNum = collect($report['blocks'])->keyBy('number');

        // Блок 1: a (full) + d (диапазон)
        $this->assertSame(2, $byNum[1]['whole']);
        $this->assertSame(2, $byNum[1]['half1']);
        $this->assertSame(2, $byNum[1]['half2']);

        // Блок 2: a + b + d
        $this->assertSame(3, $byNum[2]['whole']);
        $this->assertSame(3, $byNum[2]['half1']);
        $this->assertSame(3, $byNum[2]['half2']);

        // Блок 3: целиком только a; 1-я половина — a и c; 2-я — только a
        $this->assertSame(1, $byNum[3]['whole']);
        $this->assertSame(2, $byNum[3]['half1']);
        $this->assertSame(1, $byNum[3]['half2']);

        // Матрица «студент × блок»: только оплатившие (a,b,c,d), без conditional-e.
        $this->assertSame([1, 2, 3], $report['block_numbers']);
        $byStudent = collect($report['students'])->keyBy('id');
        $this->assertCount(4, $report['students']);
        $this->assertArrayNotHasKey($e->id, $byStudent->all());

        // a (full): все блоки целиком
        $this->assertSame(['whole', 'whole', 'whole'], array_values($byStudent[$a->id]['blocks']));
        // c (block_3_h1): только 1-я половина 3-го блока
        $this->assertSame([null, null, 'half1'], array_values($byStudent[$c->id]['blocks']));
        // d (диапазон 1–2): блоки 1 и 2 целиком, 3-й — нет
        $this->assertSame(['whole', 'whole', null], array_values($byStudent[$d->id]['blocks']));
    }

    /** @test */
    public function page_renders_for_admin_and_shows_blocks(): void
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'title' => 'Первый']);

        $student = User::factory()->create(['name' => 'Иван Тестов']);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'amount' => 100, 'status' => 'paid', 'tariff' => 'full',
        ]));

        Livewire::actingAs($admin)->test(CourseBlockParticipants::class)
            ->assertSuccessful()
            ->assertSet('courseId', $course->id)
            ->assertSee('Участников в группах курса')
            ->assertSee('Блок 1')
            ->assertSee('Иван Тестов'); // студент виден в матрице
    }
}
