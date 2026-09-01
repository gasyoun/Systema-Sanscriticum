<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CatalogFamilyAudit;
use App\Services\CatalogShellAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Курируемая продажа по прямой ссылке: скрытый курс, который ПРОДАЁТСЯ
 * (H3812 / H3820).
 *
 * Инцидент 31-08-2026. Сессия увидела курс 327 («Йога-сутры Патанджали … в
 * записи»): скрыт с витрины, в семье назван `duplicate`, — и погасила пять его
 * активных тарифов, рассуждая «скрытый курс продаться не может». Вывод ложный:
 * `/checkout/{tariff}` связывает ТАРИФ и никогда не читает `Course.is_visible`,
 * поэтому продажу держит только `tariffs.is_active`. Так школа и работает —
 * MG 31-08-2026: «Students can still buy recordings, but also for limited time.
 * Only when curators trust a student, can they send a direct link and it will
 * work.» Тарифы восстановили на проде в тот же проход.
 *
 * Почему восемь зелёных тестов исходной команды инцидент не поймали: все восемь
 * проверяли половину контракта про ДОСТУП (`Tariff::accessKey()`,
 * `Lesson::isUnlockedBy()`) и ни один — половину про ПОКУПКУ. Здесь пришита
 * именно вторая: боевая форма курса 327 и то, что оба аудита каталога о ней
 * говорят.
 *
 * Тест НИЧЕГО не гасит и не удаляет: он про то, что аудит НЕ зовёт прибрать.
 */
class CuratorGatedHiddenSaleTest extends TestCase
{
    use RefreshDatabase;

