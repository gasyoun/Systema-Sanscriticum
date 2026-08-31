<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\CatalogFamilyAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Вердикт по семьям каталога (H3773, остаток H3122).
 *
 * Проверки бьют в одну точку: аудит обязан отличать НАСТОЯЩИЙ поток от осевшего
 * дубля. Ложный `streams` прячет дубль от витрины навсегда, поэтому большая
 * часть тестов — про то, что аудит НЕ называет потоком.
 *
 * Боевой контроль, ради которого порог и выбран: «Кашмирский шиваизм»
 * (332/375/424) — три строки одной программы, ни одну удалять нельзя, вердикт
 * обязан быть `streams`; «Караки по Панини 2025-2026 в записи» при живом курсе
 * — `duplicate`.
 */
class CatalogFamilyAuditTest extends TestCase
{
    use RefreshDatabase;

    private function audit(): CatalogFamilyAudit
    {
        return app(CatalogFamilyAudit::class);
    }

    /** @return array<string, mixed>|null */
    private function familyContaining(int $courseId): ?array
    {
        foreach ($this->audit()->report() as $row) {
            if (in_array($courseId, array_column($row['members'], 'id'), true)) {
                return $row;
            }
        }

        return null;
    }

    /** Живой поток: свои блоки и активный тариф. */
    private function liveCourse(string $title, string $slug): Course
    {
        $course = Course::factory()->create([
            'title' => $title,
            'slug' => $slug,
            'is_visible' => true,
        ]);

        CourseBlock::factory()->for($course)->create(['number' => 1]);
        Tariff::factory()->for($course)->create();

        return $course;
    }

