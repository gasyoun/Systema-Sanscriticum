<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Models\User;
use App\Services\GrammarLab\GrammarLabImporter;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class GrammarLabTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    protected function enableLab(): void
    {
        config([
            'features.grammar_lab' => true,
            'features.grammar_lab_semantic' => false,
        ]);
    }

    /**
     * Per-process, per-class suffix for the staged fixture bundle.
     *
     * CI runs `php artisan test --parallel`, and this staging directory used to be
     * one fixed path shared by every GrammarLab test class. That is safe only while
     * all of them treat it as read-only -- and
     * GrammarLabImportSearchTest::test_removed_topic_is_retired_not_deleted does not:
     * it drops a topic from grammar_lab.json and rewrites manifest.json with the new
     * digest. A concurrent process then reads either a half-written manifest
     * ("invalid JSON: .../manifest.json") or a manifest whose sha256 no longer
     * describes the bundle beside it ("grammar_lab.json sha256 mismatch"). Both
     * errors were observed together on main in run 31996750191.
     *
     * The failure is a race, so it is intermittent -- the same commit passes on a
     * rerun, which is exactly how it survived long enough to block an unrelated PR.
     * `TEST_TOKEN` is what Laravel's parallel runner sets per worker; the pid is the
     * fallback for a plain sequential `php artisan test`, and the class hash keeps two
     * classes in one worker from sharing a directory either.
     */
    private function fixtureToken(): string
    {
        $worker = (string) (getenv('TEST_TOKEN') ?: getmypid());

        return $worker.'-'.substr(sha1(static::class), 0, 8);
    }

    protected function fixtureDir(): string
    {
        $src = base_path('tests/fixtures/grammar_lab');
        $dir = storage_path('framework/testing/grammar_lab-'.$this->fixtureToken());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        copy($src.DIRECTORY_SEPARATOR.'grammar_lab.json', $dir.DIRECTORY_SEPARATOR.'grammar_lab.json');
        $sha = hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'grammar_lab.json');
        $manifest = [
            'schema_version' => '1.0.0',
            'bundle_version' => '1.0.0',
            'feeds' => [
                ['id' => 'grammar_lab_bundle', 'path' => 'grammar_lab.json', 'sha256' => $sha],
            ],
        ];
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        return $dir;
    }

    protected function importFixture(): array
    {
        return app(GrammarLabImporter::class)->import($this->fixtureDir());
    }

    protected function student(): User
    {
        return User::factory()->create(['role' => null]);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => Roles::SUPER_ADMIN]);
    }
}
