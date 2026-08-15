<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\VisualDcsRelease;
use App\Services\Learning\ExternalLearningCatalog;
use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consume the H2499-verified published v1 pin when the sibling VisualDCS
 * tree is present. CI without the sibling still checks the committed pin file.
 */
class VisualDcsPublishedPinTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function pin(): array
    {
        $path = base_path('tests/fixtures/visualdcs/published-v1-pin.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_committed_pin_matches_h2499_keep_110(): void
    {
        $pin = $this->pin();
        $this->assertSame('vdcs-learner-v1-20260809', $pin['releaseId']);
        $this->assertSame(
            '5276b7d04ee409c58c8fe7426b26efdabc2cbc1d3d4d05082f5ed1d593f2d4f6',
            $pin['sha256']['verb-trainer.json']
        );
        $this->assertSame(7689, $pin['recordCount']['verb-trainer.json']);
        $this->assertSame(31753, $pin['recordCount']['nominal-trainer.json']);
        $this->assertSame(40, $pin['recordCount']['concordance-passage.json']);
    }

    public function test_sibling_published_v1_hashes_and_imports(): void
    {
        $pin = $this->pin();
        $dir = $this->siblingV1();
        if ($dir === null) {
            $this->markTestSkipped('Sibling VisualDCS visual/contracts/v1 not present.');
        }

        foreach ($pin['sha256'] as $file => $expected) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            $this->assertFileExists($path);
            $this->assertSame($expected, hash_file('sha256', $path), $file);
            $this->assertSame($pin['bytes'][$file], filesize($path), $file);
        }

        $result = app(VisualDcsReleaseImporter::class)->import($dir);
        $this->assertFalse($result['noop']);
        $this->assertSame($pin['releaseId'], $result['release']->release_id);
        $this->assertSame(VisualDcsRelease::STATUS_PROMOTED, $result['release']->status);

        $catalog = app(ExternalLearningCatalog::class);
        $this->assertSame(7689, $catalog->count('verb', false, true));
        $this->assertSame(31753, $catalog->count('nominal', false, true));
        $this->assertSame(40, $catalog->count('passage', false, true));

        $page = $catalog->page('verb', false, true, 1, 50);
        $this->assertCount(50, $page['items']);
        $this->assertSame(7689, $page['total']);
        $this->assertArrayNotHasKey('cells', $page['items'][0]);
        $this->assertArrayNotHasKey('raw', $page['items'][0]);

        $found = $catalog->find('vdcs:v1:verb:'.$page['items'][0]['title']);
        $this->assertNotNull($found);
        $this->assertArrayHasKey('cells', $found);
    }

    public function test_windows_forward_slash_source_is_absolute(): void
    {
        $dir = $this->siblingV1();
        if ($dir === null) {
            $this->markTestSkipped('Sibling VisualDCS visual/contracts/v1 not present.');
        }

        $forward = str_replace('\\', '/', $dir);
        $result = app(VisualDcsReleaseImporter::class)->import($forward);
        $this->assertSame('vdcs-learner-v1-20260809', $result['release']->release_id);
    }

    private function siblingV1(): ?string
    {
        $candidates = [
            dirname(base_path()).DIRECTORY_SEPARATOR.'VisualDCS'.DIRECTORY_SEPARATOR.'visual'.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'v1',
            'C:\\Users\\user\\Documents\\GitHub\\VisualDCS\\visual\\contracts\\v1',
        ];
        foreach ($candidates as $path) {
            if (is_file($path.DIRECTORY_SEPARATOR.'manifest.json')
                && is_file($path.DIRECTORY_SEPARATOR.'verb-trainer.json')) {
                return $path;
            }
        }

        return null;
    }
}
