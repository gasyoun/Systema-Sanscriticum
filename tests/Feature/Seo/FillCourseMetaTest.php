<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillCourseMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_duplicate_title_exits_1_and_writes_nothing(): void
    {
        Course::factory()->create(['slug' => 'alpha', 'is_visible' => true]);
        Course::factory()->create(['slug' => 'beta', 'is_visible' => true]);
        $path = $this->writeCsv([
            ['alpha', 'Same title', 'Description one for alpha.'],
            ['beta', 'Same title', 'Description two for beta.'],
        ]);

        $this->artisan('seo:fill-course-meta', ['csv' => $path, '--dry-run' => true])
            ->assertFailed();

        $this->assertNull(Course::query()->where('slug', 'alpha')->value('meta_title'));
        $this->assertNull(Course::query()->where('slug', 'beta')->value('meta_title'));
    }

    public function test_apply_updates_only_meta_columns(): void
    {
        $course = Course::factory()->create([
            'slug' => 'alpha',
            'title' => 'Original title',
            'is_visible' => true,
            'is_active' => true,
        ]);
        $path = $this->writeCsv([
            ['alpha', 'New meta title', 'New unique meta description for alpha.'],
        ]);

        $this->artisan('seo:fill-course-meta', ['csv' => $path])->assertSuccessful();

        $fresh = $course->fresh();
        $this->assertSame('New meta title', $fresh->meta_title);
        $this->assertSame('New unique meta description for alpha.', $fresh->meta_description);
        $this->assertSame('Original title', $fresh->title);
        $this->assertTrue($fresh->is_visible);
        $this->assertTrue($fresh->is_active);
    }

    public function test_reset_slugs_nulls_only_meta_columns(): void
    {
        $course = Course::factory()->create([
            'slug' => 'alpha',
            'title' => 'Keep me',
            'is_visible' => true,
            'meta_title' => 'Old meta',
            'meta_description' => 'Old desc',
        ]);

        $this->artisan('seo:fill-course-meta', ['--reset-slugs' => 'alpha'])
            ->assertSuccessful();

        $fresh = $course->fresh();
        $this->assertNull($fresh->meta_title);
        $this->assertNull($fresh->meta_description);
        $this->assertSame('Keep me', $fresh->title);
    }

    public function test_unknown_or_hidden_slug_fails(): void
    {
        Course::factory()->hidden()->create(['slug' => 'hidden-one']);
        $path = $this->writeCsv([
            ['hidden-one', 'Hidden title here', 'Hidden description that is unique enough.'],
        ]);

        $this->artisan('seo:fill-course-meta', ['csv' => $path, '--dry-run' => true])
            ->assertFailed();

        $missing = $this->writeCsv([
            ['no-such-slug', 'Missing title here', 'Missing description that is unique enough.'],
        ]);
        $this->artisan('seo:fill-course-meta', ['csv' => $missing, '--dry-run' => true])
            ->assertFailed();
    }

    public function test_bom_is_rejected(): void
    {
        Course::factory()->create(['slug' => 'alpha', 'is_visible' => true]);
        $path = storage_path('app/seo-bom.csv');
        file_put_contents(
            $path,
            "\xEF\xBB\xBF"."slug,meta_title,meta_description\nalpha,Title under sixty,Description under one hundred sixty.\n"
        );

        $this->artisan('seo:fill-course-meta', ['csv' => $path, '--dry-run' => true])
            ->assertFailed();
    }

    public function test_title_over_60_fails(): void
    {
        Course::factory()->create(['slug' => 'alpha', 'is_visible' => true]);
        $tooLong = str_repeat('я', 61);
        $path = $this->writeCsv([
            ['alpha', $tooLong, 'Short unique description for the overflow title test.'],
        ]);

        $this->artisan('seo:fill-course-meta', ['csv' => $path, '--dry-run' => true])
            ->assertFailed();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = storage_path('app/seo-fill-test-'.uniqid('', true).'.csv');
        $fh = fopen($path, 'w');
        fwrite($fh, "slug,meta_title,meta_description\n");
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }
}
