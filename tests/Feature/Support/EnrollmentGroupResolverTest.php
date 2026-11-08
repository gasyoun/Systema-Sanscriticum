<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Services\Support\EnrollmentGroupResolver;
use App\Support\EnrollmentGroupLink;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Запись в группу по переписке (20-10-2026): образец — диалог из лички,
 * где группа («суббота 11») названа за три сообщения до «запишите меня».
 */
class EnrollmentGroupResolverTest extends TestCase
{
    use RefreshDatabase;

    private Group $saturday;

    private Group $tuesday;

    private Group $gr63;

    private Group $hindiSaturday;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 18:10:00');

        $intake = $this->course('Грамматика санскрита с нуля — набор 2026', [1, 2]);
        $this->saturday = $this->group($intake, 'Грамматика 2026 — суббота 11:00', 'forming');
        $this->tuesday = $this->group($intake, 'Грамматика 2026 — вторник 08:00', 'forming');

        $kochergina = $this->course('Грамматика по Кочергиной гр.63', [1, 2, 3]);
        $this->gr63 = $this->group($kochergina, 'Грамматика по Кочергиной гр.63', 'active');
        CourseBlock::create(['course_id' => $kochergina->id, 'number' => 3, 'title' => 'Блок 3', 'is_current' => true, 'is_active' => true]);
        Schedule::query()->create(['title' => 'гр.63', 'start' => '2026-10-27 08:00:00', 'group_id' => $this->gr63->id]);

        $hindi = $this->course('Грамматика хинди гр. 2, суббота 11:00 (2026)', [1]);
        $this->hindiSaturday = $this->group($hindi, 'Грамматика хинди гр. 2, суббота 11:00 (2026)', 'forming');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param list<int> $blocks */
    private function course(string $title, array $blocks): Course
    {
        $course = Course::factory()->create(['title' => $title, 'is_active' => true]);
        foreach ($blocks as $n) {
            Tariff::factory()->for($course)->block($n)->create(['price' => 8000]);
        }

        return $course;
    }

    private function group(Course $course, string $name, string $status): Group
    {
        $group = Group::create(['name' => $name, 'status' => $status]);
        $course->groups()->attach($group->id);

        return $group;
    }

    private function resolver(): EnrollmentGroupResolver
    {
        return app(EnrollmentGroupResolver::class);
    }

    public function test_dialog_from_prod_resolves_saturday_group_named_earlier(): void
    {
        // Новые первыми — как их отдаёт автоответ.
        $result = $this->resolver()->resolve([
            'Тогда готова оплатить и ждать уроки',
            'Ух ты! Хорошо, так даже интереснее)',
            "Оплата 6000 месяц?\nИ старт будет, как наберется?",
            'Если я правильно поняла)',
            'Можно меня тогда на нее записать?',
            'Ааа, поняла',
            'Суббота 11 - имеете в виду, в 11:00? Возможно появится группа с нуля на это время?',
            "Эх, в 8 утра совсем не удобно :( Не планируется ли групп в другое время?\nВечернее или в выходные?",
            "Здравствуйте! Подскажите, а сейчас есть ли группа по санскриту для начинающих?\n\nЯ ранее 3 блока прошла и вроде начала 4.",
        ]);

        $this->assertNotNull($result);
        $this->assertSame($this->saturday->id, $result['group']?->id, 'хинди-суббота 11:00 отпадает: хинди не назван');
        $this->assertSame(1, $result['block']);
        $this->assertSame(1, $result['tariff']->block_number);
        $this->assertStringContainsString('gsig=', (string) $result['url']);
    }

    public function test_group_number_wins_and_active_group_pays_current_block(): void
    {
        $result = $this->resolver()->resolve(['хочу оплатить, я в гр.63']);

        $this->assertSame($this->gr63->id, $result['group']?->id);
        $this->assertSame(3, $result['block']);
    }

    public function test_negated_time_is_not_a_request(): void
    {
        $this->assertNull($this->resolver()->resolve(['вторник 8:00 мне совсем не подходит']));
    }

    public function test_same_slot_two_sanskrit_groups_is_ambiguous_without_beginner_cue(): void
    {
        $result = $this->resolver()->resolve(['запишите на вторник 8:00']);

        $this->assertNull($result['group']);
        $this->assertEqualsCanonicalizing(
            [$this->tuesday->id, $this->gr63->id],
            $result['candidates']->pluck('id')->all(),
        );
    }

    public function test_beginner_cue_picks_forming_group(): void
    {
        $result = $this->resolver()->resolve(['хочу с нуля, запишите на вторник 8:00']);

        $this->assertSame($this->tuesday->id, $result['group']?->id);
    }

    public function test_hindi_named_picks_hindi_group(): void
    {
        $result = $this->resolver()->resolve(['запишите на хинди в субботу 11']);

        $this->assertSame($this->hindiSaturday->id, $result['group']?->id);
    }

    public function test_no_group_cue_returns_null(): void
    {
        $this->assertNull($this->resolver()->resolve(['готова оплатить']));
    }

    public function test_signed_link_rejects_tampered_or_foreign_group(): void
    {
        $tariff = Tariff::query()->where('course_id', $this->saturday->courses()->value('courses.id'))->firstOrFail();

        $this->assertSame(
            $this->saturday->id,
            EnrollmentGroupLink::resolve($tariff, $this->saturday->id, EnrollmentGroupLink::signature($tariff->id, $this->saturday->id))?->id,
        );
        $this->assertNull(EnrollmentGroupLink::resolve($tariff, $this->tuesday->id, EnrollmentGroupLink::signature($tariff->id, $this->saturday->id)));
        // Подпись верна, но группа чужого курса.
        $this->assertNull(EnrollmentGroupLink::resolve($tariff, $this->gr63->id, EnrollmentGroupLink::signature($tariff->id, $this->gr63->id)));
    }
}
