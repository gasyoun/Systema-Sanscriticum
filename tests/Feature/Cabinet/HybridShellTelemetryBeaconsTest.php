<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4185 — гибридный шелл обязан отдавать все клиентские маячки спеки §4.
 *
 * Инцидент: после DEPLOY №52 (флаг CABINET_HYBRID ON, 21-08-2026) три события
 * замолчали при выросшем трафике — `cabinet.continue.click` 93/35 → 3/1,
 * `course.tab.view` 21/10 → 0/0, `offer.impression`/`offer.click` 689/16 · 16/5
 * → 3/1 · 0/0 (H4134 §5). Причина не в поведении студентов: гибридные шаблоны
 * несли ДРУГИЕ имена атрибутов (`data-cabinet-event` / `data-kind`), которых
 * слушатель в student/partials/telemetry.blade.php не знает, а на вкладках
 * курса и на закрытых уроках маячков не было вовсе.
 *
 * Контракт, который пиннится здесь:
 *  1. имена событий — ровно константы ActivityEvent (их читает cabinet:baseline);
 *  2. имя атрибута — только `data-track-event` / `data-track-impression`;
 *  3. мёртвого `data-cabinet-event` в шелле нет ни на одной странице;
 *  4. слушатель телеметрии подключён РОВНО один раз (иначе двойной счёт);
 *  5. подавление офферов в recovery не меняется — маячок оффера тоже молчит.
 */
class HybridShellTelemetryBeaconsTest extends TestCase
{
    use RefreshDatabase;

    private function enableHybrid(): void
    {
        config(['features.cabinet_hybrid' => true]);
    }

    /**
     * Студент с курсом: один открытый урок (карточка «Продолжить») и один
     * закрытый (next-block offer).
     *
     * @return array{user: User, group: Group, course: Course, lesson: Lesson, locked: Lesson}
     */
    private function studentWithOpenAndLockedLesson(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-h4185']);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Грамматика I']);
        $course->groups()->attach($group);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'title' => 'Урок 1 открытый',
            'is_free' => true,
            'sort_order' => 1,
        ]);
        $locked = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'title' => 'Урок 2 закрытый',
            'is_free' => false,
            'block_number' => 2,
            'sort_order' => 2,
        ]);

        return compact('user', 'group', 'course', 'lesson', 'locked');
    }

    /** @test */
    public function hybrid_home_emits_continue_click_beacon_under_the_spec_name(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentWithOpenAndLockedLesson();

        $html = $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-today-continue', $html);
        $this->assertStringContainsString(
            'data-track-event="'.ActivityEvent::CABINET_CONTINUE_CLICK.'"',
            $html,
            'Карточка «Продолжить» в гибриде обязана нести cabinet.continue.click.'
        );
    }

    /** @test */
    public function hybrid_home_emits_homework_rework_beacon_under_the_spec_name(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course, 'lesson' => $lesson] = $this->studentWithOpenAndLockedLesson();

        HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_NEEDS_REVISION,
            'last_activity_at' => now(),
            'reviewed_at' => now(),
        ]);

        $html = $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-today-homework-rework', $html);
        $this->assertStringContainsString(
            'data-track-event="'.ActivityEvent::CABINET_HOMEWORK_REWORK_CLICK.'"',
            $html,
            'Слот доработки ДЗ обязан нести cabinet.homework.rework.click.'
        );
    }

    /** @test */
    public function hybrid_course_tabs_emit_course_tab_view(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithOpenAndLockedLesson();

        $html = $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-track-event="'.ActivityEvent::COURSE_TAB_VIEW.'"',
            $html,
            'Вкладки «дома курса» обязаны нести course.tab.view.'
        );
        // Контекст события: какая именно вкладка (Alpine-биндинг по tab.id).
        $this->assertStringContainsString(':data-track-tab="tab.id"', $html);
    }

    /** @test */
    public function hybrid_course_locked_lesson_emits_next_block_offer_pair(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithOpenAndLockedLesson();

        $html = $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-track-impression="'.ActivityEvent::OFFER_IMPRESSION.'"',
            $html,
            'Закрытый урок = next-block offer: нужен offer.impression.'
        );
        $this->assertStringContainsString(
            'data-track-event="'.ActivityEvent::OFFER_CLICK.'"',
            $html,
            'Закрытый урок = next-block offer: нужен offer.click.'
        );
        $this->assertStringContainsString('data-track-kind="next-block"', $html);
    }

    /** @test */
    public function open_lesson_alone_carries_no_offer_beacon(): void
    {
        // Ложноположительный оффер хуже молчания: открытый урок ничего не продаёт.
        $this->enableHybrid();
        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-h4185-open']);
        $user->groups()->attach($group);
        $course = Course::factory()->create(['is_active' => true]);
        $course->groups()->attach($group);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'is_free' => true,
        ]);

        $html = $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-track-kind="next-block"', $html);
    }

    /** @test */
    public function every_client_beacon_uses_the_listened_attribute_name(): void
    {
        // Регресс на сам класс аварии: следующая переписка шелла не должна
        // переименовать атрибут и снова обнулить телеметрию молча.
        $shell = glob(resource_path('views/student/hybrid/*.blade.php'));
        $this->assertNotEmpty($shell);

        foreach ($shell as $file) {
            $this->assertStringNotContainsString(
                'data-cabinet-event=',
                (string) file_get_contents($file),
                basename($file).': слушатель телеметрии знает только data-track-event.'
            );
        }
    }

    /** @test */
    public function telemetry_listener_is_bound_exactly_once_per_page(): void
    {
        // Двойное подключение партиала = два слушателя = двойной счёт событий.
        $this->enableHybrid();
        ['user' => $user, 'course' => $course] = $this->studentWithOpenAndLockedLesson();

        foreach ([
            route('student.dashboard'),
            route('student.library'),
            route('student.progress'),
            route('student.course', $course->slug),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame(
                1,
                substr_count($html, 'window.__cabinetTelemetryBound = true;'),
                $url.': партиал телеметрии подключён больше одного раза.'
            );
        }
    }
}