    /** Живой поток-близнец: витрина, блок, активный тариф. */
    private function liveTwin(): Course
    {
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025)',
            'slug' => 'ys-327-live-test',
            'is_visible' => true,
            'is_active' => true,
        ]);

        CourseBlock::factory()->for($course)->create(['number' => 1]);
        Tariff::factory()->for($course)->create();

        return $course;
    }

    /**
     * Боевая форма курса 327, снятая с прода 31-08-2026: скрыт с витрины,
     * `is_active`, 5 активных тарифов, НОЛЬ уроков и НОЛЬ материалов, группа 65
     * с 43 участниками, 129 оплат с 02-06-2025 по 28-12-2025.
     *
     * Числа взяты боевые, а не удобные: именно сочетание «пусто в уроках, но
     * густо в оплатах» и делает эту строку похожей на мусор для любого аудита,
     * который считает содержимое, а не продаваемость.
     */
    private function course327(): Course
    {
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи',
            'slug' => 'ys-327-v-zapisi-test',
            'is_visible' => false,
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            Tariff::factory()->for($course)->create(['is_active' => true]);
        }

        $group = Group::factory()->create(['name' => 'Йога-сутры, запись']);
        $course->groups()->attach($group->id);

        $buyers = User::factory()->count(43)->create();
        $group->users()->attach($buyers->pluck('id')->all());

        // 129 оплат в окне 02-06-2025 … 28-12-2025 — курс продавали полгода.
        for ($i = 0; $i < 129; $i++) {
            Payment::create([
                'user_id' => $buyers[$i % 43]->id,
                'course_id' => $course->id,
                'amount' => 24000,
                'tariff' => 'full',
                'status' => 'paid',
                'first_paid_at' => $i === 0
                    ? '2025-06-02 10:00:00'
                    : ($i === 128 ? '2025-12-28 10:00:00' : '2025-09-15 10:00:00'),
            ]);
        }

        return $course;
    }

    /** @test */
    public function the_measured_shape_of_course_327_is_what_the_audits_are_shown(): void
    {
        $course = $this->course327();

        $this->assertFalse((bool) $course->is_visible, 'скрыт с витрины');
        $this->assertTrue((bool) $course->is_active, 'но сам курс активен');
        $this->assertSame(5, $course->tariffs()->where('is_active', true)->count());
        $this->assertSame(0, $course->lessons()->count(), 'ни одного урока');
        $this->assertSame(0, DB::table('course_materials')->where('course_id', $course->id)->count());
        $this->assertSame(43, $course->groups()->first()->users()->count());
        $this->assertSame(129, $course->payments()->paid()->count());
    }

    /** @test */
    public function a_hidden_course_with_active_tariffs_is_a_curator_gated_sale_not_a_shell(): void
    {
        $course = $this->course327();

        $audit = app(CatalogShellAudit::class);

        $this->assertTrue(
            $audit->isCuratorGatedSale($course),
            'скрытый + is_active + активные тарифы = продажа по прямой ссылке куратора',
        );
        $this->assertFalse(
            $audit->isShellCourse($course),
            'оболочкой курируемая продажа не бывает — и сказано это явно, а не через пересчёт таблиц',
        );

        $row = $audit->courseRow($course);

        $this->assertTrue($row['curator_gated_sale'], 'вердикт по одному курсу несёт маркер');
        $this->assertFalse($row['safe'], 'такую строку нельзя называть безопасной к удалению');
        $this->assertStringContainsString('ПРОДАЁТСЯ по прямой ссылке куратора', implode(' ', $row['blockers']));
        $this->assertStringContainsString('гейт ПОКУПКИ, а не доступа', implode(' ', $row['blockers']));
    }

    /** @test */
    public function a_visible_course_with_active_tariffs_is_not_a_curator_gated_sale(): void
    {
        // Контроль: маркер обязан отделять СКРЫТУЮ продажу от обычной витрины,
        // иначе он бы просто пометил весь каталог и ничего не сказал.
        $live = $this->liveTwin();

        $this->assertFalse(app(CatalogShellAudit::class)->isCuratorGatedSale($live));
    }

    /** @test */
    public function a_hidden_course_whose_tariffs_are_all_off_is_not_a_curator_gated_sale(): void
    {
        // Второй контроль — та самая граница, которую и перешёл инцидент:
        // продажу держат ТАРИФЫ. Погашены все — продажи нет, и маркер гаснет.
        $course = Course::factory()->create([
            'title' => 'Йога-сутры Патанджали (1 поток, 2025) в записи',
            'slug' => 'ys-327-off-test',
            'is_visible' => false,
            'is_active' => true,
        ]);
        Tariff::factory()->for($course)->create(['is_active' => false]);

        $this->assertFalse(app(CatalogShellAudit::class)->isCuratorGatedSale($course));
    }

    /** @test */
    public function the_family_audit_marks_course_327_as_a_curator_gated_sale(): void
    {
        $live = $this->liveTwin();
        $course = $this->course327();

        $row = null;
        foreach (app(CatalogFamilyAudit::class)->report() as $candidate) {
            if (in_array($course->id, array_column($candidate['members'], 'id'), true)) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertContains($live->id, array_column($row['members'], 'id'), 'живой поток и запись — одна семья');
        $this->assertContains(
            CatalogFamilyAudit::CLASS_CURATOR_GATED_SALE,
            $row['classes'],
            'семья названа курируемой продажей, а не просто дублем',
        );

        $member = collect($row['members'])->firstWhere('id', $course->id);
        $this->assertTrue($member['curator_gated_sale']);
        $this->assertSame(5, $member['active_tariffs']);
    }

    /** @test */
    public function the_family_audit_never_invites_retiring_a_course_that_still_sells(): void
    {
        // Ровно тот текст, по которому и пошёл инцидент: скрытая строка семьи
        // получала совет «прибираться по желанию через `catalog:retire-shell`».
        // Для курируемой продажи он ЗАМЕЩАЕТСЯ запретом.
        $this->liveTwin();
        $course = $this->course327();

        $row = null;
        foreach (app(CatalogFamilyAudit::class)->report() as $candidate) {
            if (in_array($course->id, array_column($candidate['members'], 'id'), true)) {
                $row = $candidate;
            }
        }

        $this->assertNotNull($row);
        $this->assertNotNull($row['follow_up']);
        $this->assertStringNotContainsString(
            'Прибираться по желанию',
            $row['follow_up'],
            'приглашение прибрать строку, которая продаётся, — ровно тот текст, по которому и погасили тарифы',
        );
        $this->assertStringContainsString('НЕ ТРОГАТЬ', $row['follow_up']);
        $this->assertStringContainsString('Ни `catalog:retire-shell`', $row['follow_up'], 'команда названа ЗАПРЕЩЁННОЙ, а не предложена');
        $this->assertStringContainsString('не читает `Course.is_visible`', $row['follow_up']);
    }

    /** @test */
    public function the_audits_change_no_catalog_state(): void
    {
        // H3812 пишет ПРАВИЛА и не трогает каталог. Пять тарифов обязаны
        // пережить оба прогона — это и есть проверка «аудит только читает».
        $this->liveTwin();
        $course = $this->course327();

        app(CatalogFamilyAudit::class)->report();
        app(CatalogShellAudit::class)->report();

        $this->assertSame(5, $course->fresh()->tariffs()->where('is_active', true)->count());
        $this->assertFalse((bool) $course->fresh()->is_visible);
        $this->assertTrue((bool) $course->fresh()->is_active);
    }
}