    private function paidPayment(Course $course, string $paidAt): void
    {
        Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
            'first_paid_at' => $paidAt,
        ]);
    }

    /** @test */
    public function a_lone_course_is_unique(): void
    {
        $course = $this->liveCourse('Ведический санскрит', 'vedicheskij-sanskrit-test');

        $row = $this->familyContaining($course->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_UNIQUE, $row['verdict']);
        $this->assertSame([], $row['reasons']);
        $this->assertNull($row['follow_up']);
    }

    /** @test */
    public function numbered_streams_of_one_programme_are_streams_not_duplicates(): void
    {
        // Боевой контроль 332/375/424: три потока одной программы.
        $first = $this->liveCourse('Кашмирский шиваизм (1 поток, 2024)', 'kasmirskii-sivaizm-2024-test');
        $second = $this->liveCourse('Кашмирский шиваизм (2 поток, 2025)', 'kasmirskii-sivaizm-2025-test');
        $third = $this->liveCourse('Кашмирский шиваизм (3 поток, 2026)', 'kasmirskii-sivaizm-2026-test');

        $row = $this->familyContaining($second->id);

        $this->assertNotNull($row);
        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($row['members'], 'id'),
            'все три потока встали в одну семью',
        );
        $this->assertSame(CatalogFamilyAudit::VERDICT_STREAMS, $row['verdict']);
        $this->assertSame([], $row['reasons']);
    }

    /** @test */
    public function an_empty_twin_of_a_live_course_is_a_duplicate(): void
    {
        // «Караки по Панини»: 335 живой, 421 — пустая копия «в записи».
        $live = $this->liveCourse('Караки по Панини', 'karaki-po-panini-test');
        $shell = Course::factory()->create([
            'title' => 'Караки по Панини 2025-2026 в записи',
            'slug' => 'karaki-po-panini-2025-2026-v-zapisi-test',
            'is_visible' => true,
        ]);

        $row = $this->familyContaining($shell->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict']);
        $this->assertContains($live->id, array_column($row['members'], 'id'));
        $this->assertStringContainsString('ни блоков, ни активных тарифов, ни оплат', implode(' ', $row['reasons']));
        $this->assertNotNull($row['follow_up']);
    }

    /** @test */
    public function a_recording_stream_with_payments_but_no_blocks_is_still_a_stream(): void
    {
        // Курс-запись: ни блоков, ни тарифов — но оплаты есть, значит это
        // настоящий поток, а не пустая копия. Роль `recording`.
        $live = $this->liveCourse('Мегхадута Калидасы (1 поток, 2025)', 'megxaduta-1-potok-test');
        $this->paidPayment($live, '2025-03-01 10:00:00');

        $recording = Course::factory()->create([
            'title' => 'Мегхадута Калидасы (2 поток, 2026)',
            'slug' => 'megxaduta-2-potok-test',
        ]);
        $this->paidPayment($recording, '2026-03-01 10:00:00');

        $row = $this->familyContaining($recording->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_STREAMS, $row['verdict']);

        $member = collect($row['members'])->firstWhere('id', $recording->id);
        $this->assertSame('recording', $member['role']);
    }

    /** @test */
    public function two_courses_claiming_the_same_stream_number_are_a_duplicate(): void
    {
        $a = $this->liveCourse('Пранаяма (1 поток, 2025)', 'pranayama-1-a-test');
        $b = $this->liveCourse('Пранаяма (1 поток, 2025)', 'pranayama-1-b-test');

        $row = $this->familyContaining($a->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict']);
        $this->assertStringContainsString('неотличимы как потоки', implode(' ', $row['reasons']));
        $this->assertStringContainsString((string) $b->id, implode(' ', $row['reasons']));
    }

    /** @test */
    public function unnumbered_streams_are_split_by_the_first_payment_date(): void
    {
        // Без номера в названии потоки разводит дата первого платежа — иначе
        // два живых потока схлопнулись бы в ложный duplicate.
        $a = $this->liveCourse('Йога-сутры в записи', 'yoga-sutry-a-test');
        $b = $this->liveCourse('Йога-сутры в записи', 'yoga-sutry-b-test');
        $this->paidPayment($a, '2025-02-01 10:00:00');
        $this->paidPayment($b, '2026-02-01 10:00:00');

        $row = $this->familyContaining($a->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_STREAMS, $row['verdict']);
    }

    /** @test */
    public function a_manual_course_family_wins_over_the_title(): void
    {
        // Человек прав по определению: заполненный course_family связывает
        // курсы, названия которых автомат в одну семью не сводит.
        $a = $this->liveCourse('Введение в грамматику', 'vvedenie-grammatika-test');
        $b = $this->liveCourse('Пропедевтика санскрита', 'propedevtika-test');

        $a->update(['course_family' => 'grammatika-vvodnyj']);
        $b->update(['course_family' => 'grammatika-vvodnyj']);

        $row = $this->familyContaining($a->id);

        $this->assertNotNull($row);
        $this->assertSame('grammatika-vvodnyj', $row['family']);
        $this->assertSame([$a->id, $b->id], array_column($row['members'], 'id'));

        $member = collect($row['members'])->firstWhere('id', $a->id);
        $this->assertTrue($member['manual_family']);
    }

    /** @test */
    public function evidence_carries_urls_tariff_keys_and_counts(): void
    {
        // «Fail =: вердикты без колонок доказательств» — проверяем, что каждая
        // из них действительно заполнена.
        $course = $this->liveCourse('Пали для начинающих', 'pali-dlya-nachinayushhix-test');
        $this->paidPayment($course, '2026-01-15 10:00:00');

        $member = $this->familyContaining($course->id)['members'][0];

        $this->assertSame('/k/pali-dlya-nachinayushhix-test', $member['url']);
        $this->assertSame(['full'], $member['tariff_keys']);
        $this->assertSame(1, $member['blocks']);
        $this->assertSame(1, $member['active_tariffs']);
        $this->assertSame(1, $member['paid_payments']);
        $this->assertSame('2026-01-15', $member['first_paid_at']);
        $this->assertSame('live', $member['role']);
    }

    /** @test */
    public function an_inactive_tariff_does_not_make_a_shell_look_live(): void
    {
        $live = $this->liveCourse('Ньяя-сутры', 'nyaya-sutry-test');
        $shell = Course::factory()->create([
            'title' => 'Ньяя-сутры в записи',
            'slug' => 'nyaya-sutry-v-zapisi-test',
        ]);
        Tariff::factory()->for($shell)->create(['is_active' => false]);

        $row = $this->familyContaining($shell->id);

        $this->assertNotNull($row);
        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $row['verdict']);
        $this->assertContains($live->id, array_column($row['members'], 'id'));

        $member = collect($row['members'])->firstWhere('id', $shell->id);
        $this->assertSame(0, $member['active_tariffs']);
        $this->assertSame('unknown', $member['role']);
    }

    /** @test */
    public function every_course_lands_in_exactly_one_family_row(): void
    {
        $this->liveCourse('Кашмирский шиваизм (1 поток, 2024)', 'ks-1-test');
        $this->liveCourse('Кашмирский шиваизм (2 поток, 2025)', 'ks-2-test');
        $this->liveCourse('Ведический санскрит', 'vs-test');

        $rows = $this->audit()->report();
        $ids = array_merge(...array_map(fn (array $r) => array_column($r['members'], 'id'), $rows));

        $this->assertSame(Course::count(), count($ids), 'ни один курс не потерян и не посчитан дважды');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /** @test */
    public function the_audit_writes_nothing(): void
    {
        $course = $this->liveCourse('Аштадхьяи', 'ashtadhyayi-test');
        $before = Course::query()->orderBy('id')->get()->toArray();
        $tariffsBefore = Tariff::query()->orderBy('id')->get()->toArray();

        $this->audit()->report();

        $this->assertEquals($before, Course::query()->orderBy('id')->get()->toArray());
        $this->assertEquals($tariffsBefore, Tariff::query()->orderBy('id')->get()->toArray());
        $this->assertNull($course->fresh()->course_family, 'аудит не проставляет семью — он только читает');
    }

    /** @test */
    public function the_command_prints_a_markdown_report(): void
    {
        $this->liveCourse('Караки по Панини', 'karaki-cmd-test');
        Course::factory()->create([
            'title' => 'Караки по Панини 2025-2026 в записи',
            'slug' => 'karaki-cmd-shell-test',
        ]);

        // Не `expectsOutputToContain`: он ВЫЧЁРКИВАЕТ совпавшую строку вывода,
        // а отчёт печатается одним вызовом `line()` — второе ожидание тогда
        // ищет в пустоте и падает на верном выводе. Читаем буфер целиком.
        $this->assertSame(0, Artisan::call('catalog:audit-families', ['--markdown' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('# Аудит каталога: семьи курсов', $out);
        $this->assertStringContainsString('duplicate', $out);
        $this->assertStringContainsString('`karaki-cmd-shell-test`', $out, 'дубль назван в таблице');
        $this->assertStringContainsString('/k/karaki-cmd-test', $out, 'колонка доказательств несёт URL');
        $this->assertStringContainsString('_Dr. Mārcis Gasūns_', $out);
    }

    /** @test */
    public function the_duplicate_verdict_sorts_before_streams_and_unique(): void
    {
        $this->liveCourse('Кашмирский шиваизм (1 поток, 2024)', 'sort-ks-1-test');
        $this->liveCourse('Кашмирский шиваизм (2 поток, 2025)', 'sort-ks-2-test');
        $this->liveCourse('Караки по Панини', 'sort-karaki-test');
        Course::factory()->create(['title' => 'Караки по Панини в записи', 'slug' => 'sort-karaki-shell-test']);
        $this->liveCourse('Ведический санскрит', 'sort-vs-test');

        $verdicts = array_column($this->audit()->report(), 'verdict');

        $this->assertSame(CatalogFamilyAudit::VERDICT_DUPLICATE, $verdicts[0]);
        $this->assertSame(CatalogFamilyAudit::VERDICT_UNIQUE, end($verdicts));
    }
}
