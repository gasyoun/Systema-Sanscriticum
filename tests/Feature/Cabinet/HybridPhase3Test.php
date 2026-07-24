<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * H1573 — Phase 3: grammar ladder, completion lighting, course vehi.
 */
class HybridPhase3Test extends TestCase
{
    use RefreshDatabase;

    private function enableHybrid(): void
    {
        config(['features.cabinet_hybrid' => true]);
    }

    /**
     * @return array{user: User, group: Group, letters: Course, grammar: Course, lessonsLetters: Collection, lessonsGrammar: Collection}
     */
    private function studentOnLadder(): array
    {
        config([
            'grammar_ladder.stations' => [
                [
                    'key' => 'letters',
                    'title' => 'Письмо',
                    'description' => 'Вход',
                    'pattern' => 'Деванагар-тест',
                ],
                [
                    'key' => 'grammar_i',
                    'title' => 'Грамматика I',
                    'description' => 'Следующая',
                    'pattern' => 'ГраммI-тест',
                ],
                [
                    'key' => 'texts',
                    'title' => 'Тексты',
                    'description' => 'Финиш',
                    'pattern' => 'Тексты-тест-нет',
                ],
            ],
        ]);

        $user = User::factory()->create();
        $group = Group::create(['name' => 'G-ladder-p3']);
        $user->groups()->attach($group);

        $letters = Course::factory()->create([
            'is_active' => true,
            'title' => 'Деванагар-тест письмо',
        ]);
        $letters->groups()->attach($group);
        $lessonsLetters = collect([
            Lesson::factory()->create(['course_id' => $letters->id, 'group_id' => $group->id, 'title' => 'L1', 'is_free' => true, 'sort_order' => 1]),
            Lesson::factory()->create(['course_id' => $letters->id, 'group_id' => $group->id, 'title' => 'L2', 'is_free' => true, 'sort_order' => 2]),
        ]);

        $grammar = Course::factory()->create([
            'is_active' => true,
            'title' => 'ГраммI-тест ступень',
        ]);
        $grammar->groups()->attach($group);
        $lessonsGrammar = collect([
            Lesson::factory()->create(['course_id' => $grammar->id, 'group_id' => $group->id, 'title' => 'G1', 'is_free' => true, 'sort_order' => 1]),
        ]);

        return compact('user', 'group', 'letters', 'grammar', 'lessonsLetters', 'lessonsGrammar');
    }

    /** @test */
    public function progress_404_when_flag_off(): void
    {
        config(['features.cabinet_hybrid' => false]);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('student.progress'))->assertNotFound();
    }

    /** @test */
    public function progress_renders_station_map(): void
    {
        $this->enableHybrid();
        ['user' => $user] = $this->studentOnLadder();

        $this->actingAs($user)
            ->get(route('student.progress'))
            ->assertOk()
            ->assertSee('data-grammar-ladder', false)
            ->assertSee('data-station="letters"', false)
            ->assertSee('Письмо', false)
            ->assertSee('Грамматика I', false);
    }

    /** @test */
    public function completing_station_lights_and_offers_next_without_timer(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'lessonsLetters' => $lessonsLetters] = $this->studentOnLadder();
        $user->completedLessons()->attach($lessonsLetters->pluck('id')->all());

        $ladder = app(GrammarLadder::class)->forUser($user);
        $this->assertContains('letters', $ladder['lit_keys']);
        $this->assertNotNull($ladder['ladder_offer']);
        $this->assertSame('ladder', $ladder['ladder_offer']->kind);
        $this->assertStringContainsString('подождёт', $ladder['ladder_offer']->body);
        $this->assertStringNotContainsString('дн', mb_strtolower($ladder['ladder_offer']->body));

        $this->actingAs($user)
            ->get(route('student.progress'))
            ->assertOk()
            ->assertSee('data-station-status="completed"', false)
            ->assertSee('data-offer-kind="ladder"', false)
            ->assertSee('Станция подождёт', false);
    }

    /** @test */
    public function recovery_suppresses_ladder_offer(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'letters' => $letters, 'lessonsLetters' => $lessonsLetters] = $this->studentOnLadder();
        $user->completedLessons()->attach($lessonsLetters->pluck('id')->all());

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $letters->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'failed',
            'is_conditional' => false,
        ]));

        $this->assertTrue(app(RecoveryStateResolver::class)->resolve($user)->suppressOffers());

        $this->actingAs($user)
            ->get(route('student.progress'))
            ->assertOk()
            ->assertDontSee('data-offer-kind="ladder"', false);
    }

    /** @test */
    public function path_telemetry_emitted_on_progress_view(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'lessonsLetters' => $lessonsLetters] = $this->studentOnLadder();
        $user->completedLessons()->attach($lessonsLetters->pluck('id')->all());

        $this->actingAs($user)->get(route('student.progress'))->assertOk();

        $this->assertTrue(
            ActivityEvent::where('event_type', ActivityEvent::PATH_STATION_VIEW)
                ->where('user_id', $user->id)
                ->exists()
        );
        $this->assertTrue(
            ActivityEvent::where('event_type', ActivityEvent::PATH_STATION_LIT_IMPRESSION)
                ->where('user_id', $user->id)
                ->exists()
        );
    }

    /** @test */
    public function course_home_shows_landmarks_from_blocks(): void
    {
        $this->enableHybrid();
        ['user' => $user, 'letters' => $course, 'group' => $group] = $this->studentOnLadder();

        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'title' => 'Блок 1',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addWeek(),
            'is_current' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('student.course', $course->slug))
            ->assertOk()
            ->assertSee('data-course-landmarks', false)
            ->assertSee('Место курса на пути', false)
            ->assertSee('Блок 1 — начало', false)
            ->assertSee('не сроки оплаты', false);
    }
}
