<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Support\Hermes\LearningOps\DeadlineButlerService;
use App\Services\Support\Hermes\LearningOps\ProgressTrackerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Департамент C «Учебные операции» (H6103, E026) — приёмка фазы-1 в тени:
 * роли W2 27 `deadline-butler` и 28 `progress-tracker` + реестр департамента
 * config/school_learning_ops.php. Обе роли — детерминированные suggester-черновики
 * (ноль LLM-вызовов, ноль записей, human-send Q3, без скидочной автономии Q6,
 * тон школы Q9); фича-флаги OFF по умолчанию — гейт волны W2 не снят, прод-письма
 * нет. Роль 23 `schedule-keeper` — житель W1, закрыта ребёнком H6187 и здесь
 * не дублируется (правило зонтика H6103).
 */
class H6103HermesLearningOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.hermes_deadline_butler' => true,
            'features.hermes_progress_tracker' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 14:35:00', config('app.timezone')));
    }

    /**
     * @return array{0: User, 1: Group, 2: Course}
     */
    private function studentGroupCourse(string $groupName = 'Бхагавадгита-1', string $memberSince = '2026-11-16 10:00:00'): array
    {
        $user = User::factory()->create(['name' => 'Мария']);
        $course = Course::factory()->create();
        $group = Group::create(['name' => $groupName]);
        $user->groups()->attach($group->id);
        DB::table('group_user')
            ->where('user_id', $user->id)
            ->where('group_id', $group->id)
            ->update(['created_at' => $memberSince, 'updated_at' => $memberSince]);

        return [$user->fresh(), $group, $course];
    }

    private function lessonWithHomework(Group $group, Course $course, string $title, array $attrs = []): Lesson
    {
        return Lesson::factory()->for($course)->create(array_merge([
            'title' => $title,
            'group_id' => $group->id,
            'is_published' => true,
            'homework_enabled' => true,
            'homework_prompt' => 'Разборите предложение из Бхагавадгиты',
        ], $attrs));
    }

    private function submission(User $user, Lesson $lesson, Course $course, string $status): HomeworkSubmission
    {
        return HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    public function test_deadline_butler_off_by_default(): void
    {
        $fileDefaults = require config_path('features.php');
        config(['features.hermes_deadline_butler' => $fileDefaults['hermes_deadline_butler']]);
        [$user] = $this->studentGroupCourse();

        $result = $this->app->make(DeadlineButlerService::class)->draftFor($user);

        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(27, $result['role_id']);
        $this->assertArrayNotHasKey('draft', $result);
    }

    public function test_deadline_butler_golden_draft(): void
    {
        [$user, $group, $course] = $this->studentGroupCourse();

        $open = $this->lessonWithHomework($group, $course, 'Занятие 12'); // не начинал
        $revision = $this->lessonWithHomework($group, $course, 'Занятие 13'); // доработка
        $review = $this->lessonWithHomework($group, $course, 'Занятие 14'); // на проверке
        $done = $this->lessonWithHomework($group, $course, 'Занятие 15'); // принято — скрыто
        $this->lessonWithHomework($group, $course, 'Занятие 16', ['is_published' => false]); // не опубликован
        $closed = $this->lessonWithHomework($group, $course, 'Занятие 17'); // приём закрыт, не сдано
        $closed->forceFill(['homework_closed_at' => now()->subDay()])->save();

        $this->submission($user, $revision, $course, HomeworkSubmission::STATUS_NEEDS_REVISION);
        $this->submission($user, $review, $course, HomeworkSubmission::STATUS_SUBMITTED);
        $this->submission($user, $done, $course, HomeworkSubmission::STATUS_ACCEPTED);

        $lessonsBefore = Lesson::count();
        $submissionsBefore = HomeworkSubmission::count();

        $result = $this->app->make(DeadlineButlerService::class)->draftFor($user);

        $this->assertTrue($result['ok']);
        $this->assertSame('deadline-butler', $result['role']);
        $this->assertSame(27, $result['role_id']);

        // Дедлайны честные: приём закрыт без сдачи — вопрос человеку, не давления на студента.
        $this->assertTrue($result['requires_human_verification']);
        $this->assertSame('closed_homework_without_submission', $result['verification_reason']);

        $this->assertSame(
            "По домашним заданиям на 18.11.2026:\n"
            ."· «Занятие 12» — домашка ждёт вас, сдать можно в кабинете\n"
            ."· «Занятие 13» — домашка ждёт доработки после проверки\n"
            ."На проверке у преподавателя: «Занятие 14».\n"
            .'Если что-то не открывается или нужен перенос — просто напишите нам, разберёмся.',
            $result['draft'],
        );

        // Q6 — без скидочной автономии: ни слова о деньгах.
        $this->assertDoesNotMatchRegularExpression('/скидк|рассроч|оплат|рубл|₽|₴|\$/iu', $result['draft']);

        $this->assertSame($closed->id, $result['facts']['closed_unsubmitted'][0]);
        $this->assertSame($open->id, $result['facts']['awaiting'][0]['lesson_id']);
        $this->assertSame('not_started', $result['facts']['awaiting'][0]['submission_status']);
        $this->assertSame('needs_revision', $result['facts']['awaiting'][1]['submission_status']);
        $this->assertSame($review->id, $result['facts']['on_review'][0]['lesson_id']);

        // Только чтение: ни одной записи.
        $this->assertSame($lessonsBefore, Lesson::count());
        $this->assertSame($submissionsBefore, HomeworkSubmission::count());
    }

    public function test_deadline_butler_all_submitted(): void
    {
        [$user, $group, $course] = $this->studentGroupCourse();
        $done = $this->lessonWithHomework($group, $course, 'Занятие 12');
        $this->submission($user, $done, $course, HomeworkSubmission::STATUS_ACCEPTED);

        $result = $this->app->make(DeadlineButlerService::class)->draftFor($user);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['requires_human_verification']);
        $this->assertSame(
            "По домашним заданиям на 18.11.2026:\n"
            .'Открытых домашних заданий нет — всё сдано, новых работ пока не появилось.',
            $result['draft'],
        );
    }

    public function test_deadline_butler_without_groups(): void
    {
        $user = User::factory()->create();

        $result = $this->app->make(DeadlineButlerService::class)->draftFor($user);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_active_groups', $result['reason']);
    }

    public function test_progress_tracker_off_by_default(): void
    {
        $fileDefaults = require config_path('features.php');
        config(['features.hermes_progress_tracker' => $fileDefaults['hermes_progress_tracker']]);
        [$user, $group] = $this->studentGroupCourse();

        $result = $this->app->make(ProgressTrackerService::class)->digestForGroup($group);

        $this->assertFalse($result['ok']);
        $this->assertSame('disabled', $result['reason']);
        $this->assertSame(28, $result['role_id']);
    }

    public function test_progress_tracker_golden_digest(): void
    {
        [$maria, $group, $course] = $this->studentGroupCourse('Бхагавадгита-1', '2026-10-29 10:00:00');

        $first = $this->lessonWithHomework($group, $course, 'Урок 1');
        $second = $this->lessonWithHomework($group, $course, 'Урок 2');

        [$anna] = $this->studentInGroup($group, 'Анна', '2026-11-01 10:00:00');
        [$pavel] = $this->studentInGroup($group, 'Павел', '2026-10-20 10:00:00');
        [$olga] = $this->studentInGroup($group, 'Ольга', '2026-11-17 10:00:00');

        // Мария — 2 из 2; Анна — 1 из 2; Павель/Ольга — 0 из 2.
        $maria->lessonProgress()->attach([$first->id => ['is_completed' => true], $second->id => ['is_completed' => true]]);
        $anna->lessonProgress()->attach([$first->id => ['is_completed' => true]]);

        $pivotBefore = DB::table('lesson_user')->count();

        $result = $this->app->make(ProgressTrackerService::class)->digestForGroup($group);

        $this->assertTrue($result['ok']);
        $this->assertSame('progress-tracker', $result['role']);
        $this->assertSame(28, $result['role_id']);
        $this->assertFalse($result['requires_human_verification']);

        // Средний прогресс: (100 + 50 + 0 + 0) / 4 = 38%.
        $this->assertSame(38, $result['facts']['average_percent']);
        $this->assertSame(2, $result['facts']['published_lessons']);
        $this->assertSame(4, $result['facts']['active_students']);

        // Красный флаг — только стаж 14+ дней без пройденных: Павел, не Ольга.
        $this->assertSame([$pavel->id], $result['facts']['falling_behind_user_ids']);
        $this->assertSame('lesson_user.is_completed (H3764)', $result['facts']['canonical_source']);

        $this->assertSame(
            "Сводка прогресса группы «Бхагавадгита-1» на 18.11.2026:\n"
            ."Опубликованных уроков: 2, активных студентов: 4.\n"
            ."Средний прогресс по группе: 38%.\n"
            ."Стоит подтянуть (нет пройденных уроков при стаже от 14 дней):\n"
            ."· Павел — 0 из 2, в группе с 2026-10-20\n"
            .'Полный разбор по каждому студенту — в панели группы.',
            $result['draft'],
        );

        // Только чтение: пивот не тронут.
        $this->assertSame($pivotBefore, DB::table('lesson_user')->count());
    }

    public function test_progress_tracker_without_published_lessons(): void
    {
        [$user, $group, $course] = $this->studentGroupCourse();

        $result = $this->app->make(ProgressTrackerService::class)->digestForGroup($group);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_published_lessons', $result['reason']);
    }

    public function test_dept_c_registry_matches_roster(): void
    {
        $roles = config('school_learning_ops.roles');

        // Канонический состав §C ростера v1: ID 23–34, без новых ID.
        $this->assertSame(range(23, 34), array_keys($roles));
        $this->assertSame([
            23 => 'schedule-keeper',
            24 => 'teacher-dispatcher',
            25 => 'attendance-tracker',
            26 => 'room-steward',
            27 => 'deadline-butler',
            28 => 'progress-tracker',
            29 => 'homework-dispatcher',
            30 => 'certificate-issuer',
            31 => 'cohort-registrar',
            32 => 'make-up-agent',
            33 => 'classroom-supply',
            34 => 'exam-session-agent',
        ], array_map(static fn (array $r): string => $r['name'], $roles));

        // Квота фазы-1 (Q2): W1-житель департамента — только 23, остальные — W2.
        $w1 = array_keys(array_filter($roles, static fn (array $r): bool => $r['wave'] === 'W1'));
        $this->assertSame([23], $w1);
        $gates = config('school_learning_ops.gates');
        $this->assertSame([23], $gates['q2_quota']['dept_w1_roles']);
        $this->assertSame(range(24, 34), $gates['q2_quota']['dept_w2_roles']);
        $this->assertSame(25, $gates['q2_quota']['phase1_total']);

        // Политические исключения (8/39/42) в департаменте отсутствуют.
        $excluded = config('school_role_quota.excluded_role_ids');
        $this->assertSame([], array_intersect($excluded, array_map(static fn (array $r): string => $r['name'], $roles)));

        // Гейты автономии ростера V2 — на месте.
        $this->assertTrue($gates['q3_suggester']);
        $this->assertTrue($gates['q5_pii_phase0']);
        $this->assertTrue($gates['q6_no_discount_autonomy']);
        $this->assertTrue($gates['q7_tokens']['deterministic_zero_llm']);

        // Реализованные строки указывают на живой сервис и фича-флаг OFF по умолчанию.
        $fileDefaults = require config_path('features.php');
        foreach ($roles as $id => $role) {
            if (($role['status'] ?? '') !== 'implemented') {
                continue;
            }
            $this->assertTrue(class_exists($role['service']), "role {$id} service missing");
            $this->assertArrayHasKey($role['feature_flag'], $fileDefaults);
            $this->assertFalse($fileDefaults[$role['feature_flag']], "role {$id} flag must default OFF");
        }

        // Роль 23 закрыта ребёнком H6187 — зонтик не пишет второй вариант.
        $this->assertSame('H6187', $roles[23]['implemented_by']);
        $this->assertSame('H6103', $roles[27]['implemented_by']);
        $this->assertSame('H6103', $roles[28]['implemented_by']);
    }

    /**
     * @return array{0: User}
     */
    private function studentInGroup(Group $group, string $name, string $memberSince): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->groups()->attach($group->id);
        DB::table('group_user')
            ->where('user_id', $user->id)
            ->where('group_id', $group->id)
            ->update(['created_at' => $memberSince, 'updated_at' => $memberSince]);

        return [$user->fresh()];
    }
}
