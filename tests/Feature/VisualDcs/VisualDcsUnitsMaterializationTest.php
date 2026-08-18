<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\VisualDcsRelease;
use App\Models\VisualDcsUnit;
use App\Services\Learning\ExternalLearningCatalog;
use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H2869 step-9 repair: каталог обслуживается из `visualdcs_units`, а не из
 * payload-файлов. На проде запросный json_decode опубликованного релиза
 * (26 МБ) исчерпывал memory_limit=128M php-fpm — 500 на всех поверхностях
 * при первом же флипе флагов (18-08-2026).
 */
class VisualDcsUnitsMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_path_survives_without_payload_files(): void
    {
        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/complete'));

        $release = VisualDcsRelease::query()->promoted()->firstOrFail();
        $this->assertSame(
            $this->expectedUnitCount('complete'),
            VisualDcsUnit::query()->where('visualdcs_release_id', $release->id)->count(),
        );

        // Главное доказательство: путь запроса не открывает payload-файлы.
        // Прячем каталог релиза целиком; каталог обязан работать из БД.
        $storage = (string) $release->storage_path;
        $aside = $storage.'-aside';
        File::moveDirectory($storage, $aside);

        try {
            $catalog = app(ExternalLearningCatalog::class);

            $page = $catalog->page('verb', false, true, 1);
            $this->assertNotEmpty($page['items']);
            $this->assertSame('ah', $page['items'][0]['title']);

            $this->assertGreaterThan(0, $catalog->count('nominal', false, true));

            $passage = $catalog->find('vdcs:v1:passage:hitopade-a:0');
            $this->assertNotNull($passage);
            $this->assertNotSame('', $passage['txt']);
            $this->assertNotEmpty($passage['links']);

            // mustFind — путь POST-а прогресса.
            $this->assertSame('verb', $catalog->mustFind('vdcs:v1:verb:ah')['surface']);
        } finally {
            File::moveDirectory($aside, $storage);
        }
    }

    public function test_reimport_backfills_units_for_already_promoted_release(): void
    {
        $importer = app(VisualDcsReleaseImporter::class);
        $importer->import(base_path('tests/fixtures/visualdcs/complete'));
        $expected = $this->expectedUnitCount('complete');

        // Повторный импорт того же релиза — no-op, дублей нет.
        $importer->import(base_path('tests/fixtures/visualdcs/complete'));
        $this->assertSame($expected, VisualDcsUnit::query()->count());

        // Прод-кейс H2869: релиз промоушен ДО материализации (импортирован
        // старым кодом) — units нет. Повторный запуск импорта достраивает их.
        VisualDcsUnit::query()->delete();
        $importer->import(base_path('tests/fixtures/visualdcs/complete'));
        $this->assertSame($expected, VisualDcsUnit::query()->count());
    }

    public function test_promoting_another_release_switches_catalog_rows(): void
    {
        $importer = app(VisualDcsReleaseImporter::class);
        $importer->import(base_path('tests/fixtures/visualdcs/complete'));
        $importer->import(base_path('tests/fixtures/visualdcs/sparse'));

        $titles = array_column(app(ExternalLearningCatalog::class)->list('verb', false, true), 'title');
        $this->assertContains('abhibhañj', $titles);
        $this->assertNotContains('ah', $titles);

        // История обоих релизов в таблице — откат не требует пересборки.
        $this->assertSame(2, VisualDcsRelease::query()->count());
        $this->assertSame(
            $this->expectedUnitCount('complete') + $this->expectedUnitCount('sparse'),
            VisualDcsUnit::query()->count(),
        );
    }

    private function expectedUnitCount(string $kind): int
    {
        $dir = base_path('tests/fixtures/visualdcs/'.$kind);
        $verb = json_decode((string) file_get_contents($dir.'/verb-trainer.json'), true);
        $nominal = json_decode((string) file_get_contents($dir.'/nominal-trainer.json'), true);
        $passage = json_decode((string) file_get_contents($dir.'/concordance-passage.json'), true);

        return count($verb['roots'] ?? [])
            + count($nominal['lemmas'] ?? [])
            + count($passage['passages'] ?? []);
    }
}
