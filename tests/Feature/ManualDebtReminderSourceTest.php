<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Discipline\ChatRemovalCandidate;
use App\Services\Discipline\ChatRemovalEligibility;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3156: ручное напоминание оставляет след с `source='manual'`, правило H2746
 * его засчитывает, а лестница дожима меняет ритм ТОЛЬКО по явной настройке.
 */
class ManualDebtReminderSourceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        Debtors::flushPairCaches();
        MarketingSetting::flushCached();

        $start = Carbon::now()->subDays(45)->startOfDay();
        $this->course = Course::factory()->create([
            'is_active' => true,
            'slug' => 'h3156-course',
            'title' => 'Курс H3156',
        ]);
        CourseBlock::factory()->for($this->course)
            ->withDates($start, $start->copy()->addDays(30))
            ->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $this->course->id, 'price' => 4800]);

        $this->group = Group::create([
            'name' => 'Поток H3156',
            'telegram_chat_id' => '-100H3156',
            'status' => 'active',
        ]);
        $this->course->groups()->attach($this->group->id);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    private function debtor(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'telegram_id' => '310001',
            'vk_id' => null,
        ], $attrs));
        $user->groups()->attach($this->group->id);

        return $user;
    }

    private function sendManualReminder(User $admin, User $debtor, array $channels = ['to_telegram' => true]): void
    {
        Livewire::actingAs($admin)
            ->test(Debtors::class)
            ->callTableAction('quick_reminder', $debtor, data: array_merge([
                'text' => 'Намасте, {name}! Оплата по «{course}» не поступила.',
                'subject' => 'Напоминание',
                'to_telegram' => false,
                'to_vk' => false,
                'to_email' => false,
            ], $channels))
            ->assertHasNoTableActionErrors();
    }

    public function test_manual_reminder_writes_a_row_marked_manual(): void
    {
        $debtor = $this->debtor();

        $this->sendManualReminder($this->admin(), $debtor);

        $rows = DebtReminder::query()->where('user_id', $debtor->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(DebtReminder::SOURCE_MANUAL, $rows->first()->source);
        $this->assertSame((int) $this->course->id, (int) $rows->first()->course_id);
    }

    public function test_no_row_when_no_channel_could_deliver(): void
    {
        // Ни одного канала: нет telegram_id/vk_id и невалидный email.
        $debtor = $this->debtor(['telegram_id' => null, 'vk_id' => null, 'email' => 'не-почта']);

        $this->sendManualReminder($this->admin(), $debtor, [
            'to_telegram' => true,
            'to_vk' => true,
            'to_email' => true,
        ]);

        $this->assertSame(0, DebtReminder::query()->where('user_id', $debtor->id)->count());
    }

    public function test_two_manual_reminders_satisfy_the_h2746_silence_rule(): void
    {
        $admin = $this->admin();
        $debtor = $this->debtor();

        $this->sendManualReminder($admin, $debtor);
        $this->sendManualReminder($admin, $debtor);

        Debtors::flushPairCaches();
        $candidate = app(ChatRemovalEligibility::class)
            ->candidates($debtor->id)
            ->first(fn (ChatRemovalCandidate $c) => $c->telegramChatId === '-100H3156');

        $this->assertNotNull($candidate);
        $this->assertSame(2, $candidate->evidence->trailingUnanswered);
        $this->assertSame([], $candidate->blockers, implode(', ', $candidate->blockers));
        // Аудит-след честен про происхождение контакта.
        $this->assertSame(
            [DebtReminder::SOURCE_MANUAL, DebtReminder::SOURCE_MANUAL],
            array_column($candidate->evidence->attempts, 'channel'),
        );
    }

    public function test_default_policy_manual_does_not_suppress_the_auto_ladder(): void
    {
        $debtor = $this->debtor();
        MarketingSetting::flushCached();
        MarketingSetting::create([
            'debt_reminders_enabled' => true,
            'debt_reminder_cadence_days' => 7,
            'debt_reminder_manual_suppresses_auto' => false,
            'debt_reminder_to_telegram' => true,
            'debt_reminder_to_vk' => false,
            'debt_reminder_to_email' => false,
        ]);

        DebtReminder::create([
            'user_id' => $debtor->id,
            'course_id' => $this->course->id,
            'block_number' => 1,
            'sent_at' => Carbon::now()->subDay(),
            'source' => DebtReminder::SOURCE_MANUAL,
        ]);

        $this->artisan('debts:remind')->assertSuccessful();

        // Лестница не заметила ручной строки и отправила своё — ритм сохранён.
        $this->assertSame(
            1,
            DebtReminder::query()
                ->where('user_id', $debtor->id)
                ->where('source', DebtReminder::SOURCE_AUTO)
                ->count(),
        );
    }

    public function test_opt_in_policy_manual_suppresses_the_auto_ladder(): void
    {
        $debtor = $this->debtor();
        MarketingSetting::flushCached();
        MarketingSetting::create([
            'debt_reminders_enabled' => true,
            'debt_reminder_cadence_days' => 7,
            'debt_reminder_manual_suppresses_auto' => true,
            'debt_reminder_to_telegram' => true,
            'debt_reminder_to_vk' => false,
            'debt_reminder_to_email' => false,
        ]);

        DebtReminder::create([
            'user_id' => $debtor->id,
            'course_id' => $this->course->id,
            'block_number' => 1,
            'sent_at' => Carbon::now()->subDay(),
            'source' => DebtReminder::SOURCE_MANUAL,
        ]);

        $this->artisan('debts:remind')->assertSuccessful();

        $this->assertSame(
            0,
            DebtReminder::query()
                ->where('user_id', $debtor->id)
                ->where('source', DebtReminder::SOURCE_AUTO)
                ->count(),
        );
    }

    public function test_auto_rows_default_to_auto_source(): void
    {
        // Строка без явного source (как все исторические) читается как auto —
        // бэкфилл историчен: до H3156 писала только авто-команда.
        $debtor = $this->debtor();
        $row = DebtReminder::create([
            'user_id' => $debtor->id,
            'course_id' => $this->course->id,
            'block_number' => 1,
            'sent_at' => Carbon::now()->subDays(3),
        ]);

        $this->assertSame(DebtReminder::SOURCE_AUTO, $row->fresh()->source);
    }
}
