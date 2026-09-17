<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\CourseStreamComparisonExport;
use App\Filament\Pages\AttendanceDashboard;
use App\Models\Group;
use App\Models\Lead;
use App\Models\Schedule;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * H5086 — регресс formula injection в четырёх выгрузках (находка H5046
 * csv-export-formula-injection): crafted лид/ответ анкеты/имя студента
 * должен приехать в файл нейтрализованным (ведущий апостроф), без живых
 * байтов формулы.
 */
final class CsvFormulaInjectionExportTest extends TestCase
{
    use RefreshDatabase;

    /** Тело streamed-ответа: фича-тест не отправляет контент — дожимаем сами. */
    private function streamedBody(TestResponse $response): string
    {
        /** @var StreamedResponse $base */
        $base = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $base);

        ob_start();
        $base->sendContent();

        return (string) ob_get_clean();
    }

    /** @test */
    public function crafted_lead_yields_escaped_cell_bytes_in_leads_export(): void
    {
        Lead::create([
            'name' => '=1+1',
            'contact' => '+79990000000',
            'email' => 'clean@example.com',
            'utm_term' => '@SUM(1)',
            'referrer' => '-REF',
            'user_agent' => "\tEvilUA",
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $response = $this->actingAs($admin)->get('/admin/leads/export');
        $response->assertOk();

        $csv = $this->streamedBody($response);

        // Нейтрализованные байты на месте…
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'+79990000000", $csv);
        $this->assertStringContainsString("'@SUM(1)", $csv);
        $this->assertStringContainsString("'-REF", $csv);
        $this->assertStringContainsString("'\tEvilUA", $csv);
        // …и живых формульных ячеек нет.
        $this->assertStringNotContainsString(';=1+1', $csv);
        $this->assertStringNotContainsString(';+79990000000', $csv);
        // Чистое значение не тронуто.
        $this->assertStringContainsString('clean@example.com', $csv);
    }

    /** @test */
    public function survey_export_escapes_formula_answers(): void
    {
        SurveyResponse::create([
            'survey_slug' => 'exit-price',
            'answers' => ['what_happened' => '=cmd|"/c calc"!A0'],
            'contact' => '@evil.tg',
            'reward_choice' => null,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $response = $this->actingAs($admin)->get('/admin/surveys/exit-price/export');
        $response->assertOk();

        $csv = $this->streamedBody($response);

        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringContainsString("'@evil.tg", $csv);
    }

    /** @test */
    public function attendance_export_escapes_student_name(): void
    {
        config(['features.attendance_dashboard' => true]);

        $group = Group::create(['name' => 'Группа F1']);
        $student = User::factory()->create(['name' => '=Индира+']);
        $group->users()->attach($student->id);

        $schedule = Schedule::create(['title' => 'Занятие', 'start' => now()->subDay(), 'group_id' => $group->id]);
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 'f1', 'joined_at' => now()->subDay(), 'duration_seconds' => 1800,
        ]);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);

        $method = new \ReflectionMethod(AttendanceDashboard::class, 'exportCsv');
        $method->setAccessible(true);
        /** @var StreamedResponse $stream */
        $stream = $method->invoke(new AttendanceDashboard);

        ob_start();
        $stream->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString("'=Индира+", $csv);
    }

    /** @test */
    public function course_stream_comparison_export_escapes_names_and_keeps_numbers(): void
    {
        $report = [
            'family' => 'testovyi-kurs',
            'family_title' => 'Тестовый курс',
            'attendance' => [
                'covered_users' => 0,
                'total_users' => 1,
                'coverage_ratio' => 0.0,
                'bought_all_never_watched' => [],
            ],
            'salary' => [
                'attribution_confirmed' => true,
                'remainder' => 0.0,
                'pending_total' => 0.0,
                'pending_candidates' => [],
            ],
            'streams' => [
                [
                    'title' => '=Поток-формула',
                    'role' => 'live',
                    'course_id' => 101,
                    'payers' => 1,
                    'revenue' => 3000,
                    'avg_check' => 3000,
                    'discount_total' => 0,
                    'accrued' => 1200,
                    'retention_first_to_last' => 80,
                    'blocks' => [['number' => 1, 'buyers' => 1, 'access' => 1, 'revenue' => 3000]],
                    'students' => [
                        ['id' => 7, 'name' => '@Отто фон', 'blocks' => [1 => true]],
                    ],
                ],
            ],
        ];

        $rows = (new CourseStreamComparisonExport($report))->array();

        $flat = collect($rows)->flatten()->all();
        $this->assertContains("'=Поток-формула", $flat, 'имя потока с = нейтрализовано');
        $this->assertContains("'@Отто фон", $flat, 'имя студента с @ нейтрализовано');
        $this->assertContains(3000, $flat, 'числовые ячейки остаются числами');
        $this->assertNotContains('=Поток-формула', $flat, 'живой формулы в файле нет');
    }
}
