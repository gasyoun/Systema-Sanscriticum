<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TeachingGlossaryCourse;
use App\Models\TeachingGlossaryTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Импорт агрегата преподавательского глоссария (H4832): шапка, типы,
 * пустые значения, dry-run, идемпотентный рефреш.
 */
class TeachingGlossaryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $termsPath;

    private string $coursesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->termsPath = sys_get_temp_dir().'/tg_terms_test.tsv';
        $this->coursesPath = sys_get_temp_dir().'/tg_courses_test.tsv';

        file_put_contents($this->termsPath, implode("\n", [
            "cyrillic_form\tlemma_slp1\tru_gloss\tcorpus_freq\tn_files\tn_courses\tambiguous_lemmas\tgloss_provenance",
            "вишну\tvizRu\tВишну\t4303\t441\t36\t\tagent",
            "атман\tAtman atman\tсебя; Атман\t3950\t279\t16\tyes\thuman-pending",
            "редкое\tракта\t\t20\t3\t1\t\t",
        ]));

        file_put_contents($this->coursesPath, implode("\n", [
            "course\tn_headwords\ttop_terms",
            "Астро 1\t199\tнакшатра индра вишну",
            "Бюлероведение 2023-2024\t595\tвишну риши брахма",
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->termsPath);
        @unlink($this->coursesPath);

        parent::tearDown();
    }

    public function test_import_loads_terms_and_course_profile(): void
    {
        $this->artisan('teaching-glossary:import', [
            'termsPath' => $this->termsPath,
            '--courses-path' => $this->coursesPath,
        ])->assertSuccessful();

        $this->assertSame(3, TeachingGlossaryTerm::query()->count());

        $atman = TeachingGlossaryTerm::query()->where('cyrillic_form', 'атман')->firstOrFail();
        $this->assertTrue($atman->ambiguous_lemmas);
        $this->assertSame('себя; Атман', $atman->ru_gloss);
        $this->assertSame(3950, $atman->corpus_freq);

        $redkoe = TeachingGlossaryTerm::query()->where('cyrillic_form', 'редкое')->firstOrFail();
        $this->assertNull($redkoe->ru_gloss);
        $this->assertNull($redkoe->gloss_provenance);
        $this->assertFalse($redkoe->ambiguous_lemmas);

        $this->assertSame(2, TeachingGlossaryCourse::query()->count());
        $this->assertSame(199, TeachingGlossaryCourse::query()->where('course', 'Астро 1')->value('n_headwords'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('teaching-glossary:import', [
            'termsPath' => $this->termsPath,
            '--courses-path' => $this->coursesPath,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, TeachingGlossaryTerm::query()->count());
        $this->assertSame(0, TeachingGlossaryCourse::query()->count());
    }

    public function test_reimport_is_a_clean_refresh_not_a_duplicate(): void
    {
        foreach ([1, 2] as $run) {
            $this->artisan('teaching-glossary:import', [
                'termsPath' => $this->termsPath,
                '--courses-path' => $this->coursesPath,
            ])->assertSuccessful();
            $this->assertSame(3, TeachingGlossaryTerm::query()->count(), "run {$run}");
            $this->assertSame(2, TeachingGlossaryCourse::query()->count(), "run {$run}");
        }
    }

    public function test_unexpected_header_fails_loud(): void
    {
        $bad = sys_get_temp_dir().'/tg_bad_test.tsv';
        file_put_contents($bad, "word\tlemma\tfreq\nвишну\tvizRu\t4303\n");

        try {
            $this->artisan('teaching-glossary:import', ['termsPath' => $bad])->assertExitCode(1);
        } finally {
            @unlink($bad);
        }

        $this->assertSame(0, TeachingGlossaryTerm::query()->count());
    }

    public function test_missing_file_fails_without_writes(): void
    {
        $this->artisan('teaching-glossary:import', ['termsPath' => '/nonexistent/glossary.tsv'])->assertExitCode(1);

        $this->assertSame(0, DB::table('teaching_glossary_terms')->count());
    }
}
