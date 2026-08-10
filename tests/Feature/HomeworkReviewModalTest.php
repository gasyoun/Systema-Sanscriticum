<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Filament\Resources\HomeworkSubmissionResource\Pages\ListHomeworkSubmissions;
use App\Filament\Resources\HomeworkSubmissionResource\Pages\ViewHomeworkSubmission;
use App\Models\Course;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Модалка проверки домашней работы: набранный отзыв не должен теряться.
 *
 * Повод — случай на проде: преподаватель писал длинный комментарий, случайно
 * кликнул мимо модалки, и текст исчез. Закрытие вызывает unmountAction() →
 * array_pop(mountedActionsData), то есть данные стираются НА СЕРВЕРЕ и вернуть
 * их нечем. Отсюда две линии защиты: клик мимо не закрывает окно, а текст
 * дублируется черновиком в браузере.
 */
class HomeworkReviewModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function submission(): HomeworkSubmission
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-modal@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        return HomeworkSubmission::create([
            'user_id' => User::factory()->create()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /** Клик мимо модалки не должен уничтожать набранный отзыв. */
    public function test_review_modals_do_not_close_when_clicking_away(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        $page = Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])->instance();

        $this->assertFalse($page->getAction('accept')->isModalClosedByClickingAway());
        $this->assertFalse($page->getAction('return')->isModalClosedByClickingAway());
    }

    /** То же для массовой проверки — там форма та же, а работ несколько. */
    public function test_bulk_review_modals_do_not_close_when_clicking_away(): void
    {
        $this->actingAsAdmin();
        $this->submission();

        $table = Livewire::test(ListHomeworkSubmissions::class)->instance()->getTable();

        $this->assertFalse($table->getBulkAction('accept')->isModalClosedByClickingAway());
        $this->assertFalse($table->getBulkAction('return')->isModalClosedByClickingAway());
    }

    /**
     * Esc сознательно оставлен рабочим: это осознанный жест и ARIA-паттерн диалога,
     * в отличие от случайного клика. Текст при этом защищён черновиком.
     */
    public function test_escape_still_closes_the_review_modal(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        $page = Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])->instance();

        $this->assertTrue($page->getAction('accept')->isModalClosedByEscaping());
    }

    /** Черновик привязан к работе и вердикту — ключ уезжает в разметку модалки. */
    public function test_review_modal_carries_a_draft_key(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->mountAction('accept')
            ->assertSee(HomeworkSubmissionResource::reviewDraftKey($submission, HomeworkSubmission::STATUS_ACCEPTED), escape: false)
            // Ключ должен быть именно в Alpine-обвязке поля: сохранение по вводу и
            // восстановление при открытии, иначе он ничего не делает.
            ->assertSee('x-on:input.debounce.500ms', escape: false)
            ->assertSee('localStorage.setItem', escape: false)
            ->assertSee('$nextTick', escape: false);
    }

    /** У массовой проверки черновика нет: ключ привязать не к чему. */
    public function test_bulk_review_modal_has_no_draft_wiring(): void
    {
        $this->actingAsAdmin();
        $this->submission();

        Livewire::test(ListHomeworkSubmissions::class)
            ->mountTableBulkAction('accept', [])
            ->assertDontSee('hw-review-draft:', escape: false);
    }

    /**
     * Так черновик и «протухает»: успешная проверка добавляет review-комментарий,
     * версия в ключе растёт, и следующая модалка старый текст уже не увидит.
     */
    public function test_draft_key_changes_after_a_review_is_recorded(): void
    {
        $admin = $this->actingAsAdmin();
        $submission = $this->submission();

        $before = HomeworkSubmissionResource::reviewDraftKey($submission, HomeworkSubmission::STATUS_ACCEPTED);

        app(HomeworkService::class)->recordReview(
            $submission,
            $admin,
            HomeworkSubmission::STATUS_ACCEPTED,
            'Принято',
        );

        $after = HomeworkSubmissionResource::reviewDraftKey($submission->refresh(), HomeworkSubmission::STATUS_ACCEPTED);

        $this->assertNotSame($before, $after);
    }

    /** Сообщение студента в треде не должно обнулять черновик преподавателя. */
    public function test_student_message_does_not_change_the_draft_key(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        $before = HomeworkSubmissionResource::reviewDraftKey($submission, HomeworkSubmission::STATUS_ACCEPTED);

        HomeworkComment::create([
            'submission_id' => $submission->id,
            'author_id' => $submission->user_id,
            'author_role' => 'student',
            'type' => HomeworkComment::TYPE_MESSAGE,
            'body' => 'А когда проверите?',
        ]);

        $this->assertSame($before, HomeworkSubmissionResource::reviewDraftKey($submission->refresh(), HomeworkSubmission::STATUS_ACCEPTED));
    }

    /** Happy-path одиночной проверки: до сих пор эта страница не была покрыта вовсе. */
    public function test_accepting_records_the_review(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->mountAction('accept')
            ->setActionData(['body' => 'Отличная работа'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(HomeworkSubmission::STATUS_ACCEPTED, $submission->refresh()->status);
        $this->assertDatabaseHas('homework_comments', [
            'submission_id' => $submission->id,
            'type' => HomeworkComment::TYPE_REVIEW,
            'body' => 'Отличная работа',
        ]);
    }

    public function test_comment_templates_include_file_and_good_luck_phrases(): void
    {
        foreach ([HomeworkSubmission::STATUS_ACCEPTED, HomeworkSubmission::STATUS_NEEDS_REVISION] as $status) {
            $templates = HomeworkSubmissionResource::commentTemplates($status);
            $this->assertArrayHasKey('Смотрите комментарии в приложенном файле.', $templates);
            $this->assertArrayHasKey('Удачи!', $templates);
        }
    }

    public function test_join_comment_templates_combines_with_blank_line(): void
    {
        $joined = HomeworkSubmissionResource::joinCommentTemplates([
            'Смотрите комментарии в приложенном файле.',
            'Удачи!',
        ]);

        $this->assertSame(
            "Смотрите комментарии в приложенном файле.\n\nУдачи!",
            $joined,
        );
        $this->assertNull(HomeworkSubmissionResource::joinCommentTemplates([]));
        $this->assertNull(HomeworkSubmissionResource::joinCommentTemplates(null));
    }

    /** Multi-select шаблонов подставляет собранный текст в body. */
    public function test_selecting_multiple_templates_fills_body(): void
    {
        $this->actingAsAdmin();
        $submission = $this->submission();

        $phrases = [
            'Смотрите комментарии в приложенном файле.',
            'Удачи!',
        ];
        $expected = HomeworkSubmissionResource::joinCommentTemplates($phrases);

        Livewire::test(ViewHomeworkSubmission::class, ['record' => $submission->id])
            ->mountAction('accept')
            ->setActionData(['templates' => $phrases])
            ->assertActionDataSet(['body' => $expected]);
    }
}
