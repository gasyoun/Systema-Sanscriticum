<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Удаление одобренных аудитом оболочек (H3773).
 *
 * Условие MG 19-08-2026 не изменилось: «главное, чтобы не потерялись никакие
 * записи ни при каких обстоятельствах». Поэтому почти все проверки здесь — про
 * то, чего команда НЕ делает.
 */
class DeleteCatalogShellsTest extends TestCase
{
    use RefreshDatabase;

    private function shellCourse(string $title, string $slug): Course
    {
        return Course::factory()->create([
            'title' => $title,
            'slug' => $slug,
            'is_visible' => false,
        ]);
    }

    /** @test */
    public function without_apply_it_deletes_nothing(): void
    {
        $shell = $this->shellCourse('Саравали 2026', 'saravali-2026-del-test');

        $this->artisan('catalog:delete-shells --course='.$shell->id)
            ->assertExitCode(0);

        $this->assertNotNull($shell->fresh(), 'сухой прогон не удаляет');
    }

    /** @test */
    public function with_apply_it_removes_an_approved_shell(): void
    {
        $shell = $this->shellCourse('Ликбез по лингвистике (2023)', 'likbez-2023-del-test');

        $this->artisan('catalog:delete-shells --course='.$shell->id.' --apply')
            ->assertExitCode(0);

        $this->assertNull($shell->fresh());
    }

    /** @test */
    public function it_refuses_a_course_the_audit_does_not_call_safe(): void
    {
        // Живой курс: есть активный тариф, значит оболочкой он не является и в
        // отчёт вообще не попадает.
        $live = Course::factory()->create(['title' => 'Живой курс', 'slug' => 'zhivoi-del-test']);
        Tariff::factory()->for($live)->create();

        $this->artisan('catalog:delete-shells --course='.$live->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($live->fresh(), 'живой курс не удалён');
    }

    /** @test */
    public function it_refuses_a_visible_shell(): void
    {
        // Видимость — блокер в аудите: сначала скрыть и убедиться, что не нужен.
        $visible = Course::factory()->create([
            'title' => 'Пустой, но на витрине',
            'slug' => 'pustoi-na-vitrine-del-test',
            'is_visible' => true,
        ]);

        $this->artisan('catalog:delete-shells --course='.$visible->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($visible->fresh());
    }

    /** @test */
    public function it_refuses_when_an_enrolled_student_has_no_twin(): void
    {
        // Ровно случай, ради которого писалось правило близнеца: человек
        // записан ТОЛЬКО сюда, и удаление отняло бы у него единственную запись.
        $shell = $this->shellCourse('Караки по Панини в записи', 'karaki-orphan-del-test');
        $shell->users()->attach(User::factory()->create()->id);

        $this->artisan('catalog:delete-shells --course='.$shell->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($shell->fresh(), 'сирота без близнеца защищает курс от удаления');
    }

    /** @test */
    public function an_enrolled_student_with_a_twin_does_not_block_deletion(): void
    {
        // Боевая форма 335/421: все девять записанных держат и настоящий курс.
        $live = Course::factory()->create(['title' => 'Караки по Панини', 'slug' => 'karaki-live-del-test']);
        Tariff::factory()->for($live)->create();

        $shell = $this->shellCourse('Караки по Панини 2025-2026 в записи', 'karaki-twin-del-test');

        $user = User::factory()->create();
        $live->users()->attach($user->id);
        $shell->users()->attach($user->id);

        $this->artisan('catalog:delete-shells --course='.$shell->id.' --apply')
            ->assertExitCode(0);

        $this->assertNull($shell->fresh());
        $this->assertNotNull($live->fresh(), 'настоящий курс не тронут');
        $this->assertDatabaseHas('course_user', ['user_id' => $user->id, 'course_id' => $live->id]);
        $this->assertDatabaseMissing('course_user', ['course_id' => $shell->id]);
    }

    /** @test */
    public function a_paid_payment_makes_the_course_invisible_to_this_command(): void
    {
        $course = $this->shellCourse('Курс с оплатой', 'kurs-s-oplatoi-del-test');
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->artisan('catalog:delete-shells --course='.$course->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($course->fresh(), 'оплата — это данные, курс уже не оболочка');
    }

    /** @test */
    public function it_refuses_a_group_whose_slug_the_code_references(): void
    {
        // «Старт чтения» и «Клуб» пусты в базе, но код ходит к ним по слагу.
        $group = Group::factory()->create(['name' => 'Старт чтения', 'slug' => 'start-chteniya']);

        $this->artisan('catalog:delete-shells --group='.$group->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($group->fresh(), 'рабочая заготовка под фичу не удаляется');
    }

    /** @test */
    public function it_removes_an_approved_empty_group(): void
    {
        $group = Group::factory()->create([
            'name' => 'Затраты на ИП',
            'slug' => 'zatraty-na-ip-del-test-only',
        ]);

        $this->artisan('catalog:delete-shells --group='.$group->id.' --apply')
            ->assertExitCode(0);

        $this->assertNull($group->fresh());
    }

    /** @test */
    public function one_refused_object_cancels_the_whole_batch(): void
    {
        // Всё или ничего: иначе частичное удаление оставило бы каталог в
        // состоянии, которого не видел ни один отчёт.
        $ok = $this->shellCourse('Саравали 2026', 'saravali-batch-del-test');
        $bad = Course::factory()->create(['title' => 'Живой', 'slug' => 'zhivoi-batch-del-test']);
        Tariff::factory()->for($bad)->create();

        $this->artisan('catalog:delete-shells --course='.$ok->id.' --course='.$bad->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($ok->fresh(), 'одобренный объект тоже уцелел — партия отклонена целиком');
        $this->assertNotNull($bad->fresh());
    }

    /** @test */
    public function it_refuses_to_run_with_no_ids_at_all(): void
    {
        // Нет режима «удали всё безопасное»: список называет человек.
        $shell = $this->shellCourse('Саравали 2026', 'saravali-noids-del-test');

        $this->artisan('catalog:delete-shells --apply')
            ->assertExitCode(1);

        $this->assertNotNull($shell->fresh());
    }

    /** @test */
    public function the_verdict_is_recomputed_at_delete_time_not_taken_from_a_report(): void
    {
        // Отчёт снят, курс безопасен — а потом на него завели тариф. Команда
        // обязана увидеть НОВОЕ состояние, а не то, что было в отчёте.
        $shell = $this->shellCourse('Саравали 2026', 'saravali-stale-del-test');

        $this->artisan('catalog:delete-shells --course='.$shell->id)->assertExitCode(0);

        Tariff::factory()->for($shell)->create();

        $this->artisan('catalog:delete-shells --course='.$shell->id.' --apply')
            ->assertExitCode(1);

        $this->assertNotNull($shell->fresh(), 'устаревший вердикт из отчёта не принимается');
    }

    /** @test */
    public function deleting_a_course_leaves_other_courses_untouched(): void
    {
        $shell = $this->shellCourse('Саравали 2026', 'saravali-isolation-del-test');
        $other = Course::factory()->create(['title' => 'Другой курс', 'slug' => 'drugoi-isolation-del-test']);
        Tariff::factory()->for($other)->create();

        $before = DB::table('courses')->where('id', '!=', $shell->id)->count();

        $this->artisan('catalog:delete-shells --course='.$shell->id.' --apply')->assertExitCode(0);

        $this->assertSame($before, DB::table('courses')->count());
        $this->assertNotNull($other->fresh());
    }
}
