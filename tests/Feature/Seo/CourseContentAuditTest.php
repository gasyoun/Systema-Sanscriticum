<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H7094 — факт-лист и детектор дублей описаний.
 *
 * Проверяем ровно то, на что опирается редактура: факты берутся из БД (не
 * выдумываются), очередь сортируется по оплатам, strict-гейт падает на пустом
 * и на побайтово одинаковом описании.
 */
class CourseContentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_facts_command_reports_real_course_facts(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Уша Санка']);
        $course = Course::factory()->create([
            'title' => 'Гимн Ганеше',
            'slug' => 'gimn-ganeshe',
            'teacher_id' => $teacher->id,
            'hours_count' => 12,
            'description' => null,
        ]);

        Lesson::factory()->count(2)->create([
            'course_id' => $course->id,
            'block_number' => 1,
            'lesson_date' => '2024-10-27',
        ]);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => 2,
            'lesson_date' => '2024-11-10',
        ]);
        Tariff::factory()->create([
            'course_id' => $course->id,
            'title' => 'Весь курс целиком',
            'price' => 6000,
        ]);

        $this->artisan('catalog:seo-content-facts', ['slug' => 'gimn-ganeshe'])
            ->expectsOutputToContain('Гимн Ганеше')
            ->expectsOutputToContain('Уша Санка')
            ->expectsOutputToContain('Весь курс целиком')
            ->expectsOutputToContain('27.10.2024')
            ->expectsOutputToContain('10.11.2024')
            ->assertSuccessful();
    }

    public function test_unknown_slug_fails_without_guessing(): void
    {
        $this->artisan('catalog:seo-content-facts', ['slug' => 'nope'])
            ->assertExitCode(1);
    }

    public function test_queue_is_ordered_by_payments_and_honours_limit(): void
    {
        $cheap = Course::factory()->create(['slug' => 'cheap', 'description' => null]);
        $rich = Course::factory()->create(['slug' => 'rich', 'description' => null]);

        $user = User::factory()->create();

        foreach ([1, 2] as $i) {
            Payment::create([
                'course_id' => $cheap->id,
                'user_id' => $user->id,
                'amount' => 1000,
                'tariff' => 'block_1',
                'status' => 'paid',
                'created_at' => '2026-08-0'.$i.' 10:00:00',
            ]);
        }

        foreach ([1, 2, 3, 4] as $i) {
            Payment::create([
                'course_id' => $rich->id,
                'user_id' => $user->id,
                'amount' => 1000,
                'tariff' => 'block_1',
                'status' => 'paid',
                'created_at' => '2026-08-0'.$i.' 10:00:00',
            ]);
        }

        Artisan::call('catalog:seo-content-facts', ['--limit' => 1, '--format' => 'json']);
        $facts = json_decode(Artisan::output(), true);

        $this->assertIsArray($facts);
        $this->assertCount(1, $facts);
        $this->assertSame('rich', $facts[0]['slug']);
        $this->assertSame(4, $facts[0]['payments']);
        $this->assertSame(1, $facts[0]['rank']);
    }

    public function test_audit_strict_passes_on_clean_descriptions(): void
    {
        Course::factory()->create(['description' => str_repeat('Полное описание курса. ', 40)]);

        $this->artisan('catalog:audit-seo-content', ['--strict' => true])
            ->assertSuccessful();
    }

    public function test_audit_strict_fails_on_empty_description(): void
    {
        Course::factory()->create(['slug' => 'empty-one', 'description' => null]);

        $this->artisan('catalog:audit-seo-content', ['--strict' => true])
            ->expectsOutputToContain('empty-one')
            ->assertExitCode(1);
    }

    public function test_audit_groups_identical_descriptions_once(): void
    {
        $text = str_repeat('Одинаковый текст описания. ', 40);

        Course::factory()->create(['slug' => 'twin-a', 'description' => $text]);
        Course::factory()->create(['slug' => 'twin-b', 'description' => $text]);
        Course::factory()->create(['slug' => 'solo', 'description' => str_repeat('Другой текст. ', 40)]);

        Artisan::call('catalog:audit-seo-content', ['--json' => true]);
        $findings = json_decode(Artisan::output(), true);

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings['identical']);
        $this->assertSame(['twin-a', 'twin-b'], $findings['identical'][0]);
    }
}
