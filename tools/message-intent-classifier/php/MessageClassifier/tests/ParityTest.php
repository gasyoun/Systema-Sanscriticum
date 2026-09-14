<?php

declare(strict_types=1);

namespace MessageClassifier\Tests;

use MessageClassifier\Classifier;
use MessageClassifier\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Golden-parity test: PHP engine must reproduce vectors/golden.json byte-for-byte
 * (same file the Python suite asserts against). Standalone twin:
 * tests/parity_run.php.
 */
final class ParityTest extends TestCase
{
    private static Classifier $classifier;

    /** @var list<array<string, mixed>> */
    private static array $vectors;

    public static function setUpBeforeClass(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $doc = json_decode(
            (string) file_get_contents($repoRoot.'/vectors/golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::$vectors = $doc['vectors'];
        self::$classifier = new Classifier(new Loader($repoRoot));
    }

    public function testGoldenCountAtLeastSixty(): void
    {
        $this->assertGreaterThanOrEqual(60, count(self::$vectors));
    }

    public function testNegationPairPresent(): void
    {
        $ids = array_column(self::$vectors, 'id');
        $this->assertContains('g-neg-a', $ids);
        $this->assertContains('g-neg-b', $ids);
    }

    public function testEveryVectorReproducesExactly(): void
    {
        $failures = [];
        foreach (self::$vectors as $vector) {
            $got = self::$classifier->classifyAll((string) $vector['text']);
            foreach (Loader::PLANES as $plane) {
                $want = $vector['expect'][$plane] ?? null;
                $have = $got[$plane];
                if ($want === null) {
                    if ($have !== null) {
                        $failures[] = "{$vector['id']}: {$plane} expected null, got {$have['category']}";
                    }
                    continue;
                }
                if ($have !== $want) {
                    $failures[] = "{$vector['id']}: {$plane} expected ".json_encode($want, JSON_UNESCAPED_UNICODE)
                        .', got '.json_encode($have, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        $this->assertSame([], $failures, implode("\n", array_slice($failures, 0, 20)));
    }

    public function testNormalizationMirror(): void
    {
        $this->assertSame('елка ежик', Loader::normalizeText('Ёлка ЁЖИК'));
        $this->assertSame('a b c', Loader::normalizeText("  a\t\n b   c "));
        $this->assertSame('', Loader::normalizeText(null));
    }
}
