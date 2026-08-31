<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseSlugAlias;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Удаление курса-оболочки не имеет права убивать её ссылки (H3807).
 *
 * Боевой инцидент 31-08-2026: `catalog:delete-shells` снёс курсы 421 «Караки по
 * Панини 2025-2026 в записи» и 430 «Ликбез по лингвистике (2023)» вместе с их
 * строками в `course_slug_aliases`. Записи никто не потерял — все девять
 * записанных уже были на живом курсе 335, — но `/k/karaki-po-panini-2025-2026-v-zapisi`
 * стал отдавать честный 404 вместо 301 на живой курс.
 */
class CatalogShellSlugSurvivalTest extends TestCase
{
    use RefreshDatabase;

    private function live(array $attributes = []): Course
    {
        $course = Course::factory()->create(array_merge([
            'title' => 'Караки по Панини (2025)',
            'slug' => 'karaki-po-panini-2025-test-only',
            'course_family' => 'karaki-po-panini',
            'is_visible' => true,
        ], $attributes));

        Lesson::factory()->for($course)->create();

        return $course;
    }

    private function shell(array $attributes = []): Course
    {
        return Course::factory()->create(array_merge([
            'title' => 'Караки по Панини 2025-2026 в записи',
            'slug' => 'karaki-po-panini-2025-2026-v-zapisi-test-only',
            'course_family' => 'karaki-po-panini',
            'is_visible' => false,
        ], $attributes));
    }

    /** @test */
    public function deleting_a_shell_hands_its_slug_to_the_only_live_course_of_the_family(): void
    {
        $live = $this->live();
        $shell = $this->shell();
        $slug = (string) $shell->slug;

        $this->artisan('catalog:delete-shells', ['--course' => [$shell->id], '--apply' => true])
            ->assertSuccessful();

        $this->assertNull(Course::query()->find($shell->id), 'оболочка удалена');
        $this->assertSame(
            $live->id,
            Course::resolveBySlug($slug)?->id,
            'ссылка на удалённую оболочку ведёт на живой курс, а не в 404',
        );
    }

    /** @test */
    public function the_shells_own_old_aliases_survive_too(): void
    {
        $live = $this->live();
        $shell = $this->shell();

        CourseSlugAlias::query()->create([
            'slug' => 'karaki-staryi-slug-test-only',
            'course_id' => $shell->id,
            'created_at' => now(),
        ]);

        $this->artisan('catalog:delete-shells', ['--course' => [$shell->id], '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(
            $live->id,
            Course::resolveBySlug('karaki-staryi-slug-test-only')?->id,
            'прежний алиас оболочки тоже переезжает, а не исчезает с курсом',
        );
    }

    /** @test */
    public function an_ambiguous_family_is_refused_until_a_human_names_the_target(): void
    {
        $this->live();
        $this->live(['slug' => 'karaki-po-panini-2024-test-only']);
        $shell = $this->shell();

        $this->artisan('catalog:delete-shells', ['--course' => [$shell->id], '--apply' => true])
            ->assertFailed();

        $this->assertNotNull(Course::query()->find($shell->id), 'при неоднозначной семье не удаляем вовсе');
    }

    /** @test */
    public function an_explicit_alias_into_resolves_the_ambiguity(): void
    {
        $this->live();
        $chosen = $this->live(['slug' => 'karaki-po-panini-2024-test-only']);
        $shell = $this->shell();
        $slug = (string) $shell->slug;

        $this->artisan('catalog:delete-shells', [
            '--course' => [$shell->id],
            '--alias-into' => ["{$shell->id}:{$chosen->id}"],
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($chosen->id, Course::resolveBySlug($slug)?->id);
    }

    /** @test */
    public function drop_slug_lets_a_human_accept_the_404_on_purpose(): void
    {
        $this->live();
        $this->live(['slug' => 'karaki-po-panini-2024-test-only']);
        $shell = $this->shell();
        $slug = (string) $shell->slug;

        $this->artisan('catalog:delete-shells', [
            '--course' => [$shell->id],
            '--drop-slug' => [$shell->id],
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertNull(Course::query()->find($shell->id));
        $this->assertNull(Course::resolveBySlug($slug), '404 здесь выбран человеком, а не случился молча');
    }

    /** @test */
    public function a_dry_run_touches_neither_the_course_nor_the_slug(): void
    {
        $this->live();
        $shell = $this->shell();
        $slug = (string) $shell->slug;

        $this->artisan('catalog:delete-shells', ['--course' => [$shell->id]])->assertSuccessful();

        $this->assertNotNull(Course::query()->find($shell->id));
        $this->assertSame(0, CourseSlugAlias::query()->where('slug', $slug)->count());
    }

    /** @test */
    public function alias_slug_repairs_a_slug_whose_course_is_already_gone(): void
    {
        $live = $this->live();

        $this->artisan('catalog:alias-slug', [
            'slug' => 'karaki-po-panini-2025-2026-v-zapisi',
            '--into' => $live->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($live->id, Course::resolveBySlug('karaki-po-panini-2025-2026-v-zapisi')?->id);
    }

    /** @test */
    public function alias_slug_leaves_a_slug_that_still_resolves_alone(): void
    {
        $live = $this->live();
        $other = $this->live(['slug' => 'karaki-po-panini-2024-test-only']);

        $this->artisan('catalog:alias-slug', [
            'slug' => (string) $other->slug,
            '--into' => $live->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($other->id, Course::resolveBySlug((string) $other->slug)?->id, 'живой канон не перевешивается');
    }
}
