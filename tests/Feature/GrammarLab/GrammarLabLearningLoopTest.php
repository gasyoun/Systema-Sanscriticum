<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Models\GrammarAttempt;
use App\Models\GrammarExercise;
use App\Models\GrammarLabEntitlement;
use App\Models\GrammarMastery;
use App\Models\SrsReviewState;
use App\Services\GrammarLab\ExercisePublisher;
use App\Services\GrammarLab\ExerciseRollback;
use App\Services\GrammarLab\GrammarMasteryProjector;
use App\Services\GrammarLab\GrammarRecommender;
use App\Services\Srs\ReviewService;
use App\Services\Srs\Rating;
use App\Support\GrammarLabSrsDeck;
use DateTimeImmutable;

class GrammarLabLearningLoopTest extends GrammarLabTestCase
{
    public function test_import_publishes_deterministic_and_blocks_interpretive(): void
    {
        $report = $this->importFixture();
        $this->assertSame(2, $report['exercises']);
        $this->assertSame(1, $report['published_exercises']);
        $this->assertGreaterThanOrEqual(1, $report['sample_size']);

        $published = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();
        $this->assertSame(GrammarExercise::STATUS_PUBLISHED, $published->status);
        $this->assertTrue($published->in_review_sample);

        $interp = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:type-vs-class-essay')->first();
        $this->assertSame(GrammarExercise::STATUS_APPROVAL_REQUIRED, $interp->status);
        $this->assertContains('interpretive_needs_approval', $interp->validation_errors ?? []);
        $this->assertFileExists($report['sample_path']);
        $manifest = json_decode((string) file_get_contents($report['sample_path']), true);
        $this->assertSame(0.2, $manifest['sample_fraction']);
        $this->assertContains('ex:grammar-lab:ablaut-grades', $manifest['exercise_ids']);
    }

    public function test_kill_switch_blocks_new_auto_publish(): void
    {
        config(['grammar_lab.publication.auto_publish' => false]);
        $this->importFixture();
        $row = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();
        $this->assertSame(GrammarExercise::STATUS_IMPORTED, $row->status);
        $this->assertContains('auto_publish_killed', $row->validation_errors ?? []);
    }

    public function test_rollback_restores_prior_version_and_keeps_attempts(): void
    {
        $this->importFixture();
        $publisher = app(ExercisePublisher::class);
        $first = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();

        $user = $this->entitledStudent();
        app(GrammarMasteryProjector::class)->record($user, $first, 'guṇa');
        $this->assertSame(1, GrammarAttempt::query()->count());

        $updated = $first->payload;
        $updated['prompt_ru'] = 'Какая ступень у kartum? (версия 2)';
        $updated['answer'] = 'vṛddhi';
        $updated['answer_normalized'] = 'vṛddhi';
        $updated['choices'] = ['нулевая', 'guṇa', 'vṛddhi'];
        $updated['distractors'] = ['нулевая', 'guṇa'];
        $second = $publisher->ingest($updated);
        $this->assertSame(2, (int) $second->version_n);
        $this->assertSame('vṛddhi', $second->payload['answer']);

        $rolled = app(ExerciseRollback::class)->rollback($second, 'fixture_reject');
        $this->assertSame(GrammarExercise::STATUS_PUBLISHED, $rolled->status);
        $this->assertSame('guṇa', $rolled->payload['answer']);
        $this->assertSame(1, GrammarAttempt::query()->count());
        $this->assertSame(1, GrammarAttempt::query()->where('content_hash', $first->content_hash)->count());
    }

    public function test_mastery_does_not_write_fsrs_state(): void
    {
        $this->importFixture();
        $exercise = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();
        $user = $this->entitledStudent();
        $projector = app(GrammarMasteryProjector::class);
        $projector->record($user, $exercise, 'нулевая');
        $this->assertSame(GrammarMastery::LEARNING, $projector->for($user, $exercise->topic_id)->state);
        $projector->record($user, $exercise, 'guṇa');
        $this->assertSame(GrammarMastery::LEARNING, $projector->for($user, $exercise->topic_id)->state);
        $projector->record($user, $exercise, 'guṇa');
        $this->assertSame(GrammarMastery::MASTERED, $projector->for($user, $exercise->topic_id)->state);
        $this->assertSame(0, SrsReviewState::query()->count());
    }

    public function test_srs_projection_reuses_review_service(): void
    {
        $this->importFixture();
        $exercise = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();
        $user = $this->entitledStudent();
        $card = GrammarLabSrsDeck::addExercise($user, $exercise);
        $this->assertSame(0, SrsReviewState::query()->count());

        $review = app(ReviewService::class);
        $now = new DateTimeImmutable('2026-08-13T12:00:00+00:00');
        $review->grade($user, $card, Rating::Good, null, $now);
        $this->assertSame(1, SrsReviewState::query()->where('user_id', $user->id)->where('card_id', $card->id)->count());
        $again = GrammarLabSrsDeck::addExercise($user, $exercise);
        $this->assertSame($card->id, $again->id);
        $this->assertSame(1, SrsReviewState::query()->count());
    }

    public function test_recommender_is_deterministic(): void
    {
        $this->importFixture();
        $user = $this->entitledStudent();
        $rec = app(GrammarRecommender::class);
        $a = $rec->next($user);
        $b = $rec->next($user);
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertSame('next_band', $a['rule']);

        $exercise = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:ablaut-grades')->first();
        app(GrammarMasteryProjector::class)->record($user, $exercise, 'нулевая');
        $weak = $rec->next($user);
        $this->assertSame('weakness', $weak['rule']);
        $this->assertSame($weak, $rec->next($user));
        $this->assertStringContainsString('Слабое место', $weak['reason']);
    }

    public function test_practice_routes_use_same_entitlement(): void
    {
        $this->enableLab();
        $this->importFixture();

        $this->actingAs($this->student())
            ->get('/dvaram/grammar-lab/t/ablaut-grades/practice')
            ->assertForbidden()
            ->assertDontSee('Какая ступень у kartum?', false);

        $user = $this->entitledStudent();
        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/t/ablaut-grades/practice')
            ->assertOk()
            ->assertSee('Какая ступень у kartum?');

        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/t/ablaut-grades/practice', ['answer' => 'guṇa'])
            ->assertOk()
            ->assertSee('Верно')
            ->assertSee('Дальше');

        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/t/ablaut-grades/srs')
            ->assertRedirect();
        $this->assertSame(1, GrammarLabSrsDeck::deckFor($user)->cards()->count());
    }

    public function test_interpretive_cannot_be_forced_visible_without_approval(): void
    {
        $this->importFixture();
        $row = GrammarExercise::query()->where('exercise_id', 'ex:grammar-lab:type-vs-class-essay')->first();
        $row->status = GrammarExercise::STATUS_PUBLISHED;
        $row->save();
        $gated = app(ExercisePublisher::class)->applyGates($row->fresh());
        $this->assertSame(GrammarExercise::STATUS_APPROVAL_REQUIRED, $gated->status);

        $this->enableLab();
        $user = $this->entitledStudent();
        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/t/ablaut-grades')
            ->assertOk()
            ->assertSee('Практика');
        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/t/ablaut-grades/practice')
            ->assertOk()
            ->assertSee('Какая ступень у kartum?')
            ->assertDontSee('Почему тип I нельзя');
    }

    private function entitledStudent()
    {
        $user = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
        ]);

        return $user;
    }
}
