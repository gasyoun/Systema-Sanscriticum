<?php

declare(strict_types=1);

namespace Tests\Feature\Support\StudentAgent;

use App\Models\Course;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\StudentAgent\StudentAgentService;
use App\Services\Support\StudentAgent\Tools\CabinetFaqTool;
use App\Services\Support\StudentAgent\Tools\DictionaryLookupTool;
use App\Services\Support\StudentAgent\Tools\HomeworkHintTool;
use App\Services\Support\StudentAgent\Tools\StudentAgentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3231 (Wave 3): bounded student agent. Not a free-form tutor — exactly
 * three tools, hard allow-list, CONFIRM before any irreversible tool.
 */
class StudentAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): StudentAgentService
    {
        return $this->app->make(StudentAgentService::class);
    }

    public function test_disabled_by_default(): void
    {
        $this->assertFalse($this->agent()->isEnabled());
    }

    public function test_refuses_when_flag_off(): void
    {
        config()->set('features.student_agent', false);
        $student = User::factory()->create();

        $result = $this->agent()->handle($student, 'dictionary_lookup', ['query' => 'agni']);

        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['reason']);
    }

    /** The core "no free-form tutor" guarantee: anything outside the three named jobs is refused outright. */
    public function test_refuses_out_of_scope_tool_ask(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $result = $this->agent()->handle($student, 'free_chat', ['message' => 'ответь на любой вопрос']);

        $this->assertFalse($result['ok']);
        $this->assertSame('tool_not_allowed', $result['reason']);
    }

    public function test_allowed_tools_are_exactly_the_three_jobs(): void
    {
        $this->assertSame(
            ['homework_hint', 'dictionary_lookup', 'cabinet_faq'],
            $this->agent()->allowedTools(),
        );
    }

    public function test_irreversible_tool_requires_confirmation_first(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $irreversible = new class implements StudentAgentTool
        {
            public function name(): string
            {
                return 'delete_something';
            }

            public function isIrreversible(): bool
            {
                return true;
            }

            public function run(User $user, array $params): array
            {
                return ['ok' => true, 'data' => ['deleted' => true]];
            }
        };

        $agent = new StudentAgentService(
            app(DictionaryLookupTool::class),
            app(CabinetFaqTool::class),
            app(HomeworkHintTool::class),
            [$irreversible],
        );

        $unconfirmed = $agent->handle($student, 'delete_something', []);
        $this->assertFalse($unconfirmed['ok']);
        $this->assertSame('confirmation_required', $unconfirmed['reason']);
        $this->assertTrue($unconfirmed['requires_confirmation']);

        $confirmed = $agent->handle($student, 'delete_something', [], confirmed: true);
        $this->assertTrue($confirmed['ok']);
    }

    public function test_dictionary_lookup_is_deterministic_db_search_no_llm(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $dictionary = Dictionary::create(['name' => 'MW', 'is_active' => true]);
        DictionaryWord::create([
            'dictionary_id' => $dictionary->id,
            'devanagari' => 'अग्नि',
            'iast' => 'agni',
            'cyrillic' => 'агни',
            'translation' => 'огонь',
        ]);

        $result = $this->agent()->handle($student, 'dictionary_lookup', ['query' => 'agni']);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['data']['hits']);
        $this->assertSame('agni', $result['data']['hits'][0]['iast']);
        $this->assertNull($result['budget']['cost_usd']);
        $this->assertFalse($result['budget']['cost_evaluable']);
    }

    public function test_homework_hint_prefers_teacher_comment_over_llm(): void
    {
        config()->set('features.student_agent', true);
        $this->mock(CuratorAi::class, function ($mock) {
            $mock->shouldNotReceive('chatWithUsage');
        });

        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'teacher-h3231@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $student = User::factory()->create();

        $submission = HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);

        HomeworkComment::create([
            'submission_id' => $submission->id,
            'author_id' => $teacher->id,
            'author_role' => HomeworkComment::ROLE_TEACHER,
            'type' => HomeworkComment::TYPE_REVIEW,
            'body' => 'Посмотри правило сандхи для конечного -s.',
        ]);

        $result = $this->agent()->handle($student, 'homework_hint', ['submission_id' => $submission->id]);

        $this->assertTrue($result['ok']);
        $this->assertSame('teacher_comment', $result['data']['source']);
        $this->assertSame('Посмотри правило сандхи для конечного -s.', $result['data']['hint']);
    }

    public function test_homework_hint_refuses_someone_elses_submission(): void
    {
        config()->set('features.student_agent', true);

        $teacher = Teacher::create(['name' => 'Препод 2', 'email' => 'teacher-h3231b@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $submission = HomeworkSubmission::create([
            'user_id' => $owner->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now(),
        ]);

        $result = $this->agent()->handle($stranger, 'homework_hint', ['submission_id' => $submission->id]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_owner', $result['reason']);
    }

    /** Own-data fixture: reuses the real committed H2448 corpus (resources/knowledge/faq.md). */
    public function test_cabinet_faq_reuses_h2448_bm25_retriever(): void
    {
        config()->set('features.student_agent', true);
        config()->set('features.faq_rag_suggester', true);
        $student = User::factory()->create();

        $result = $this->agent()->handle($student, 'cabinet_faq', ['query' => 'домашние задания проверка']);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['data']['hits']);
    }

    public function test_cabinet_faq_refuses_when_faq_rag_flag_off(): void
    {
        config()->set('features.student_agent', true);
        config()->set('features.faq_rag_suggester', false);
        $student = User::factory()->create();

        $result = $this->agent()->handle($student, 'cabinet_faq', ['query' => 'домашние задания']);

        $this->assertFalse($result['ok']);
        $this->assertSame('faq_rag_disabled', $result['reason']);
    }
}
