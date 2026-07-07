<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CalendarPage;
use App\Filament\Widgets\ScheduleCalendarWidget;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeacher(string $email): array
    {
        $teacher = Teacher::create(['name' => 'Препод '.$email, 'email' => $email]);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);

        return [$teacher, $teacherUser];
    }

    private function makeEvent(Course $course, Carbon $start, string $title): Schedule
    {
        return Schedule::create([
            'title' => $title,
            'course_id' => $course->id,
            'start' => $start,
            'end' => $start->copy()->addHour(),
        ]);
    }

    /** @test */
    public function fetch_events_is_scoped_to_teacher_and_date_range(): void
    {
        [$teacherA, $teacherAUser] = $this->makeTeacher('a@example.test');
        [$teacherB] = $this->makeTeacher('b@example.test');

        $courseA = Course::factory()->create(['teacher_id' => $teacherA->id]);
        $courseB = Course::factory()->create(['teacher_id' => $teacherB->id]);

        $own = $this->makeEvent($courseA, now()->startOfMonth()->addDays(5)->setTime(10, 0), 'Своё');
        $foreign = $this->makeEvent($courseB, now()->startOfMonth()->addDays(5)->setTime(12, 0), 'Чужое');
        $outOfRange = $this->makeEvent($courseA, now()->addMonths(3), 'Вне диапазона');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherAUser);

        $events = Livewire::test(ScheduleCalendarWidget::class)
            ->instance()
            ->fetchEvents([
                'start' => now()->startOfMonth()->toDateTimeString(),
                'end' => now()->endOfMonth()->toDateTimeString(),
                'timezone' => config('app.timezone'),
            ]);

        $ids = array_column($events, 'id');

        $this->assertContains($own->id, $ids, 'Своё событие в диапазоне должно отдаваться.');
        $this->assertNotContains($foreign->id, $ids, 'Событие чужого преподавателя не отдаём.');
        $this->assertNotContains($outOfRange->id, $ids, 'Событие вне видимого диапазона не отдаём.');
    }

    /** @test */
    public function calendar_page_renders_for_teacher(): void
    {
        [$teacher, $teacherUser] = $this->makeTeacher('a@example.test');
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $this->makeEvent($course, now()->addDay(), 'Событие');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        $this->assertTrue(CalendarPage::canAccess());

        Livewire::test(ScheduleCalendarWidget::class)->assertOk();
    }

    /**
     * Регресс на прод-403: базовый Filament\Widget через CanAuthorizeAccess на
     * КАЖДОЙ гидрации делает abort_unless(canView(), 403). Когда canView()
     * возвращал false, initial mount проходил (тест выше был зелёным), но первый
     * же AJAX FullCalendar (гидрация) падал 403 поверх загруженной страницы.
     * Здесь дёргаем $refresh — это полный цикл гидрации, который ловил бы баг.
     *
     * @test
     */
    public function widget_survives_hydration_for_authorized_role(): void
    {
        [, $teacherUser] = $this->makeTeacher('a@example.test');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        $this->assertTrue(ScheduleCalendarWidget::canView());

        Livewire::test(ScheduleCalendarWidget::class)
            ->call('$refresh')
            ->assertOk();
    }

    /** @test */
    public function widget_is_excluded_from_dashboard_autodiscovery(): void
    {
        // Виджет живёт только на CalendarPage — не должен всплывать на Dashboard.
        $this->assertFalse(ScheduleCalendarWidget::isDiscovered());
    }

    /** @test */
    public function widget_is_hidden_from_non_privileged_roles(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));

        $this->assertFalse(ScheduleCalendarWidget::canView());
    }
}
