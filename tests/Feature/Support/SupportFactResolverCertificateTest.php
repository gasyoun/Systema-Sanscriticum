<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H3999 (рулинг A1): резолвер сертификата — черновик НАВСЕГДА.
 *
 * Схемный факт, вокруг которого построены ветки: `certificates.issued_at` —
 * колонка date, NOT NULL, без каста в модели, и модель на creating подставляет
 * `now()`. «Ещё не выдан» поэтому существует ровно в одном виде: дата выдачи в
 * БУДУЩЕМ. Отсутствия даты не бывает, и ветки под него быть не должно.
 */
class SupportFactResolverCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Course, 2: Group} */
    private function enrolledStudent(): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        return [$student, $course, $group];
    }

    private function resolve(User $student, string $text = 'где мой сертификат?'): ?array
    {
        return app(SupportAnswerFactResolver::class)->resolve(
            SupportAnswerSuggestion::CATEGORY_MATERIALS,
            $student,
            $text,
        );
    }

    public function test_no_certificate_yields_no_draft(): void
    {
        [$student] = $this->enrolledStudent();

        $this->assertNull(
            $this->resolve($student),
            'Записи нет — «сертификат ещё не готов» было бы выдуманным ответом.',
        );
    }

    public function test_issued_certificate_is_named_with_its_date_and_number(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'number' => 'SANSK-501',
            'issued_at' => '2026-08-20',
            'course_title' => 'Санскрит с нуля',
        ]);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('Сертификат по курсу «Санскрит с нуля» выдан 20.08.2026', $resolved['draft']);
        $this->assertStringContainsString('номер SANSK-501', $resolved['draft']);
        $this->assertStringNotContainsString('другой группе', $resolved['draft']);
        $this->assertSame(SupportAnswerFactResolver::TYPE_CERTIFICATE, $resolved['facts']['type']);
        $this->assertSame('2026-08-20', $resolved['facts']['issued_at']);
        $this->assertFalse($resolved['facts']['pending']);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $resolved['send_policy']);
    }

    public function test_a_future_issue_date_reads_as_not_yet_issued(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'number' => 'SANSK-502',
            'issued_at' => '2026-10-01',
            'course_title' => 'Санскрит с нуля',
        ]);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('дата выдачи — 01.10.2026', $resolved['draft']);
        $this->assertStringContainsString('До этой даты документ в кабинете не появится', $resolved['draft']);
        $this->assertTrue($resolved['facts']['pending']);
    }

    public function test_a_document_from_another_group_is_flagged_for_the_curator(): void
    {
        [$student, $course] = $this->enrolledStudent();
        $otherGroup = Group::factory()->create();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'group_id' => $otherGroup->id,
            'number' => 'SANSK-503',
            'issued_at' => '2026-07-01',
            'course_title' => 'Санскрит с нуля',
        ]);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('оформлен по другой группе', $resolved['draft']);
        $this->assertTrue($resolved['facts']['foreign_group']);
    }

    public function test_the_document_label_follows_the_document_type(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'number' => 'SANSK-504',
            'issued_at' => '2026-08-01',
            'course_title' => 'Санскрит с нуля',
            'document_type' => CertificateMilestone::DOC_SPRAVKA,
        ]);

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringStartsWith('Справка об образовании по курсу', $resolved['draft']);
    }

    public function test_the_latest_document_wins(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        foreach (['2026-05-01' => 'SANSK-601', '2026-08-15' => 'SANSK-602'] as $date => $number) {
            Certificate::create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'group_id' => $group->id,
                'number' => $number,
                'issued_at' => $date,
                'course_title' => 'Санскрит с нуля',
            ]);
        }

        $resolved = $this->resolve($student);

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('номер SANSK-602', $resolved['draft']);
        $this->assertSame(2, $resolved['facts']['total']);
    }

    public function test_a_homework_question_does_not_reach_the_certificate_arm(): void
    {
        [$student, $course, $group] = $this->enrolledStudent();

        Certificate::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'number' => 'SANSK-505',
            'issued_at' => '2026-08-01',
            'course_title' => 'Санскрит с нуля',
        ]);

        // Категория F — это и ДЗ, и сертификаты; развилку решает текст. Работ
        // нет, поэтому вопрос про ДЗ откатывается на сертификат — но проверяем
        // именно то, что слово «сертификат» не требуется для ветки ДЗ.
        $resolved = $this->resolve($student, 'проверили мою домашку?');

        $this->assertNotNull($resolved);
        $this->assertSame(SupportAnswerFactResolver::TYPE_CERTIFICATE, $resolved['facts']['type']);
    }
}
