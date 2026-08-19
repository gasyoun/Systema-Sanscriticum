<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CatalogShellAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Аудит пустых курсов и групп-оболочек.
 *
 * Условие MG 19-08-2026: «главное, чтобы не потерялись никакие записи ни при
 * каких обстоятельствах». Поэтому проверки здесь в основном про то, что аудит
 * НЕ называет безопасным.
 *
 * Живая ловушка, ради которой всё и написано: «Клуб» и «Старт чтения» пусты в
 * базе (ноль уроков, оплат, расписаний), но код обращается к ним по слагу — 177
 * и 14 упоминаний. Наивный критерий «нет уроков и оплат» снёс бы обе фичи.
 */
class CatalogShellAuditTest extends TestCase
{
    use RefreshDatabase;

    private function audit(): CatalogShellAudit
    {
        return app(CatalogShellAudit::class);
    }

    /** @return array<string, mixed>|null */
    private function courseRow(int $id): ?array
    {
        foreach ($this->audit()->courses() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @test */
    public function an_orphan_shell_with_nothing_attached_is_safe(): void
    {
        $course = Course::factory()->create([
            'title' => 'Мегхадута Калидасы 2025',
            'slug' => 'megxaduta-kalidasy-2025-test-only',
            'is_visible' => false,
        ]);

        $row = $this->courseRow($course->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['safe'], 'пустой скрытый курс без ссылок и записанных');
        $this->assertSame([], $row['blockers']);
    }

    /** @test */
    public function a_slug_the_code_references_is_never_safe(): void
    {
        // Слаг существующей фичи: код обращается к нему по имени.
        $course = Course::factory()->create([
            'title' => 'Клуб',
            'slug' => 'club',
            'is_visible' => false,
        ]);

        $row = $this->courseRow($course->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['safe'], 'заготовка под фичу — не дубль');
        $this->assertStringContainsString('упоминается в коде', implode(' ', $row['blockers']));
    }

    /** @test */
    public function a_course_with_lessons_is_not_a_shell_at_all(): void
    {
        $course = Course::factory()->create(['is_visible' => false]);
        Lesson::factory()->for($course)->create();

        $this->assertNull($this->courseRow($course->id), 'курс с уроками в кандидаты не попадает');
    }

    /** @test */
    public function a_course_with_a_paid_payment_is_not_a_shell(): void
    {
        $course = Course::factory()->create(['is_visible' => false]);
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertNull($this->courseRow($course->id));
    }

    /** @test */
    public function a_course_with_a_schedule_is_not_a_shell(): void
    {
        $course = Course::factory()->create(['is_visible' => false]);
        Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'start' => Carbon::parse('2026-09-01 12:00'),
            'end' => Carbon::parse('2026-09-01 13:00'),
        ]);

        $this->assertNull($this->courseRow($course->id));
    }

    /** @test */
    public function a_visible_course_is_never_safe_even_when_empty(): void
    {
        $course = Course::factory()->create([
            'title' => 'Грамматика по Кочергиной гр.59',
            'slug' => 'kocergina-gr59-test-only',
            'is_visible' => true,
        ]);

        $row = $this->courseRow($course->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['safe']);
        $this->assertStringContainsString('виден на витрине', implode(' ', $row['blockers']));
    }

    /** @test */
    public function an_enrolled_student_without_a_twin_course_blocks_deletion(): void
    {
        $course = Course::factory()->create([
            'title' => 'Одинокая оболочка 2026',
            'slug' => 'odinokaia-obolocka-test-only',
            'is_visible' => false,
        ]);
        $user = User::factory()->create();
        $user->courses()->attach($course, ['status' => 'Записался']);

        $row = $this->courseRow($course->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['safe'], 'для этого студента курс — единственная запись');
        $this->assertStringContainsString('без близнеца', implode(' ', $row['blockers']));
    }

    /** @test */
    public function the_panini_shape_is_safe_when_every_student_also_holds_the_twin(): void
    {
        // Живая форма: «Караки по Панини (2025)» с содержимым и пустой дубль
        // «Караки по Панини 2025-2026 в записи», где записаны те же люди.
        $real = Course::factory()->create([
            'title' => 'Караки по Панини (2025)',
            'slug' => 'karaki-po-panini-2025-test-only',
            'is_visible' => false,
        ]);
        Lesson::factory()->for($real)->create();

        $shell = Course::factory()->create([
            'title' => 'Караки по Панини 2025-2026 в записи',
            'slug' => 'karaki-po-panini-2025-2026-v-zapisi-test-only',
            'is_visible' => false,
        ]);

        $user = User::factory()->create();
        $user->courses()->attach($real, ['status' => 'Записался']);
        $user->courses()->attach($shell, ['status' => 'Записался']);

        $this->assertNull($this->courseRow($real->id), 'настоящий курс — не оболочка');

        $row = $this->courseRow($shell->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['safe'], 'все записанные держат близнеца — ничего не теряется');
        $this->assertSame(1, $row['enrolled']);
    }

    /** @test */
    public function an_empty_group_is_listed_and_a_populated_one_is_not(): void
    {
        $empty = Group::create(['name' => 'Пустая группа']);
        $populated = Group::create(['name' => 'Живая группа']);
        $populated->users()->attach(User::factory()->create());

        $ids = array_column($this->audit()->groups(), 'id');

        $this->assertContains($empty->id, $ids);
        $this->assertNotContains($populated->id, $ids);
    }

    /** @test */
    public function a_group_holding_a_schedule_is_not_empty(): void
    {
        $group = Group::create(['name' => 'С расписанием']);
        Schedule::create([
            'title' => 'Занятие',
            'group_id' => $group->id,
            'start' => Carbon::parse('2026-09-01 12:00'),
            'end' => Carbon::parse('2026-09-01 13:00'),
        ]);

        $this->assertNotContains($group->id, array_column($this->audit()->groups(), 'id'));
    }

    /** @test */
    public function the_command_reports_without_deleting_anything(): void
    {
        $shell = Course::factory()->create([
            'title' => 'Саравали 2026',
            'slug' => 'saravali-2026-test-only',
            'is_visible' => false,
        ]);
        $emptyGroup = Group::create(['name' => 'Пустая']);

        $this->artisan('catalog:audit-shells')
            ->expectsOutputToContain('Ничего не удалено.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('courses', ['id' => $shell->id]);
        $this->assertDatabaseHas('groups', ['id' => $emptyGroup->id]);
    }

    /** @test */
    public function a_course_holding_a_certificate_is_not_a_shell(): void
    {
        // Сертификат — ровно та «запись», потерю которой MG запретил. Он живёт
        // в таблице, которой не было в первой, ручной версии проверок.
        $course = Course::factory()->create(['is_visible' => false]);
        DB::table('certificates')->insert([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'number' => 'TEST-1',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->courseRow($course->id), 'курс с сертификатом не оболочка');
    }

    /** @test */
    public function a_course_holding_a_tariff_is_not_a_shell(): void
    {
        $course = Course::factory()->create(['is_visible' => false]);
        Tariff::factory()->for($course)->create();

        $this->assertNull($this->courseRow($course->id));
    }
}
