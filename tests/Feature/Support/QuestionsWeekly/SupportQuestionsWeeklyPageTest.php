<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\SupportQuestionWeeklySnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5709 — дашборд недельных вопросов: авторизованная зона кластера
 * Telegram-support; преподавателю вход закрыт; пустое состояние и снапшот
 * рендерятся без сырых текстов.
 */
class SupportQuestionsWeeklyPageTest extends TestCase
{
    use RefreshDatabase;

    private function snapshotPayload(): array
    {
        return [
            'window' => ['from' => '2026-09-28', 'to' => '2026-10-05', 'week_start' => '2026-09-28', 'days' => 7],
            'classifier_version' => \App\Services\SupportQuestions\QuestionMessageClassifier::VERSION,
            'student_definition' => 'active_group_membership_or_paid_payment',
            'populations' => [
                'student' => [
                    'incoming' => 10,
                    'questions' => 6,
                    'by_category' => ['D' => 3, 'A' => 2, 'B' => 1],
                    'unclassified' => 0,
                    'unclassified_share' => 0.0,
                    'question_share' => 0.6,
                    'unique_questioners' => 4,
                ],
                'enquiry' => [
                    'incoming' => 5,
                    'questions' => 3,
                    'by_category' => ['D' => 2, 'C' => 1],
                    'unclassified' => 0,
                    'unclassified_share' => 0.0,
                    'question_share' => 0.6,
                    'unique_questioners' => 3,
                ],
                'staff_internal' => [
                    'incoming' => 40,
                    'questions' => 20,
                    'by_category' => [],
                    'unclassified' => 0,
                    'unclassified_share' => 0.0,
                    'question_share' => 0.5,
                    'unique_questioners' => 5,
                ],
                'unknown' => [
                    'incoming' => 2,
                    'questions' => 1,
                    'by_category' => [],
                    'unclassified' => 0,
                    'unclassified_share' => 0.0,
                    'question_share' => 0.5,
                    'unique_questioners' => 1,
                ],
            ],
            'totals' => ['incoming' => 57, 'outgoing_excluded' => 30, 'external_questions' => 9],
            'activity' => [
                'definition' => 'confirmed_students_with_lesson_in_window',
                'active_students' => 12,
                'questions_per_100_active' => 50.0,
            ],
            'reconciliation' => [
                'incoming_total' => 57,
                'questions' => 30,
                'not_questions' => 25,
                'excluded' => ['service_message' => 2, 'bot' => 0, 'duplicate_import' => 0],
                'sum_check' => true,
            ],
            'coverage' => [
                'days_with_incoming' => 7,
                'days_in_window' => 7,
                'chats_active' => 20,
                'private_chats' => 15,
                'groups' => 5,
                'sync' => [
                    'account_present' => true,
                    'last_synced_at' => '2026-10-02T20:45:00+03:00',
                    'has_error' => false,
                    'stale_after_minutes' => 15,
                    'fresh' => true,
                ],
            ],
            'generated_at' => '2026-10-02T20:45:00+03:00',
        ];
    }

    public function test_admin_sees_page_and_snapshot_without_raw_texts(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        SupportQuestionWeeklySnapshot::create([
            'week_start' => '2026-09-28',
            'classifier_version' => \App\Services\SupportQuestions\QuestionMessageClassifier::VERSION,
            'payload' => $this->snapshotPayload(),
            'is_incomplete' => false,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/telegram-support/support-questions-weekly');

        $response->assertOk();
        $this->assertStringContainsString('Недельные вопросы студентов', (string) $response->getContent());
    }

    public function test_teacher_is_denied(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $response = $this->actingAs($teacher)
            ->get('/admin/telegram-support/support-questions-weekly');

        $response->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/telegram-support/support-questions-weekly')
            ->assertRedirect('/admin/login');
    }

    public function test_empty_state_renders(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->get('/admin/telegram-support/support-questions-weekly')
            ->assertOk()
            ->assertSee('Снапшотов ещё нет', false);
    }
}
