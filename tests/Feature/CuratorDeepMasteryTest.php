<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CuratorDeepMasteryQuiz;
use App\Models\CabinetMasteryAttempt;
use App\Models\User;
use App\Support\CabinetMastery;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H4313 follow-up — зачёт «Программа 20 дней» (audience curator_deep).
 */
class CuratorDeepMasteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_bank_has_twenty_unique_sound_questions(): void
    {
        $bank = CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR_DEEP);

        $this->assertCount(20, $bank['questions']);
        $this->assertSame(16, $bank['pass']);

        $ids = [];
        foreach ($bank['questions'] as $question) {
            $this->assertArrayHasKey('id', $question);
            $this->assertArrayHasKey('correct', $question);
            $this->assertArrayHasKey($question['correct'], $question['options']);
            $this->assertArrayHasKey('why', $question);
            $ids[] = $question['id'];
        }
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_deep_all_correct_answers_pass_with_full_score(): void
    {
        $answers = [];
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR_DEEP)['questions'] as $question) {
            $answers[$question['id']] = $question['correct'];
        }

        $graded = CabinetMastery::grade(CabinetMastery::AUDIENCE_CURATOR_DEEP, $answers);

        $this->assertTrue($graded['passed']);
        $this->assertSame(20, $graded['total']);
        $this->assertSame(20, $graded['score']);
    }

    public function test_deep_below_threshold_fails(): void
    {
        $answers = [];
        $i = 0;
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR_DEEP)['questions'] as $question) {
            // Ровно 15 верных из 20 — ниже порога 16.
            if ($i < 15) {
                $answers[$question['id']] = $question['correct'];
            } else {
                $wrong = array_keys($question['options'])[0];
                if ($wrong === $question['correct']) {
                    $wrong = array_keys($question['options'])[1];
                }
                $answers[$question['id']] = $wrong;
            }
            $i++;
        }

        $graded = CabinetMastery::grade(CabinetMastery::AUDIENCE_CURATOR_DEEP, $answers);

        $this->assertFalse($graded['passed']);
        $this->assertSame(15, $graded['score']);
    }

    public function test_deep_page_is_open_to_manager_and_admin_and_closed_to_teacher(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);
        $this->assertTrue(CuratorDeepMasteryQuiz::canAccess());
        $this->get(CuratorDeepMasteryQuiz::getUrl())->assertOk()->assertSee('Программа 20 дней', false);

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->assertFalse(CuratorDeepMasteryQuiz::canAccess());
        $this->get(CuratorDeepMasteryQuiz::getUrl())->assertForbidden();
    }

    public function test_deep_submit_stores_attempt_with_audience_and_user(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);

        $answers = [];
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR_DEEP)['questions'] as $question) {
            $answers[$question['id']] = $question['correct'];
        }

        Livewire::test(CuratorDeepMasteryQuiz::class)
            ->set('answers', $answers)
            ->call('submit')
            ->assertHasNoErrors();

        $row = CabinetMasteryAttempt::query()
            ->where('user_id', $manager->id)
            ->where('audience', CabinetMastery::AUDIENCE_CURATOR_DEEP)
            ->first();

        $this->assertNotNull($row);
        $this->assertTrue($row->passed);
        $this->assertSame(20, $row->score);
        $this->assertSame(20, $row->total);
    }

    public function test_deep_shuffle_is_a_permutation(): void
    {
        $userId = 7;
        $questions = CabinetMastery::questionsForDisplay(CabinetMastery::AUDIENCE_CURATOR_DEEP, $userId);
        $source = collect(CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR_DEEP)['questions']);

        foreach ($questions as $question) {
            $original = $source->firstWhere('id', $question['id']);
            ksort($original['options']);
            $copy = $question['options'];
            ksort($copy);
            $this->assertSame($original['options'], $copy);
        }
    }
}
