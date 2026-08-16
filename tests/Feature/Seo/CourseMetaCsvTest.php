<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Tests\TestCase;

/**
 * The committed H2 CSV is the acceptance set: unique titles/descriptions, caps, no BOM.
 */
class CourseMetaCsvTest extends TestCase
{
    public function test_committed_csv_is_unique_and_within_caps(): void
    {
        $path = database_path('data/seo/course_meta_h2.csv');
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $this->assertFalse(str_starts_with($raw, "\xEF\xBB\xBF"), 'CSV must not have a UTF-8 BOM');

        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle);
        $this->assertSame(['slug', 'meta_title', 'meta_description'], $header);

        $slugs = [];
        $titles = [];
        $descs = [];
        while (($cols = fgetcsv($handle)) !== false) {
            if ($cols === [null]) {
                continue;
            }
            [$slug, $title, $desc] = $cols;
            $this->assertNotSame('', $slug);
            $this->assertLessThanOrEqual(60, mb_strlen($title), $slug);
            $this->assertLessThanOrEqual(160, mb_strlen($desc), $slug);
            $slugs[] = $slug;
            $titles[] = $title;
            $descs[] = $desc;
        }
        fclose($handle);

        $this->assertGreaterThanOrEqual(80, count($slugs), 'live sitemap /k/ set should be in the CSV');
        $this->assertCount(count($slugs), array_unique($slugs));
        $this->assertCount(count($titles), array_unique($titles));
        $this->assertCount(count($descs), array_unique($descs));
        $this->assertContains('donat-na-razvitie-ors', $slugs);
        $this->assertContains('grammatika-po-kocerginoi-gr62', $slugs);
    }
}
