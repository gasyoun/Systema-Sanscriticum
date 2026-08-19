<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChatRemovalStatus;
use App\Filament\Pages\CourseDebtChatRemovals;
use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\CourseDebtChatRemoval;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Операторский экран H2746: доступ, запись исключения в реестр и запрет
 * возврата раньше времени. Telegram при этом не трогается — Wave 1.
 */
class CourseDebtChatRemovalsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $debtor;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Debtors::flushPairCaches();
        Http::preventStrayRequests();

        $start = Carbon::now()->subDays(55)->startOfDay();
        $this->course = Course::factory()->create([
            'is_active' => true,
            'slug' => 'h2746-page',
            'title' => 'Курс страницы',
        ]);
        CourseBlock::factory()->for($this->course)->withDates($start, $start->copy()->addDays(30))->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $this->course->id, 'price' => 4800]);

        $group = Group::create(['name' => 'Поток П', 'telegram_chat_id' => '-100PAGE', 'status' => 'active']);
        $this->course->groups()->attach($group->id);

        $this->debtor = User::factory()->create(['telegram_id' => '550001']);
        $this->debtor->groups()->attach($group->id);

        foreach ([40, 9] as $daysAgo) {
            DebtReminder::create([
                'user_id' => $this->debtor->id,
                'course_id' => $this->course->id,
                'block_number' => 1,
                'sent_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    public function test_page_is_admin_only(): void
    {
        // Куратор (manager) — не админ: страница с фамилиями и суммами
        // открывается только adminOnly().
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        $this->assertFalse(CourseDebtChatRemovals::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(CourseDebtChatRemovals::canAccess());
    }

    public function test_page_renders_the_candidate(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CourseDebtChatRemovals::class)
            ->assertOk()
            ->assertSee('Курс страницы')
            ->assertSee('Поток П');
    }

    public function test_record_removal_writes_the_ledger_row_without_touching_telegram(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CourseDebtChatRemovals::class)
            ->call('recordRemoval', $this->debtor->id, $this->course->id, '-100PAGE');

        $row = CourseDebtChatRemoval::query()->where('user_id', $this->debtor->id)->firstOrFail();

        $this->assertSame(ChatRemovalStatus::Removed, $row->status);
        $this->assertSame('-100PAGE', $row->telegram_chat_id);
        $this->assertEqualsWithDelta(1000.0, (float) $row->reinstatement_fee, 0.01);
        $this->assertSame(2, $row->unanswered_contacts);
        $this->assertNotNull($row->removed_at);
        // Http::preventStrayRequests() гарантирует: ни одного вызова Bot API.
    }

    public function test_restore_action_is_hidden_until_debt_and_fee_are_closed(): void
    {
        $page = Livewire::actingAs($this->admin())->test(CourseDebtChatRemovals::class);
        $page->call('recordRemoval', $this->debtor->id, $this->course->id, '-100PAGE');

        $row = CourseDebtChatRemoval::query()->where('user_id', $this->debtor->id)->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(CourseDebtChatRemovals::class)
            ->assertTableActionHidden('restore', $row)
            ->assertTableActionVisible('debt_settled', $row)
            ->assertTableActionVisible('fee_paid', $row);

        Livewire::actingAs($this->admin())
            ->test(CourseDebtChatRemovals::class)
            ->callTableAction('debt_settled', $row)
            ->callTableAction('fee_paid', $row)
            ->assertTableActionVisible('restore', $row->refresh());

        $this->assertSame(ChatRemovalStatus::FeeSettled, $row->refresh()->status);
    }
}
