<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\VisualDcsRelease;
use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VisualDcsImporterTest extends TestCase
{
    use RefreshDatabase;

    private function completePath(): string
    {
        return base_path('tests/fixtures/visualdcs/complete');
    }

    private function sparsePath(): string
    {
        return base_path('tests/fixtures/visualdcs/sparse');
    }

    public function test_import_promotes_complete_fixture(): void
    {
        $this->artisan('visualdcs:import', ['path' => $this->completePath()])
            ->assertSuccessful();

        $release = VisualDcsRelease::query()->promoted()->first();
        $this->assertNotNull($release);
        $this->assertSame('vdcs-fixture-complete-20260813', $release->release_id);
        $this->assertFileExists($release->storage_path.DIRECTORY_SEPARATOR.'verb-trainer.json');
    }

    public function test_reimport_same_release_is_noop(): void
    {
        $importer = app(VisualDcsReleaseImporter::class);
        $first = $importer->import($this->completePath());
        $second = $importer->import($this->completePath());

        $this->assertFalse($first['noop']);
        $this->assertTrue($second['noop']);
        $this->assertSame(1, VisualDcsRelease::query()->count());
    }

    public function test_corrupt_hash_is_rejected_and_keeps_previous(): void
    {
        app(VisualDcsReleaseImporter::class)->import($this->completePath());
        $before = VisualDcsRelease::query()->promoted()->first();

        $tmp = storage_path('app/visualdcs-tmp-corrupt');
        File::deleteDirectory($tmp);
        File::copyDirectory($this->sparsePath(), $tmp);
        $manifestPath = $tmp.DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true);
        $manifest['sha256']['verb-trainer.json'] = str_repeat('0', 64);
        File::put($manifestPath, json_encode($manifest));

        $code = Artisan::call('visualdcs:import', ['path' => $tmp]);
        $this->assertSame(1, $code);
        $this->assertSame($before->id, VisualDcsRelease::query()->promoted()->value('id'));

        File::deleteDirectory($tmp);
    }

    public function test_wrong_version_is_rejected(): void
    {
        $tmp = storage_path('app/visualdcs-tmp-version');
        File::deleteDirectory($tmp);
        File::copyDirectory($this->completePath(), $tmp);
        $manifestPath = $tmp.DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(File::get($manifestPath), true);
        $manifest['contractVersion'] = '2.0.0';
        File::put($manifestPath, json_encode($manifest));

        $this->artisan('visualdcs:import', ['path' => $tmp])->assertFailed();
        $this->assertSame(0, VisualDcsRelease::query()->count());
        File::deleteDirectory($tmp);
    }

    public function test_rollback_restores_previous_release(): void
    {
        app(VisualDcsReleaseImporter::class)->import($this->completePath());
        app(VisualDcsReleaseImporter::class)->import($this->sparsePath());

        $this->assertSame(
            'vdcs-fixture-sparse-20260813',
            VisualDcsRelease::query()->promoted()->value('release_id')
        );

        $this->artisan('visualdcs:rollback')->assertSuccessful();

        $this->assertSame(
            'vdcs-fixture-complete-20260813',
            VisualDcsRelease::query()->promoted()->value('release_id')
        );
    }
}
