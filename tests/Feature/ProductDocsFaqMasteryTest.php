<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AccountantMasteryQuiz;
use App\Filament\Pages\DocumentationCatalog;
use App\Filament\Pages\TeacherMasteryQuiz;
use App\Models\CabinetMasteryAttempt;
use App\Models\ProductDoc;
use App\Models\User;
use App\Support\CabinetMastery;
use App\Support\Roles;
use Database\Seeders\ProductDocSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3244 — волна 2: Часть IV не тоньше baseline, банки teacher/accountant, каталог «Проверка».
 */
class ProductDocsFaqMasteryTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_iv_counts_meet_baseline(): void
    {
        $this->assertGreaterThanOrEqual(7, $this->partIvQuestionCount('docs/STUDENT_CABINET_GUIDE_RU.md'));
        $this->assertGreaterThanOrEqual(9, $this->partIvQuestionCount('docs/CURATOR_ADMIN_GUIDE_RU.md'));
        $this->assertGreaterThanOrEqual(7, $this->partIvQuestionCount('docs/ACCOUNTANT_CABINET_GUIDE_RU.md'));
        $this->assertGreaterThanOrEqual(9, $this->partIvQuestionCount('docs/TEACHER_CABINET_GUIDE_RU.md'));
    }

    public function test_part_iv_has_no_personal_names_or_live_ruble_amounts(): void
    {
        foreach ([
            'docs/STUDENT_CABINET_GUIDE_RU.md',
            'docs/CURATOR_ADMIN_GUIDE_RU.md',
            'docs/ACCOUNTANT_CABINET_GUIDE_RU.md',
            'docs/TEACHER_CABINET_GUIDE_RU.md',
        ] as $relative) {
            $faq = $this->partIvBody($relative);
            $this->assertDoesNotMatchRegularExpression(
                '/\d{2,}[\s\x{00a0}]*(₽|руб\.?)/u',
                $faq,
                $relative.' Часть IV: живая сумма',
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\b(Гасунс|Костина|Ворошилов|Леонов|Лейтан)\b/u',
                $faq,
                $relative.' Часть IV: фамилия из прод-контура',
            );
        }
    }

    public function test_teacher_and_accountant_banks_are_sound_and_wide_enough(): void
    {
        $teacher = CabinetMastery::bank(CabinetMastery::AUDIENCE_TEACHER);
        $accountant = CabinetMastery::bank(CabinetMastery::AUDIENCE_ACCOUNTANT);
        $this->assertGreaterThanOrEqual(8, count($teacher['questions']));
        $this->assertGreaterThanOrEqual(6, count($accountant['questions']));

        foreach ([CabinetMastery::AUDIENCE_TEACHER, CabinetMastery::AUDIENCE_ACCOUNTANT] as $audience) {
            $bank = CabinetMastery::bank($audience);
            $ids = [];
            foreach ($bank['questions'] as $question) {
                $this->assertArrayHasKey($question['correct'], $question['options']);
                $this->assertGreaterThanOrEqual(3, count($question['options']));
                $ids[] = $question['id'];
            }
            $this->assertSame($ids, array_values(array_unique($ids)), $audience);
        }
    }

    public function test_teacher_quiz_opens_for_admin_and_is_forbidden_to_plain_accountant(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);
        $this->assertTrue(TeacherMasteryQuiz::canAccess());
        $this->get(TeacherMasteryQuiz::getUrl())
            ->assertOk()
            ->assertSee('Проверка: руководство преподавателя', false);

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);
        $this->assertFalse(TeacherMasteryQuiz::canAccess());
        $this->get(TeacherMasteryQuiz::getUrl())->assertForbidden();
    }

    public function test_accountant_quiz_opens_for_finance_and_is_forbidden_to_plain_teacher(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);
        $this->actingAs($accountant);
        $this->assertTrue(AccountantMasteryQuiz::canAccess());
        $this->get(AccountantMasteryQuiz::getUrl())
            ->assertOk()
            ->assertSee('Проверка: как работать бухгалтеру', false);

        $teacher = User::factory()->create(['role' => Roles::TEACHER]);
        $this->actingAs($teacher);
        $this->assertFalse(AccountantMasteryQuiz::canAccess());
        $this->get(AccountantMasteryQuiz::getUrl())->assertForbidden();
    }

    public function test_teacher_submit_stores_a_passing_attempt(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $answers = [];
        foreach (CabinetMastery::bank(CabinetMastery::AUDIENCE_TEACHER)['questions'] as $question) {
            $answers[$question['id']] = $question['correct'];
        }

        Livewire::test(TeacherMasteryQuiz::class)
            ->set('answers', $answers)
            ->call('submit')
            ->assertHasNoErrors();

        $row = CabinetMasteryAttempt::query()->where('user_id', $admin->id)->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->passed);
        $this->assertSame(CabinetMastery::AUDIENCE_TEACHER, $row->audience);
    }

    public function test_catalog_links_all_four_quizzes(): void
    {
        $this->seed(ProductDocSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $student = ProductDoc::query()->where('slug', 'student')->firstOrFail();
        $curator = ProductDoc::query()->where('slug', 'curator')->firstOrFail();
        $teacher = ProductDoc::query()->where('slug', 'teacher')->firstOrFail();
        $accountant = ProductDoc::query()->where('slug', 'accountant')->firstOrFail();

        $this->assertNotNull($student->quizHref());
        $this->assertNotNull($curator->quizHref());
        $this->assertNotNull($teacher->quizHref());
        $this->assertNotNull($accountant->quizHref());
        $this->assertStringContainsString('proverka', $student->quizHref());
        $this->assertStringContainsString('cabinet-mastery', $curator->quizHref());
        $this->assertStringContainsString('teacher-mastery', $teacher->quizHref());
        $this->assertStringContainsString('accountant-mastery', $accountant->quizHref());

        $this->get(DocumentationCatalog::getUrl())
            ->assertOk()
            ->assertSee('Проверка', false)
            ->assertSee($teacher->quizHref(), false)
            ->assertSee($accountant->quizHref(), false);
    }

    private function partIvQuestionCount(string $relative): int
    {
        return preg_match_all('/^\*\*[^*]+\*\*/um', $this->partIvBody($relative)) ?: 0;
    }

    private function partIvBody(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path);
        $text = (string) file_get_contents($path);
        if (preg_match('/^# Часть IV[^\n]*\n(.*)$/usm', $text, $m) !== 1) {
            $this->fail($relative.': нет заголовка Часть IV');
        }

        $body = $m[1];
        $cut = preg_split('/^## Чек-лист/um', $body, 2);

        return $cut[0];
    }
}
