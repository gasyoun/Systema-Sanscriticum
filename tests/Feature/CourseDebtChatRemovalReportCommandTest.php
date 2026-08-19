<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\DebtReminder;
use App\Models\Group;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сухой прогон `debts:chat-removal-report` (H2746): арифметика взноса,
 * обезличивание по умолчанию и отсутствие любых обращений к Telegram.
 */
class CourseDebtChatRemovalReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $debtor;

    protected function setUp(): void
    {
        parent::setUp();

        Debtors::flushPairCaches();
        Http::preventStrayRequests();

        $start = Carbon::now()->subDays(50)->startOfDay();
        $course = Course::factory()->create([
            'is_active' => true,
            'slug' => 'h2746-report',
            'title' => 'Курс для отчёта',
        ]);
        CourseBlock::factory()->for($course)->withDates($start, $start->copy()->addDays(30))->create(['number' => 1]);
        Tariff::factory()->block(1)->create(['course_id' => $course->id, 'price' => 4800]);

        $chatA = Group::create(['name' => 'Поток A', 'telegram_chat_id' => '-100RA', 'status' => 'active']);
        $chatB = Group::create(['name' => 'Поток B', 'telegram_chat_id' => '-100RB', 'status' => 'active']);
        $course->groups()->attach([$chatA->id, $chatB->id]);

        $this->debtor = User::factory()->create([
            'name' => 'Иванова Мария Петровна',
            'telegram_id' => '660001',
        ]);
        $this->debtor->groups()->attach([$chatA->id, $chatB->id]);

        foreach ([35, 8] as $daysAgo) {
            DebtReminder::create([
                'user_id' => $this->debtor->id,
                'course_id' => $course->id,
                'block_number' => 1,
                'sent_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    public function test_report_redacts_identity_by_default(): void
    {
        $this->artisan('debts:chat-removal-report')
            ->assertSuccessful()
            ->expectsOutputToContain('user#'.$this->debtor->id)
            ->doesntExpectOutputToContain('Иванова');
    }

    public function test_reveal_flag_prints_the_name(): void
    {
        $this->artisan('debts:chat-removal-report --reveal')
            ->assertSuccessful()
            ->expectsOutputToContain('Иванова Мария Петровна');
    }

    public function test_fee_arithmetic_is_thousand_times_chats(): void
    {
        $this->artisan('debts:chat-removal-report')
            ->assertSuccessful()
            ->expectsOutputToContain('1000 × 2');
    }

    public function test_json_output_carries_rule_and_evidence(): void
    {
        // Именно Artisan::call, а не $this->artisan(): PendingCommand гоняет
        // команду через свой буфер и Artisan::output() оставляет пустым.
        $this->assertSame(0, Artisan::call('debts:chat-removal-report', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);

        $this->assertSame(30, $payload['rule']['min_days_overdue']);
        $this->assertSame(2, $payload['rule']['min_unanswered_contacts']);
        $this->assertSame(1000, $payload['rule']['reinstatement_fee']);
        $this->assertFalse($payload['rule']['auto_telegram_mutation']);

        $this->assertCount(2, $payload['eligible']);
        $row = $payload['eligible'][0];
        $this->assertSame('user#'.$this->debtor->id, $row['subject']);
        $this->assertSame(2, $row['unanswered_contacts']);
        $this->assertCount(2, $row['contact_attempts']);
        $this->assertSame([], $row['blockers']);
        $this->assertSame(2, $payload['fee_arithmetic'][0]['chats']);
        $this->assertSame(2000, $payload['fee_arithmetic'][0]['total']);
    }

    public function test_report_never_touches_telegram(): void
    {
        // Http::preventStrayRequests() в setUp: любой исходящий вызов —
        // исключение. Wave 1 обязана быть чисто отчётной.
        $this->artisan('debts:chat-removal-report --all')->assertSuccessful();

        $this->assertTrue(true);
    }
}
