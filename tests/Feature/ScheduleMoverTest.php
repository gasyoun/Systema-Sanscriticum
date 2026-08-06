<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\ScheduleMover;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Перенос одного занятия и отмена с каскадом +1 неделя (ScheduleMover).
 * Заменяет прежние ReplicateAction «дублировать на завтра / +неделю».
 */
class ScheduleMoverTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        return $admin;
    }

    private function mover(): ScheduleMover
    {
        return app(ScheduleMover::class);
    }

    // ------------------------------------------------------------------
    // reschedule — только одно занятие
    // ------------------------------------------------------------------

    /** @test */
    public function reschedule_moves_only_one_schedule_and_keeps_duration(): void
    {
        $group = Group::create(['name' => 'Гр. A']);
        $base = now()->addWeek()->setTime(11, 0)->seconds(0);

        $target = Schedule::create([
            'title' => 'Целевое (#1, '.$base->format('d.m.y').')',
            'start' => $base,
            'end' => $base->copy()->addHours(2),
            'group_id' => $group->id,
            'reminded_at' => now()->subHour(),
            'group_link_posted_at' => now()->subHour(),
        ]);
        $neighbor = Schedule::create([
            'title' => 'Сосед',
            'start' => $base->copy()->addDays(2),
            'end' => $base->copy()->addDays(2)->addHours(2),
            'group_id' => $group->id,
        ]);

        $newStart = $base->copy()->addDays(3)->setTime(18, 30);
        $this->mover()->reschedule($target, $newStart);

        $target->refresh();
        $neighbor->refresh();

        $this->assertTrue($target->start->equalTo($newStart));
        $this->assertTrue($target->end->equalTo($newStart->copy()->addHours(2)));
        $this->assertNull($target->reminded_at, 'lifecycle сбрасывается при смене start');
        $this->assertNull($target->group_link_posted_at);

        $this->assertTrue($neighbor->start->equalTo($base->copy()->addDays(2)), 'сосед не должен сдвинуться');
    }

    // ------------------------------------------------------------------
    // cancelAndShiftWeek — каскад
    // ------------------------------------------------------------------

    /** @test */
    public function cancel_shifts_self_and_subsequent_same_group_by_one_week(): void
    {
        $group = Group::create(['name' => 'Гр. B']);
        $other = Group::create(['name' => 'Гр. C']);
        $t0 = now()->addDays(3)->setTime(19, 0)->seconds(0);

        $earlier = Schedule::create([
            'title' => 'Раньше (#0, '.$t0->copy()->subWeek()->format('d.m.y').')',
            'start' => $t0->copy()->subWeek(),
            'end' => $t0->copy()->subWeek()->addHours(2),
            'group_id' => $group->id,
        ]);
        $a = Schedule::create([
            'title' => 'A (#1, '.$t0->format('d.m.y').')',
            'start' => $t0,
            'end' => $t0->copy()->addHours(2),
            'group_id' => $group->id,
            'reminded_at' => now(),
            'zoom_occurrence_uuid' => 'occ-a',
            'zoom_recording_url' => 'https://zoom.us/rec/a',
        ]);
        $b = Schedule::create([
            'title' => 'B (#2, '.$t0->copy()->addWeek()->format('d.m.y').')',
            'start' => $t0->copy()->addWeek(),
            'end' => $t0->copy()->addWeek()->addHours(2),
            'group_id' => $group->id,
        ]);
        $c = Schedule::create([
            'title' => 'C (#3, '.$t0->copy()->addWeeks(2)->format('d.m.y').')',
            'start' => $t0->copy()->addWeeks(2),
            'end' => $t0->copy()->addWeeks(2)->addHours(2),
            'group_id' => $group->id,
        ]);
        $foreign = Schedule::create([
            'title' => 'Чужая группа',
            'start' => $t0,
            'end' => $t0->copy()->addHours(2),
            'group_id' => $other->id,
        ]);

        $count = $this->mover()->cancelAndShiftWeek($a);
        $this->assertSame(3, $count);

        $earlier->refresh();
        $a->refresh();
        $b->refresh();
        $c->refresh();
        $foreign->refresh();

        // earlier не тронут
        $this->assertTrue($earlier->start->equalTo($t0->copy()->subWeek()));

        // a, b, c +7
        $this->assertTrue($a->start->equalTo($t0->copy()->addWeek()));
        $this->assertTrue($a->end->equalTo($t0->copy()->addWeek()->addHours(2)));
        $this->assertTrue($b->start->equalTo($t0->copy()->addWeeks(2)));
        $this->assertTrue($c->start->equalTo($t0->copy()->addWeeks(3)));

        // чужая группа не тронута
        $this->assertTrue($foreign->start->equalTo($t0));

        // lifecycle сброшен у сдвинутых
        $this->assertNull($a->reminded_at);
        $this->assertNull($a->zoom_occurrence_uuid);
        $this->assertNull($a->zoom_recording_url);

        // дата в title обновлена
        $this->assertStringContainsString($t0->copy()->addWeek()->format('d.m.y'), $a->title);
        $this->assertStringNotContainsString($t0->format('d.m.y'), $a->title);
    }

    /** @test */
    public function cancel_without_group_throws(): void
    {
        $s = Schedule::create([
            'title' => 'Без группы',
            'start' => now()->addWeek(),
            'end' => now()->addWeek()->addHour(),
            'group_id' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->mover()->cancelAndShiftWeek($s);
    }

    /** @test */
    public function count_chain_matches_cancel_scope(): void
    {
        $group = Group::create(['name' => 'Гр. D']);
        $t0 = now()->addDays(5)->setTime(10, 0)->seconds(0);

        Schedule::create(['title' => 'past', 'start' => $t0->copy()->subDay(), 'group_id' => $group->id]);
        $pivot = Schedule::create(['title' => 'pivot', 'start' => $t0, 'group_id' => $group->id]);
        Schedule::create(['title' => 'next1', 'start' => $t0->copy()->addDay(), 'group_id' => $group->id]);
        Schedule::create(['title' => 'next2', 'start' => $t0->copy()->addDays(2), 'group_id' => $group->id]);

        $this->assertSame(3, $this->mover()->countChain($pivot));
    }

    // ------------------------------------------------------------------
    // Filament row-actions
    // ------------------------------------------------------------------

    /** @test */
    public function list_reschedule_action_moves_only_selected(): void
    {
        $this->admin();
        $group = Group::create(['name' => 'Гр. UI']);
        $base = now()->addWeek()->setTime(12, 0)->seconds(0);

        $target = Schedule::create([
            'title' => 'UI target',
            'start' => $base,
            'end' => $base->copy()->addHours(2),
            'group_id' => $group->id,
        ]);
        $other = Schedule::create([
            'title' => 'UI other',
            'start' => $base->copy()->addDays(1),
            'end' => $base->copy()->addDays(1)->addHours(2),
            'group_id' => $group->id,
        ]);

        $newStart = $base->copy()->addDays(4)->setTime(15, 0);

        Livewire::test(ListSchedules::class)
            ->callTableAction('reschedule', $target, data: [
                'start' => $newStart->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($target->fresh()->start->equalTo($newStart));
        $this->assertTrue($other->fresh()->start->equalTo($base->copy()->addDays(1)));
    }

    /** @test */
    public function list_cancel_shift_week_action_cascades(): void
    {
        $this->admin();
        $group = Group::create(['name' => 'Гр. UI2']);
        $t0 = now()->addDays(4)->setTime(19, 0)->seconds(0);

        $a = Schedule::create([
            'title' => 'A',
            'start' => $t0,
            'end' => $t0->copy()->addHours(2),
            'group_id' => $group->id,
        ]);
        $b = Schedule::create([
            'title' => 'B',
            'start' => $t0->copy()->addWeek(),
            'end' => $t0->copy()->addWeek()->addHours(2),
            'group_id' => $group->id,
        ]);

        Livewire::test(ListSchedules::class)
            ->callTableAction('cancel_shift_week', $a)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($a->fresh()->start->equalTo($t0->copy()->addWeek()));
        $this->assertTrue($b->fresh()->start->equalTo($t0->copy()->addWeeks(2)));
    }

    /** @test */
    public function duplicate_actions_are_gone(): void
    {
        $this->admin();
        $group = Group::create(['name' => 'Гр. UI3']);
        $s = Schedule::create([
            'title' => 'X',
            'start' => now()->addWeek(),
            'end' => now()->addWeek()->addHour(),
            'group_id' => $group->id,
        ]);

        Livewire::test(ListSchedules::class)
            ->assertTableActionExists('reschedule')
            ->assertTableActionExists('cancel_shift_week')
            ->assertTableActionDoesNotExist('duplicate_next_day')
            ->assertTableActionDoesNotExist('duplicate_next_week');

        // запись не дублируется «вхолостую»
        $this->assertSame(1, Schedule::count());
        unset($s);
    }
}
