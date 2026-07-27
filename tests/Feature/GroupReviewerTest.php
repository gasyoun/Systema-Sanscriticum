<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\HomeworkSubmissionResource;
use App\Filament\Resources\LessonResource;
use App\Filament\Resources\StudentGroupResource;
use App\Mail\HomeworkReviewerDigestMail;
use App\Mail\HomeworkSubmittedMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\HomeworkNotifier;
use App\Services\TeacherSalaryService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Грант «проверяющий ↔ группа» (H1729): Ольга проверяет домашки групп Гасунса,
 * не становясь преподавателем его курсов и не получая за них зарплату.
 */
class GroupReviewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function reviewer(): User
    {
        return User::factory()->create(['role' => Roles::TEACHER, 'name' => 'Ольга']);
    }

    private function grant(Group $group, User $user, bool $canReview = true, bool $notify = true): void
    {
        $group->reviewers()->attach($user->id, [
            'can_review' => $canReview,
            'notify' => $notify,
            'assigned_at' => now(),
        ]);
    }

    /** @return array{course: Course, group: Group, lesson: Lesson, student: User} */
    private function groupWithCourse(string $groupName, ?Teacher $teacher = null): array
    {
        $teacher ??= Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $group = Group::create(['name' => $groupName]);
        $group->courses()->attach($course->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        $lesson = Lesson::factory()->for($course)->create();

        return ['course' => $course, 'group' => $group, 'lesson' => $lesson, 'student' => $student];
    }

    /**
     * Без явного студента заводим НОВОГО и вписываем его в группу контекста:
     * пара (user_id, lesson_id) в homework_submissions уникальна, поэтому
     * переиспользование одного студента ломало бы второй submit по тому же уроку.
     */
    private function submit(array $ctx, ?User $student = null, string $status = HomeworkSubmission::STATUS_SUBMITTED): HomeworkSubmission
    {
        if ($student === null && isset($ctx['group'])) {
            $student = User::factory()->create();
            $student->groups()->attach($ctx['group']->id);
        }

        return HomeworkSubmission::create([
            'user_id' => ($student ?? $ctx['student'])->id,
            'lesson_id' => $ctx['lesson']->id,
            'course_id' => $ctx['course']->id,
            'status' => $status,
            'last_activity_at' => now(),
        ]);
    }

    /** @return Collection<int, HomeworkSubmission> */
    private function queueFor(User $user)
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return HomeworkSubmissionResource::getEloquentQuery()->get();
    }

    /** @test */
    public function grant_is_unique_per_group_and_user(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();

        $this->grant($ctx['group'], $olga);

        $this->expectException(QueryException::class);
        $this->grant($ctx['group'], $olga);
    }

    /** @test */
    public function reviewer_sees_submissions_of_granted_group_only(): void
    {
        $teacher = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $g60 = $this->groupWithCourse('60', $teacher);
        $g61 = $this->groupWithCourse('61', $teacher);
        $g99 = $this->groupWithCourse('99', $teacher);

        $olga = $this->reviewer();
        $this->grant($g60['group'], $olga);
        $this->grant($g61['group'], $olga);

        $mine60 = $this->submit($g60);
        $mine61 = $this->submit($g61);
        $foreign = $this->submit($g99);

        $visible = $this->queueFor($olga)->pluck('id');

        $this->assertTrue($visible->contains($mine60->id), 'работа группы 60 должна быть видна');
        $this->assertTrue($visible->contains($mine61->id), 'работа группы 61 должна быть видна');
        $this->assertFalse($visible->contains($foreign->id), 'работа чужой группы видна быть не должна');
    }

    /** @test */
    public function two_groups_on_one_course_do_not_leak_into_each_other(): void
    {
        // Одна и та же пара «курс + общий урок», две группы. Урок без group_id —
        // разделять их может только членство студента в группе.
        $teacher = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create(['group_id' => null]);

        $g60 = Group::create(['name' => '60']);
        $g61 = Group::create(['name' => '61']);
        $g60->courses()->attach($course->id);
        $g61->courses()->attach($course->id);

        $studentIn60 = User::factory()->create();
        $studentIn60->groups()->attach($g60->id);
        $studentIn61 = User::factory()->create();
        $studentIn61->groups()->attach($g61->id);

        $olga = $this->reviewer();
        $this->grant($g60, $olga);

        $ctx = ['course' => $course, 'lesson' => $lesson, 'student' => $studentIn60];
        $visibleWork = $this->submit($ctx, $studentIn60);
        $hiddenWork = $this->submit($ctx, $studentIn61);

        $visible = $this->queueFor($olga)->pluck('id');

        $this->assertTrue($visible->contains($visibleWork->id));
        $this->assertFalse($visible->contains($hiddenWork->id), 'работа студента группы 61 не должна течь проверяющему группы 60');
    }

    /** @test */
    public function student_in_two_groups_does_not_leak_a_foreign_course(): void
    {
        // Ученик состоит и в подшефной группе 60, и в хинди-группе. Его работа по
        // курсу хинди не должна попасть проверяющему группы 60.
        $g60 = $this->groupWithCourse('60');
        $hindi = $this->groupWithCourse('хинди-12');

        $student = $g60['student'];
        $student->groups()->attach($hindi['group']->id);

        $olga = $this->reviewer();
        $this->grant($g60['group'], $olga);

        $own = $this->submit($g60, $student);
        $foreignCourse = $this->submit($hindi, $student);

        $visible = $this->queueFor($olga)->pluck('id');

        $this->assertTrue($visible->contains($own->id));
        $this->assertFalse($visible->contains($foreignCourse->id), 'курс чужой группы не должен открываться грантом');
    }

    /** @test */
    public function lesson_bound_to_a_group_is_scoped_by_that_group(): void
    {
        $teacher = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);

        $g60 = Group::create(['name' => '60']);
        $g61 = Group::create(['name' => '61']);
        $g60->courses()->attach($course->id);
        $g61->courses()->attach($course->id);

        $lesson60 = Lesson::factory()->for($course)->create(['group_id' => $g60->id]);
        $lesson61 = Lesson::factory()->for($course)->create(['group_id' => $g61->id]);

        // Студент состоит в обеих группах — разделяет только group_id урока.
        $student = User::factory()->create();
        $student->groups()->attach([$g60->id, $g61->id]);

        $olga = $this->reviewer();
        $this->grant($g60, $olga);

        $work60 = HomeworkSubmission::create([
            'user_id' => $student->id, 'lesson_id' => $lesson60->id, 'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED, 'last_activity_at' => now(),
        ]);
        $work61 = HomeworkSubmission::create([
            'user_id' => $student->id, 'lesson_id' => $lesson61->id, 'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED, 'last_activity_at' => now(),
        ]);

        $visible = $this->queueFor($olga)->pluck('id');

        $this->assertTrue($visible->contains($work60->id));
        $this->assertFalse($visible->contains($work61->id), 'урок другой группы не должен быть виден');
    }

    /** @test */
    public function reviewer_never_sees_own_submissions(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        // Ольга сама учится в этой же группе и сдала работу.
        $olga->groups()->attach($ctx['group']->id);
        $ownWork = $this->submit($ctx, $olga);
        $studentWork = $this->submit($ctx);

        $visible = $this->queueFor($olga)->pluck('id');

        $this->assertTrue($visible->contains($studentWork->id));
        $this->assertFalse($visible->contains($ownWork->id), 'свою работу проверяющий в очереди видеть не должен');
        $this->assertFalse(HomeworkSubmissionResource::canReview($ownWork), 'и вердикт по своей работе выносить не может');
    }

    /** @test */
    public function drafts_stay_out_of_the_reviewer_queue(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        $draft = $this->submit($ctx, null, HomeworkSubmission::STATUS_DRAFT);

        $this->assertFalse($this->queueFor($olga)->pluck('id')->contains($draft->id));
    }

    /** @test */
    public function navigation_badge_counts_only_visible_submitted_work(): void
    {
        $teacher = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $g60 = $this->groupWithCourse('60', $teacher);
        $g99 = $this->groupWithCourse('99', $teacher);

        $olga = $this->reviewer();
        $this->grant($g60['group'], $olga);

        $this->submit($g60);
        $this->submit($g60, null, HomeworkSubmission::STATUS_ACCEPTED);
        $this->submit($g99);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($olga);

        $this->assertSame('1', HomeworkSubmissionResource::getNavigationBadge());
    }

    /** @test */
    public function can_review_flag_gates_the_verdict_but_not_visibility(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga, canReview: false);

        $work = $this->submit($ctx);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($olga);

        $this->assertTrue(HomeworkSubmissionResource::getEloquentQuery()->pluck('id')->contains($work->id));
        $this->assertFalse(HomeworkSubmissionResource::canReview($work));
    }

    /** @test */
    public function reviewer_with_verdict_right_can_review(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        $work = $this->submit($ctx);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($olga);

        $this->assertTrue(HomeworkSubmissionResource::canReview($work));
    }

    /** @test */
    public function reviewer_sees_granted_group_roster_and_nothing_else(): void
    {
        $teacher = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $g60 = $this->groupWithCourse('60', $teacher);
        $g99 = $this->groupWithCourse('99', $teacher);

        $olga = $this->reviewer();
        $this->grant($g60['group'], $olga);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($olga);

        $ids = StudentGroupResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($g60['group']->id));
        $this->assertFalse($ids->contains($g99['group']->id));
        $this->assertTrue(StudentGroupResource::canView($g60['group']));
        $this->assertFalse(StudentGroupResource::canView($g99['group']));
    }

    /** @test */
    public function grant_does_not_open_courses_lessons_or_schedule(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($olga);

        // teacher_id у Ольги не проставлен — эти разделы скоупятся по нему и
        // грантом не расширяются (решение 13: домашки + состав + посещаемость).
        $this->assertTrue(
            CourseResource::getEloquentQuery()->get()->isEmpty(),
            'грант не должен открывать курсы',
        );
        $this->assertTrue(
            LessonResource::getEloquentQuery()->get()->isEmpty(),
            'грант не должен открывать уроки',
        );
    }

    /**
     * Главный регрессионный тест H1729: грант не трогает зарплатный контур.
     *
     * @test
     */
    public function grant_does_not_accrue_salary_for_the_reviewer(): void
    {
        $gasuns = Teacher::create(['name' => 'Гасунс', 'email' => 'gasuns@example.test']);
        $olgaTeacher = Teacher::create(['name' => 'Ольга', 'email' => 'olga@example.test']);

        $ctx = $this->groupWithCourse('60', $gasuns);

        $olga = $this->reviewer();
        $olga->update(['teacher_id' => $olgaTeacher->id]);

        $service = app(TeacherSalaryService::class);
        $before = $service->totalForTeacher($olgaTeacher->fresh());

        $this->grant($ctx['group'], $olga);

        $after = app(TeacherSalaryService::class)->totalForTeacher($olgaTeacher->fresh());

        $this->assertSame(
            $before,
            $after,
            'грант проверяющего не должен менять начисление ЗП — иначе это путь со-преподавателя, который H1729 запрещает',
        );
        $this->assertNull(
            $ctx['course']->salaryTermsFor($olgaTeacher->id),
            'проверяющий не должен появляться в условиях ЗП курса',
        );
    }

    /** @test */
    public function submission_notifies_the_reviewer_on_every_configured_channel(): void
    {
        config()->set('homework.reviewers.notify_channels', ['database', 'mail']);

        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        NotificationFacade::fake();

        $work = $this->submit($ctx);
        app(HomeworkNotifier::class)->submitted($work);

        // Колокольчик в админке — это DatabaseNotification, адресованное ей лично.
        NotificationFacade::assertSentTo($olga, DatabaseNotification::class);
        Mail::assertQueued(HomeworkSubmittedMail::class);
    }

    /** @test */
    public function digest_mode_replaces_the_per_submission_mail_to_the_course_teacher(): void
    {
        config()->set('homework.reviewers.notify_channels', ['database']);
        config()->set('homework.reviewers.digest_enabled', true);

        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        NotificationFacade::fake();

        app(HomeworkNotifier::class)->submitted($this->submit($ctx));

        // Проверяющему — колокольчик; преподавателю курса письма нет, он получит сводку.
        NotificationFacade::assertSentTo($olga, DatabaseNotification::class);
        Mail::assertNothingQueued();
    }

    /** @test */
    public function reviewer_with_notify_off_is_not_messaged(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga, notify: false);

        $work = $this->submit($ctx);

        $this->assertTrue(app(HomeworkNotifier::class)->reviewersFor($work)->isEmpty());
    }

    /**
     * Регрессия: группы без проверяющего продолжают работать ровно как раньше —
     * преподаватель курса получает письмо на каждую сданную работу.
     *
     * @test
     */
    public function course_without_reviewers_still_mails_the_teacher_per_submission(): void
    {
        $ctx = $this->groupWithCourse('77');

        $work = $this->submit($ctx);
        app(HomeworkNotifier::class)->submitted($work);

        Mail::assertQueued(HomeworkSubmittedMail::class, 1);
    }

    /** @test */
    public function weekly_digest_reaches_the_course_teacher_and_stays_silent_when_empty(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);

        // Пусто — письма быть не должно.
        $this->artisan('homework:reviewer-digest')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->submit($ctx);

        $this->artisan('homework:reviewer-digest')->assertSuccessful();
        Mail::assertQueued(HomeworkReviewerDigestMail::class, 1);
    }

    /** @test */
    public function digest_skips_courses_without_reviewers(): void
    {
        $this->groupWithCourse('77');

        $this->artisan('homework:reviewer-digest')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    /**
     * Проверяющий с выключенным notify не подавляет письма преподавателю —
     * значит и сводка по такому курсу не положена, иначе придёт и то, и другое.
     *
     * @test
     */
    public function digest_skips_a_course_whose_reviewer_has_notify_off(): void
    {
        $ctx = $this->groupWithCourse('60');
        $this->grant($ctx['group'], $this->reviewer(), notify: false);
        $this->submit($ctx);

        $this->artisan('homework:reviewer-digest')->assertSuccessful();

        Mail::assertNotQueued(HomeworkReviewerDigestMail::class);
    }

    /** @test */
    public function feature_switch_off_revokes_every_grant(): void
    {
        $ctx = $this->groupWithCourse('60');
        $olga = $this->reviewer();
        $this->grant($ctx['group'], $olga);
        $work = $this->submit($ctx);

        config()->set('homework.reviewers.enabled', false);

        $this->assertSame([], $olga->fresh()->reviewableGroupIds());
        $this->assertTrue($this->queueFor($olga->fresh())->pluck('id')->doesntContain($work->id));
    }
}
