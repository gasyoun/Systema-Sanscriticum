<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Массовое действие «Разбить оплату блока на другую группу» в «Студентах»:
 * по умолчанию сухой прогон, применение — админу и только при включённом флаге.
 */
class SplitPaymentBlockBulkActionTest extends TestCase
{
    use RefreshDatabase;

    private Course $from;

    private Course $to;

    private Group $toGroup;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->from = Course::factory()->create(['slug' => 'gr60', 'title' => 'гр.60']);
        $this->to = Course::factory()->create(['slug' => 'gr61', 'title' => 'гр.61']);
        $this->from->groups()->attach(Group::factory()->create()->id);
        $this->toGroup = Group::factory()->create();
        $this->to->groups()->attach($this->toGroup->id);

        foreach ([$this->from, $this->to] as $course) {
            foreach ([1, 2] as $half) {
                Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 3, 'block_half' => $half]);
            }
            Tariff::factory()->block(3)->create(['course_id' => $course->id, 'price' => 4800]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    private function paidStudent(): User
    {
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->from->id,
            'amount' => 4800,
            'tariff' => 'block_3',
            'status' => 'paid',
            'start_block' => 3,
            'end_block' => 3,
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function data(bool $apply): array
    {
        return [
            'from_course_id' => $this->from->id,
            'target_group_id' => $this->toGroup->id,
            'block' => 3,
            'percent' => 50,
            'apply' => $apply,
        ];
    }

    public function test_dry_run_reports_and_writes_nothing_even_with_the_flag_on(): void
    {
        config(['features.payment_block_half_split' => true]);
        $student = $this->paidStudent();
        $before = Payment::query()->count();

        Livewire::actingAs($this->admin())->test(ListUsers::class)
            ->callTableBulkAction('split_block_to_group', [$student->getKey()], data: $this->data(false))
            ->assertNotified('Сухой прогон: ничего не записано');

        $this->assertSame($before, Payment::query()->count());
        $this->assertSame('block_3', Payment::query()->where('user_id', $student->id)->value('tariff'));
    }

    public function test_apply_splits_the_payment_when_the_flag_is_on(): void
    {
        config(['features.payment_block_half_split' => true]);
        $student = $this->paidStudent();

        Livewire::actingAs($this->admin())->test(ListUsers::class)
            ->callTableBulkAction('split_block_to_group', [$student->getKey()], data: $this->data(true))
            ->assertNotified('Оплата блока разбита');

        $this->assertSame('block_3_h1', Payment::query()->where('user_id', $student->id)->where('course_id', $this->from->id)->value('tariff'));
        $this->assertSame('block_3_h2', Payment::query()->where('user_id', $student->id)->where('course_id', $this->to->id)->value('tariff'));
    }

    public function test_apply_is_ignored_while_the_flag_is_off(): void
    {
        config(['features.payment_block_half_split' => false]);
        $student = $this->paidStudent();

        Livewire::actingAs($this->admin())->test(ListUsers::class)
            ->callTableBulkAction('split_block_to_group', [$student->getKey()], data: $this->data(true))
            ->assertNotified('Сухой прогон: ничего не записано');

        $this->assertSame('block_3', Payment::query()->where('user_id', $student->id)->value('tariff'));
        $this->assertSame(0, Payment::query()->where('course_id', $this->to->id)->count());
    }

    public function test_the_action_is_hidden_from_non_admin_staff(): void
    {
        $manager = User::factory()->create(['role' => Roles::MANAGER, 'is_admin' => false]);
        $student = $this->paidStudent();

        Livewire::actingAs($manager)->test(ListUsers::class)
            ->assertTableBulkActionHidden('split_block_to_group');

        $this->assertSame('block_3', Payment::query()->where('user_id', $student->id)->value('tariff'));
    }
}
