<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Lead;
use App\Models\PaymentPromise;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\WorkQueueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Агрегатор «Моя работа сегодня» (H221): каждый из четырёх бакетов наполняется
 * из реальных сигналов и попадает в счётчики.
 */
class WorkQueueReportTest extends TestCase
{
    use RefreshDatabase;

    private function report(): WorkQueueReport
    {
        return app(WorkQueueReport::class);
    }

    /** @test */
    public function all_four_buckets_populate_from_seeded_signals(): void
    {
        // 1) Лид на контакт сегодня.
        Lead::create([
            'name' => 'Лид Сегодня',
            'contact' => 'tg:@lead',
            'status' => 'new',
            'next_contact_at' => now()->toDateString(),
        ]);
        // Лид не должен попасть: контакт в будущем.
        Lead::create([
            'name' => 'Лид Завтра',
            'contact' => 'tg:@later',
            'status' => 'new',
            'next_contact_at' => now()->addWeek()->toDateString(),
        ]);
        // Лид не должен попасть: конверсия.
        Lead::create([
            'name' => 'Лид Конверсия',
            'contact' => 'tg:@won',
            'status' => 'converted',
            'next_contact_at' => now()->subDay()->toDateString(),
        ]);

        // 2) Просроченное обещание (installment_group_id — чтобы не дёргать нотифаер).
        $course = Course::factory()->create();
        $debtor = User::factory()->create();
        PaymentPromise::create([
            'user_id' => $debtor->id,
            'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(),
            'status' => PaymentPromise::STATUS_ACTIVE,
            'installment_group_id' => 'grp-test',
        ]);

        // 3) Застрявший студент: заходил, но 20 дней неактивен.
        User::factory()->create([
            'role' => null,
            'last_login_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(20),
        ]);

        // 4) Поддержка без ответа: открытый тред, последнее сообщение входящее 4 ч назад.
        $student = User::factory()->create();
        SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now()->subHours(4),
        ]);
        $msg = ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'Помогите с оплатой',
            'is_read' => false,
        ]);
        $msg->forceFill(['created_at' => now()->subHours(4)])->save();

        $report = $this->report();

        $this->assertCount(1, $report->leadsToContact(), 'лиды на контакт сегодня');
        $this->assertGreaterThanOrEqual(1, $report->overduePromises()->count(), 'обещания');
        $this->assertGreaterThanOrEqual(1, $report->stuckStudents()->count(), 'риск оттока');
        $this->assertCount(1, $report->unansweredSupport(), 'поддержка без ответа');

        $counts = $report->counts();
        $this->assertSame(1, $counts['leads']);
        $this->assertSame(1, $counts['support']);
    }

    /** @test */
    public function support_bucket_excludes_recently_answered_threads(): void
    {
        $student = User::factory()->create();
        SupportConversation::create([
            'user_id' => $student->id,
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
        // Последнее сообщение — ответ куратора: тред НЕ ждёт ответа.
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'user',
            'text' => 'вопрос',
            'is_read' => true,
        ])->forceFill(['created_at' => now()->subHours(5)])->save();
        ChatMessage::create([
            'user_id' => $student->id,
            'role' => 'curator',
            'text' => 'ответ',
            'is_read' => true,
        ])->forceFill(['created_at' => now()->subHour()])->save();

        $this->assertCount(0, $this->report()->unansweredSupport());
    }
}
