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

    protected function fixtureDir(): string
    {
        $src = base_path('tests/fixtures/grammar_lab');
        $dir = storage_path('framework/testing/grammar_lab');
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
