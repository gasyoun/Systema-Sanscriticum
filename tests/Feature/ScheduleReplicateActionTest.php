<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Регресс: дублирование занятия падало с
 * "Unknown column 'attendances_count'", потому что таблица списка грузит
 * ->counts('attendances'), а ReplicateAction копировал этот виртуальный
 * атрибут в INSERT. Плюс копия наследовала метки «уже напомнили» и per-occurrence
 * поля Zoom, из-за чего для нового занятия не срабатывали напоминания.
 */
class ScheduleReplicateActionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        return $admin;
    }

    /** @test */
    public function duplicating_a_schedule_with_attendances_does_not_crash_and_resets_lifecycle(): void
    {
        $this->admin();
        $group = Group::create(['name' => 'Группа 61']);

        // Заведомо будущее занятие: у таблицы фильтр по умолчанию «Будущие»
        // (start >= now()), иначе запись не резолвится для действия.
        $original = Schedule::create([
            'title' => 'Грамматика гр.61',
            'start' => now()->addWeek()->setTime(11, 0),
            'end' => now()->addWeek()->setTime(13, 0),
            'group_id' => $group->id,
            'reminded_at' => now()->subHour(),
            'group_link_posted_at' => now()->subHour(),
            'absent_notified_at' => now()->subHour(),
            'zoom_occurrence_uuid' => 'occ-123',
            'zoom_recording_url' => 'https://zoom.us/rec/abc',
            'zoom_recording_received_at' => now()->subHour(),
        ]);

        // Виртуальный attendances_count появляется при ->counts('attendances').
        WebinarAttendance::create([
            'schedule_id' => $original->id, 'user_id' => null,
            'zoom_participant_uuid' => 'g1', 'name' => 'Гость',
        ]);

        Livewire::test(ListSchedules::class)
            ->callTableAction('duplicate_next_day', $original)
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, Schedule::count(), 'Должно стать 2 занятия (оригинал + копия)');

        $replica = Schedule::where('id', '!=', $original->id)->latest('id')->first();

        $this->assertNotNull($replica, 'Копия занятия должна была создаться');
        $this->assertTrue($replica->start->equalTo($original->start->copy()->addDay()));
        $this->assertTrue($replica->end->equalTo($original->end->copy()->addDay()));

        // Метки жизненного цикла и per-occurrence Zoom-поля сброшены у копии.
        $this->assertNull($replica->reminded_at);
        $this->assertNull($replica->group_link_posted_at);
        $this->assertNull($replica->absent_notified_at);
        $this->assertNull($replica->zoom_occurrence_uuid);
        $this->assertNull($replica->zoom_recording_url);
        $this->assertNull($replica->zoom_recording_received_at);

        // Посещаемость оригинала не перетекла на копию.
        $this->assertSame(0, $replica->attendances()->count());
    }
}
