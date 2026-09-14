<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseSlugAlias;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CatalogShellRetirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Сведение курса-оболочки на живой курс семьи (H3807).
 *
 * Боевой случай: курс 421 «Караки по Панини 2025-2026 в записи» — ноль блоков,
 * ноль тарифов, ноль оплат, девять записанных, и все девять уже на живом курсе
 * 335. Условие MG 19-08-2026 сохраняется дословно: «главное, чтобы не
 * потерялись никакие записи ни при каких обстоятельствах», поэтому большая
 * часть проверок здесь — про то, чего команда делать НЕ должна.
 */
class CatalogShellRetirementTest extends TestCase
{
    use RefreshDatabase;

    private function retirement(): CatalogShellRetirement
    {
        return app(CatalogShellRetirement::class);
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

    private function live(array $attributes = []): Course
    {
        $course = Course::factory()->create(array_merge([
            'title' => 'Караки по Панини (2025)',
            'slug' => 'karaki-po-panini-2025-test-only',
            'course_family' => 'karaki-po-panini',
            'is_visible' => true,
        ], $attributes));

        // Собственные данные — иначе живой курс сам считается оболочкой.
        Lesson::factory()->for($course)->create();

        return $course;
    }

    /** @test */
    public function enrolments_already_covered_by_the_live_course_are_left_alone(): void
    {
        $shell = $this->shell();
        $live = $this->live();
        $user = User::factory()->create();

        $shell->users()->attach($user->id);
        $live->users()->attach($user->id);

        $plan = $this->retirement()->apply($shell, $live);

        $this->assertSame([$user->id], $plan['enrolments']['covered']);
        $this->assertSame([], $plan['enrolments']['to_move']);
        $this->assertSame(1, DB::table('course_user')->where('course_id', $live->id)->count(), 'дубля записи не появилось');
    }

    /** @test */
    public function an_enrolment_missing_on_the_live_course_is_added_with_its_pivot_fields(): void
    {
        $shell = $this->shell();
        $live = $this->live();
        $user = User::factory()->create();

        $shell->users()->attach($user->id, ['status' => 'active', 'note' => 'перевод из записи']);

        $plan = $this->retirement()->apply($shell, $live);

        $this->assertSame([$user->id], $plan['enrolments']['to_move']);

        $pivot = DB::table('course_user')->where('course_id', $live->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($pivot, 'человек записан на живой курс');
        $this->assertSame('active', $pivot->status);
        $this->assertSame('перевод из записи', $pivot->note);
    }

    /** @test */
    public function the_shell_keeps_its_own_enrolment_rows(): void
    {
        $shell = $this->shell();
        $live = $this->live();
        $user = User::factory()->create();
        $shell->users()->attach($user->id);

        $this->retirement()->apply($shell, $live);

        $this->assertSame(
            1,
            DB::table('course_user')->where('course_id', $shell->id)->count(),
            'след записи на оболочке сохраняется до дня удаления курса',
        );
    }

    /** @test */
    public function a_visible_shell_is_hidden_and_an_already_hidden_one_is_left_as_is(): void
    {
        $live = $this->live();

        $visible = $this->shell(['slug' => 'karaki-shell-visible-test-only', 'is_visible' => true]);
        $plan = $this->retirement()->apply($visible, $live);
        $this->assertTrue($plan['visibility']['change']);
        $this->assertFalse((bool) $visible->fresh()->is_visible);

        $hidden = $this->shell(['slug' => 'karaki-shell-hidden-test-only', 'is_visible' => false]);
        $plan = $this->retirement()->apply($hidden, $live);
        $this->assertFalse($plan['visibility']['change'], 'скрытому курсу менять нечего');
    }

    /** @test */
    public function the_shell_slug_becomes_an_alias_of_the_live_course(): void
    {
        $shell = $this->shell();
        $live = $this->live();

        $this->retirement()->apply($shell, $live);

        $alias = CourseSlugAlias::query()->where('slug', $shell->slug)->first();
        $this->assertNotNull($alias);
        $this->assertSame($live->id, (int) $alias->course_id);
    }

    /** @test */
    public function the_canonical_slug_still_wins_while_the_shell_is_alive(): void
    {
        $shell = $this->shell();
        $live = $this->live();

        $this->retirement()->apply($shell, $live);

        $this->assertSame(
            $shell->id,
            Course::resolveBySlug((string) $shell->slug)?->id,
            'алиас заведён заранее, но живая оболочка резолвится в себя — 301 включится только после удаления',
        );
    }

    /** @test */
    public function an_alias_owned_by_a_third_course_is_never_silently_repointed(): void
    {
        $shell = $this->shell();
        $live = $this->live();
        $other = $this->live(['slug' => 'karaki-po-panini-2024-test-only']);

        CourseSlugAlias::query()->create(['slug' => $shell->slug, 'course_id' => $other->id, 'created_at' => now()]);

        $plan = $this->retirement()->apply($shell, $live);

        $this->assertFalse($plan['alias']['create']);
        $this->assertSame(
            $other->id,
            (int) CourseSlugAlias::query()->where('slug', $shell->slug)->value('course_id'),
        );
    }

    /** @test */
    public function a_course_with_its_own_data_is_refused(): void
    {
        $notAShell = $this->live(['slug' => 'karaki-recording-with-data-test-only']);
        $live = $this->live();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/не оболочка/u');

        $this->retirement()->plan($notAShell, $live);
    }

    /** @test */
    public function a_target_from_another_family_is_refused(): void
    {
        $shell = $this->shell();
        $stranger = $this->live([
            'title' => 'Ликбез по лингвистике (2 поток, 2025-2026)',
            'slug' => 'likbez-2-potok-test-only',
            'course_family' => 'likbez-po-lingvistike',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Разные семьи/u');

        $this->retirement()->plan($shell, $stranger);
    }

    /** @test */
    public function a_shell_target_is_refused(): void
    {
        $shell = $this->shell();
        $otherShell = $this->shell(['slug' => 'karaki-second-shell-test-only']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/сам оболочка/u');

        $this->retirement()->plan($shell, $otherShell);
    }

    /** @test */
    public function the_command_writes_nothing_without_apply(): void
    {
        $shell = $this->shell(['is_visible' => true]);
        $live = $this->live();
        $user = User::factory()->create();
        $shell->users()->attach($user->id);

        $this->artisan('catalog:retire-shell', ['course' => $shell->id, '--into' => $live->id])
            ->assertSuccessful();

        $this->assertTrue((bool) $shell->fresh()->is_visible, 'сухой прогон витрину не трогает');
        $this->assertSame(0, DB::table('course_user')->where('course_id', $live->id)->count());
        $this->assertSame(0, CourseSlugAlias::query()->where('slug', $shell->slug)->count());
    }

    /** @test */
    public function the_command_applies_all_three_steps(): void
    {
        $shell = $this->shell(['is_visible' => true]);
        $live = $this->live();
        $user = User::factory()->create();
        $shell->users()->attach($user->id);

        $this->artisan('catalog:retire-shell', ['course' => $shell->slug, '--into' => $live->slug, '--apply' => true])
            ->assertSuccessful();

        $this->assertFalse((bool) $shell->fresh()->is_visible);
        $this->assertSame(1, DB::table('course_user')->where('course_id', $live->id)->where('user_id', $user->id)->count());
        $this->assertSame($live->id, (int) CourseSlugAlias::query()->where('slug', $shell->slug)->value('course_id'));
    }

    /** @test */
    public function the_command_fails_loudly_without_into(): void
    {
        $shell = $this->shell();

        $this->artisan('catalog:retire-shell', ['course' => $shell->id])->assertFailed();
    }

    /** @test */
    public function the_course_is_never_deleted(): void
    {
        $shell = $this->shell();
        $live = $this->live();

        $this->retirement()->apply($shell, $live);

        $this->assertNotNull(Course::query()->find($shell->id), 'сведение не удаляет курс — удаление это отдельный проход');
    }
}
