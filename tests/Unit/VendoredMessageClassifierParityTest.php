<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use MessageClassifier\Classifier;
use MessageClassifier\Loader;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * H3529: vendored копия пакета message-intent-classifier жива и её PHP-движок
 * проходит golden-vectors так же, как upstream parity_run.php. Требует
 * symfony/yaml — в проде он не ставится (dev-only dep), поэтому вне
 * dev-окружения тест честно пропускается; CI всегда прогоняет его.
 */
class VendoredMessageClassifierParityTest extends TestCase
{
    public function test_vendored_php_engine_passes_golden_vectors(): void
    {
        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml not installed (no-dev environment) — covered by CI');
        }

        $packageRoot = base_path('tools/message-intent-classifier');
        $this->assertFileExists($packageRoot.'/PINNED_SHA', 'vendored snapshot metadata missing');

        $goldenPath = $packageRoot.'/vectors/golden.json';
        $this->assertFileExists($goldenPath);

        $doc = json_decode((string) file_get_contents($goldenPath), true);
        $vectors = $doc['vectors'] ?? [];
        $this->assertGreaterThanOrEqual(60, count($vectors), 'golden vector pack looks truncated');

        require_once $packageRoot.'/php/MessageClassifier/Loader.php';
        require_once $packageRoot.'/php/MessageClassifier/Classifier.php';

        $loader = new Loader($packageRoot);
        $classifier = new Classifier($loader);

        $failures = [];

        foreach ($vectors as $vector) {
            $got = $classifier->classifyAll((string) $vector['text']);

            foreach (Loader::PLANES as $plane) {
                $want = $vector['expect'][$plane] ?? null;
                $have = $got[$plane];

                $wantCategory = is_array($want) ? ($want['category'] ?? null) : $want;

                if ($wantCategory === null && $have !== null) {
                    $failures[] = "{$vector['id']}: plane={$plane} expected null, got {$have['category']}";

                    continue;
                }

                if ($wantCategory !== null
                    && (($have['category'] ?? null) !== $wantCategory)) {
                    $failures[] = "{$vector['id']}: plane={$plane} want {$wantCategory}, got ".($have['category'] ?? 'null');
                }
            }
        }

        $this->assertSame([], $failures, "vendored engine diverges from golden vectors:\n".implode("\n", $failures));
    }

    #[RequiresPhpunit('11')]
    public function test_generated_json_twins_match_vendored_yaml_rule_counts(): void
    {
        $rulesDir = base_path('tools/message-intent-classifier/rules/v1');

        $yamlFiles = File::glob($rulesDir.'/*.yaml');
        $jsonFiles = File::glob($rulesDir.'/*.json');

        $this->assertNotEmpty($yamlFiles);
        $this->assertCount(count($yamlFiles), $jsonFiles, 'every rules/v1/*.yaml must ship a generated .json twin');

        if (! class_exists(Yaml::class)) {
            $this->markTestSkipped('symfony/yaml not installed (no-dev environment) — covered by CI');
        }

        foreach ($yamlFiles as $yamlPath) {
            $jsonPath = substr($yamlPath, 0, -5).'.json';
            $this->assertFileExists($jsonPath);

            $yamlDoc = Yaml::parseFile($yamlPath);
            $jsonDoc = json_decode((string) file_get_contents($jsonPath), true);

            $this->assertSame(
                count($yamlDoc['rules']),
                count($jsonDoc['rules']),
                basename($yamlPath).': rule count drift between YAML and generated JSON',
            );

            foreach ($yamlDoc['rules'] as $i => $entry) {
                $this->assertSame($entry['category'], $jsonDoc['rules'][$i]['category']);
                $this->assertSame($entry['patterns'], array_values($jsonDoc['rules'][$i]['patterns']));
            }
        }
    }
}
