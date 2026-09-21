<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseInterestRequest;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H5233 — публичная канва групп семейства Кочергиной + клик по группе →
 * заявка на вступление/переезд.
 *
 * 1) /raspisanie/kochergina рендерит карточку на каждую живую группу
 *    (прошлое + будущее занятие) с канвой «Урок N из 40» и преподавателем;
 *    скрытые/не живущие группы не попадают.
 * 2) Клик-URL префиллится по состоянию юзера: гость → intent=join,
 *    студент другой группы того же семейства → intent=transfer; форма
 *    /interest открывается с этим префиллом.
 * 3) POST /interest с intent=transfer сохраняется и виден админу с исходной
 *    группой (префикс в comment — та же строка, что уходит кураторам в TG).
 */
class PublicScheduleKocherginaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        RateLimiter::clear('course-interest:127.0.0.1');
        config([
            'features.schedule_full_post' => true,
            'features.course_interest_form' => true,
            'services.telegram.curators_chat_id' => '12345',
        ]);
    }

    /**
     * Курс семейства Кочергиной + группа «Кочергина гр. {suffix}».
     * $past + $future управляют «живостью» (обе true по умолчанию).
     *
     * @param  list<string>  $lessonTitles  заголовки записей уроков (канва)
     */
    private function kocherginaCourse(string $title, string $slug, string $groupSuffix, array $lessonTitles = [], ?Teacher $teacher = null, bool $visible = true, bool $active = true, bool $past = true, bool $future = true): array
    {
        $course = Course::factory()->create([
            'title' => $title,
            'slug' => $slug,
            'is_active' => $active,
            'is_visible' => $visible,
            'teacher_id' => $teacher?->id,
        ]);
        $group = Group::factory()->create(['name' => 'Кочергина гр. '.$groupSuffix]);
        $course->groups()->attach($group->id);

        if ($past) {
            Schedule::create([
                'title' => 'прошедшее', 'group_id' => $group->id, 'course_id' => $course->id,
                'start' => now()->subDays(14)->format('Y-m-d H:i:s'),
            ]);
        }
        if ($future) {
            Schedule::create([
                'title' => 'предстоящее', 'group_id' => $group->id, 'course_id' => $course->id,
                'start' => now()->addDays(7)->format('Y-m-d H:i:s'),
            ]);
        }

        foreach ($lessonTitles as $i => $lessonTitle) {
            Lesson::create([
                'course_id' => $course->id,
                'group_id' => $group->id,
                'title' => $lessonTitle,
                'lesson_date' => now()->subDays(count($lessonTitles) - $i),
                'is_published' => true,
                'is_free' => false,
            ]);
        }

        return [$course, $group];
    }

    /** Тест 1: страница рендерит группы + канву + преподавателя. */
    public function test_groups_page_renders_live_groups_canvas_and_teachers(): void
    {
        $teacher = Teacher::create(['name' => 'Мария Кочергова', 'email' => 'm@example.test']);
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 53',
            slug: 'koch53',
            groupSuffix: '53',
            lessonTitles: ['1-е занятие: Кочергина 3 (читка)', '2-е занятие: Кочергина 7 (читка)'],
            teacher: $teacher,
        );
        // Живая группа без распознанных заголовков: «Урок —», не выдумывать.
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 55',
            slug: 'koch55',
            groupSuffix: '55',
        );
        // Скрытый курс (is_visible=0, гр.60): живёт, но наружу не попадает.
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 60',
            slug: 'koch60',
            groupSuffix: '60',
            visible: false,
        );
        // Закончилась: только прошлое, будущего нет.
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 36',
            slug: 'koch36',
            groupSuffix: '36',
            future: false,
        );

        $response = $this->get('/raspisanie/kochergina');

        $response
            ->assertOk()
            ->assertSee('Кочергина гр. 53', false)
            ->assertSee('Кочергина гр. 55', false)
            // Канва: курсор по «читка» = 7, total 40.
            ->assertSee('Урок 7 из 40', false)
            ->assertSee('Урок —', false)
            ->assertSee('Мария Кочергова', false)
            ->assertSee('/online/prepodavatel/Мария-Кочергова', false)
            ->assertDontSee('Кочергина гр. 60', false)
            ->assertDontSee('Кочергина гр. 36', false);

        // Ссылка с /raspisanie на эту страницу (заметная, в блоке набора).
        $this->get('/raspisanie')
            ->assertOk()
            ->assertSee('/raspisanie/kochergina', false);
    }

    /** Тест 2: клик-URL и префилл интента по состоянию юзера. */
    public function test_intent_prefill_guest_join_and_other_group_student_transfer(): void
    {
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 53',
            slug: 'koch53',
            groupSuffix: '53',
            lessonTitles: ['1-е занятие: Кочергина 3 (читка)'],
        );
        $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 55',
            slug: 'koch55',
            groupSuffix: '55',
        );

        // Гость: обе карточки ведут с intent=join.
        $this->get('/raspisanie/kochergina')
            ->assertOk()
            ->assertSee('/interest/koch53?intent=join', false)
            ->assertSee('/interest/koch55?intent=join', false);

        // Форма открывается с префиллом join (radio checked).
        $this->get('/interest/koch53?intent=join')
            ->assertOk()
            ->assertSeeInOrder(['value="join"', 'checked'], false)
            // ?intent=transfer у гостя тоже валиден (форма показывает выбор).
            ->assertSee('Перевестись из другой группы', false);

        // Неизвестный intent — тихий дефолт join, не 4xx.
        $this->get('/interest/koch53?intent=hack')
            ->assertOk()
            ->assertSeeInOrder(['value="join"', 'checked'], false);

        // Студент 53-й: клик по 55-й → transfer; по своей 53-й → join.
        $user = User::factory()->create();
        $sourceGroup = Group::query()->where('name', 'Кочергина гр. 53')->firstOrFail();
        $user->groups()->attach($sourceGroup->id);

        $this->actingAs($user)
            ->get('/raspisanie/kochergina')
            ->assertOk()
            ->assertSee('/interest/koch55?intent=transfer', false)
            ->assertSee('/interest/koch53?intent=join', false);

        // Форма открывается с префиллом transfer.
        $this->actingAs($user)
            ->get('/interest/koch55?intent=transfer')
            ->assertOk()
            ->assertSeeInOrder(['value="transfer"', 'checked'], false);
    }

    /** Тест 3: POST intent=transfer сохраняется и виден админу с исходной группой. */
    public function test_transfer_store_saves_request_with_source_group_for_admin(): void
    {
        [$target] = $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 55',
            slug: 'koch55',
            groupSuffix: '55',
        );
        [, $sourceGroup] = $this->kocherginaCourse(
            title: 'Грамматика по Кочергиной 53',
            slug: 'koch53',
            groupSuffix: '53',
        );

        $user = User::factory()->create();
        $user->groups()->attach($sourceGroup->id);

        $response = $this->actingAs($user)->post('/interest/koch55', [
            'intent' => CourseInterestRequest::INTENT_TRANSFER,
            'name' => 'Переехавшая',
            'email' => 'mover@example.com',
            'comment' => 'Хочу в вечернюю группу.',
            'website' => '',
            'ff_ts' => encrypt((string) (now()->timestamp - 5)),
        ]);

        $response->assertRedirect(route('course-interest.show', ['course' => 'koch55']))
            ->assertSessionHas('course_interest_status');

        $request = CourseInterestRequest::query()->sole();
        $this->assertSame($target->id, $request->course_id);
        $this->assertSame(CourseInterestRequest::INTENT_TRANSFER, $request->intent);
        // Исходная группа — префиксом в comment: админская таблица Filament
        // показывает comment-колонку, TG-уведомление кураторам шлёт comment.
        $this->assertStringContainsString('Переезд из группы «', (string) $request->comment);
        $this->assertStringContainsString((string) $sourceGroup->name, (string) $request->comment);
        $this->assertStringContainsString('Хочу в вечернюю группу.', (string) $request->comment);
        $this->assertSame(CourseInterestRequest::STATUS_NEW, $request->status);
    }
}