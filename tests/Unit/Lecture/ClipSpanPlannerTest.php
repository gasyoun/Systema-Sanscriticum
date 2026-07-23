<?php

declare(strict_types=1);

namespace Tests\Unit\Lecture;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Lecture\ClipSpanPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClipSpanPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_existing_timecodes_without_recomputing(): void
    {
        Storage::fake('public');
        $path = 'transcripts/planner.json';
        Storage::disk('public')->put($path, json_encode([
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'А', 'punctuated_word' => 'А.', 'start' => 0.0, 'end' => 5.0],
                            ['word' => 'Б', 'punctuated_word' => 'Б.', 'start' => 40.0, 'end' => 50.0],
                            ['word' => 'В', 'punctuated_word' => 'В.', 'start' => 95.0, 'end' => 100.0],
                            ['word' => 'Г', 'punctuated_word' => 'Г.', 'start' => 140.0, 'end' => 150.0],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $lesson = Lesson::factory()->for(Course::factory())->create([
            'transcript_file' => $path,
            'topic' => 'Тема',
            'title' => 'Лекция',
        ]);

        $spans = ClipSpanPlanner::planSpans($lesson, targetSeconds: 90, maxSpans: 12);

        $this->assertNotEmpty($spans);
        foreach ($spans as $span) {
            $this->assertArrayHasKey('start_seconds', $span);
            $this->assertArrayHasKey('end_seconds', $span);
            $this->assertArrayHasKey('title', $span);
            $this->assertGreaterThan($span['start_seconds'], $span['end_seconds']);
            $this->assertStringContainsString('Тема', $span['title']);
        }
    }

    public function test_empty_transcript_yields_no_spans(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create(['transcript_file' => null]);
        $this->assertSame([], ClipSpanPlanner::planSpans($lesson));
    }
}
