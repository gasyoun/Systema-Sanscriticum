<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CabinetMasteryQuiz;
use App\Models\CabinetMasteryAttempt;
use App\Models\User;
use App\Support\CabinetMastery;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CabinetMasteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_curator_bank_every_correct_key_exists_and_ids_are_unique(): void
    {
        $this->assertBankSound(CabinetMastery::AUDIENCE_CURATOR);
        $this->assertBankSound(CabinetMastery::AUDIENCE_STUDENT);
        $this->assertBankSound(CabinetMastery::AUDIENCE_TEACHER);
        $this->assertBankSound(CabinetMastery::AUDIENCE_ACCOUNTANT);
    }

    public function test_all_correct_answers_pass(): void
    {
        foreach ([
            CabinetMastery::AUDIENCE_CURATOR,
            CabinetMastery::AUDIENCE_STUDENT,
            CabinetMastery::AUDIENCE_TEACHER,
            CabinetMastery::AUDIENCE_ACCOUNTANT,
        ] as $audience) {
            $bank = CabinetMastery::bank($audience);
            $answers = [];
            foreach ($bank['questions'] as $question) {
                $answers[$question['id']] = $question['correct'];
            }
            $graded = CabinetMastery::grade($audience, $answers);
            $this->assertTrue($graded['passed'], $audience);
            $this->assertSame($graded['total'], $graded['score'], $audience);
        }
    }

    public function test_empty_answers_fail(): void
    {
        $graded = CabinetMastery::grade(CabinetMastery::AUDIENCE_CURATOR, []);
        $this->assertFalse($graded['passed']);
        $this->assertSame(0, $graded['score']);
    }

    public function test_shuffle_is_a_permutation_and_not_always_first_correct(): void
    {
        $userId = 42;
        $firstIsCorrect = 0;
        $questions = CabinetMastery::questionsForDisplay(CabinetMastery::AUDIENCE_CURATOR, $userId);

        foreach ($questions as $question) {
            $keys = array_keys($question['options']);
            $original = CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR)['questions'];
            $source = collect($original)->firstWhere('id', $question['id']);
            $this->assertSameCanonicalOptions($source['options'], $question['options']);
            if (($keys[0] ?? null) === $question['correct']) {
                $firstIsCorrect++;
            }
        }

        $this->assertLessThan(count($questions), $firstIsCorrect, 'все правильные ответы стоят первыми — тест ничего не меряет');
    }

    public function test_curator_page_is_open_to_a_manager_and_closed_to_a_teacher(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);
        $this->assertTrue(CabinetMasteryQuiz::canAccess());
        $this->get(CabinetMasteryQuiz::getUrl())->assertOk()->assertSee('Проверка овладения кабинетом', false);

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->assertFalse(CabinetMasteryQuiz::canAccess());
        $this->get(CabinetMasteryQuiz::getUrl())->assertForbidden();
    }

    public function test_curator_page_is_closed_to_an_accountant(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);
        $this->assertFalse(CabinetMasteryQuiz::canAccess());
        $this->get(CabinetMasteryQuiz::getUrl())->assertForbidden();
    }

    public function test_curator_submit_stores_a_passing_attempt(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);

        $answers = [];
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_CURATOR)['questions'] as $question) {
            $answers[$question['id']] = $question['correct'];
        }

        Livewire::test(CabinetMasteryQuiz::class)
            ->set('answers', $answers)
            ->call('submit')
            ->assertHasNoErrors();

        $row = CabinetMasteryAttempt::query()->where('user_id', $manager->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->passed);
        $this->assertSame(CabinetMastery::AUDIENCE_CURATOR, $row->audience);
        $this->assertSame(10, $row->score);
    }

    public function test_student_page_requires_login_and_records_an_attempt(): void
    {
        $this->get(route('student.cabinet-mastery'))->assertRedirect();

        $student = User::factory()->create();
        $this->actingAs($student)
            ->get(route('student.cabinet-mastery'))
            ->assertOk()
            ->assertSee('Проверка: что умею в кабинете', false);

        $answers = [];
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_STUDENT)['questions'] as $question) {
            $answers[$question['id']] = $question['correct'];
        }

        $this->actingAs($student)
            ->post(route('student.cabinet-mastery.submit'), ['answers' => $answers])
            ->assertOk()
            ->assertSee('зачёт', false);

        $row = CabinetMasteryAttempt::query()->where('user_id', $student->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->passed);
        $this->assertSame(CabinetMastery::AUDIENCE_STUDENT, $row->audience);
    }

    public function test_dashboard_links_to_the_student_quiz(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee(route('student.cabinet-mastery'), false);
    }

    /**
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     */
    private function assertSameCanonicalOptions(array $a, array $b): void
    {
        ksort($a);
        $copy = $b;
        ksort($copy);
        $this->assertSame($a, $copy);
    }

    private function assertBankSound(string $audience): void
    {
        $bank = CabinetMastery::bank($audience);
        $ids = [];
        foreach ($bank['questions'] as $question) {
            $this->assertArrayHasKey('id', $question);
            $this->assertArrayHasKey('correct', $question);
            $this->assertArrayHasKey($question['correct'], $question['options']);
            $ids[] = $question['id'];
        }
        $this->assertSame($ids, array_unique($ids));
        $this->assertGreaterThan(0, $bank['pass']);
        $this->assertLessThanOrEqual(count($bank['questions']), $bank['pass']);
    }
}
