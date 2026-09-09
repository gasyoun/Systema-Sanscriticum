<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\KanvaTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class KanvaTimingsIngestTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function validation_flags_gaps_and_out_of_order(): void
    {
        $valid = [
            ['start' => '00:00:00', 'end' => null, 'label' => 'a'],
            ['start' => '00:05:20', 'end' => null, 'label' => 'b'],
            ['start' => '00:16:46', 'end' => null, 'label' => 'c'],
        ];
        $this->assertSame('valid', KanvaTiming::validateTimings($valid));

        // Прыжок назад.
        $this->assertSame('gaps', KanvaTiming::validateTimings([
            ['start' => '00:16:46'], ['start' => '00:05:20'],
        ]));

        // Гэп > 5 часов.
        $this->assertSame('gaps', KanvaTiming::validateTimings([
            ['start' => '00:00:00'], ['start' => '06:00:00'],
        ]));

        // Одна метка — мало.
        $this->assertSame('gaps', KanvaTiming::validateTimings([['start' => '00:00:00']]));

        // Мусорный формат.
        $this->assertSame('gaps', KanvaTiming::validateTimings([
            ['start' => 'abc'], ['start' => '00:05'],
        ]));
    }

    /** @test */
    public function seconds_parser_handles_all_n8n_variants(): void
    {
        $this->assertSame(320, KanvaTiming::toSeconds('00:05:20'));
        $this->assertSame(4500, KanvaTiming::toSeconds('1:15:00'));
        $this->assertNull(KanvaTiming::toSeconds('1.15.00'));
    }

    /** @test */
    public function command_ingests_canonical_json_and_dedups(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.99', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $json = [
            'ingested' => 1,
            'sessions' => [[
                'video_url' => 'https://youtu.be/abc123',
                'lesson_prefix' => null,
                'items' => [
                    ['start' => '00:00:00', 'end' => '00:05:20', 'label' => 'Знакомство'],
                    ['start' => '00:05:20', 'end' => '00:16:46', 'label' => 'Вопросы «кья»'],
                ],
                'source' => 'n8n:mkct0W3oFHftaBah#2427',
            ]],
        ];
        $file = sys_get_temp_dir().'/kanva_timings_test.json';
        File::put($file, json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->artisan('kanva:ingest-timings', ['--file' => $file])->assertSuccessful();
        $this->assertSame(1, KanvaTiming::count());

        // Повтор — дедуп, второй записи нет.
        $this->artisan('kanva:ingest-timings', ['--file' => $file])->assertSuccessful();
        $this->assertSame(1, KanvaTiming::count());

        $timing = KanvaTiming::first();
        $this->assertSame($course->id, $timing->course_id);
        $this->assertSame('valid', $timing->valid_status);
        $this->assertSame('n8n:mkct0W3oFHftaBah#2427', $timing->source);
    }

    /** @test */
    public function ambiguous_courses_require_explicit_course_option(): void
    {
        // Две живые грамматики → без --course команда отказывается.
        foreach (['Грамматика по Кочергиной гр.71', 'Грамматика по Кочергиной гр.72'] as $title) {
            $course = Course::factory()->create(['title' => $title, 'is_active' => true, 'is_visible' => true]);
            $group = Group::factory()->create();
            $course->groups()->attach($group->id);
        }

        $json = ['sessions' => [[
            'video_url' => null,
            'items' => [['start' => '00:00:00'], ['start' => '00:05:00', 'label' => 'x']],
            'source' => 'n8n:x#1',
        ]]];
        $file = sys_get_temp_dir().'/kanva_timings_amb.json';
        File::put($file, json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->artisan('kanva:ingest-timings', ['--file' => $file])
            ->expectsOutputToContain('курс не определён')
            ->assertSuccessful();
        $this->assertSame(0, KanvaTiming::count());

        $target = Course::where('title', 'гр.71')->first() ?? Course::where('title', 'Грамматика по Кочергиной гр.71')->first();
        $this->artisan('kanva:ingest-timings', ['--file' => $file, '--course' => (string) $target->id])->assertSuccessful();
        $this->assertSame(1, KanvaTiming::count());
        $this->assertSame($target->id, KanvaTiming::first()->course_id);
    }

    /** @test */
    public function dry_run_does_not_write(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.80', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        $json = ['sessions' => [[
            'video_url' => 'https://youtu.be/dryrun',
            'items' => [['start' => '00:00:00'], ['start' => '00:05:00', 'label' => 'y']],
            'source' => 'n8n:dry#1',
        ]]];
        $file = sys_get_temp_dir().'/kanva_timings_dry.json';
        File::put($file, json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->artisan('kanva:ingest-timings', ['--file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, KanvaTiming::count());
    }
}
