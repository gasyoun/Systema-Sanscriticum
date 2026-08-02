<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Services\LessonMaterialTagger;
use App\Services\MilestoneCertificateIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonMaterialTaggerTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private CertificateMilestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->course = Course::factory()->create();
        $this->group = Group::create(['name' => 'Поток', 'status' => 'active']);
        $this->course->groups()->attach($this->group->id);

        $this->milestone = CertificateMilestone::create([
            'course_id' => $this->course->id,
            'title' => 'Сандхи по Эмено',
            'trigger_type' => CertificateMilestone::TRIGGER_MATERIAL,
            'trigger_lesson' => 2,
            'material_tag' => 'emeno',
            'material_keywords' => 'Эмено, Emeneau',
        ]);
    }

    /** Урок со стенограммой в формате Deepgram из заданных слов. */
    private function lessonWithTranscript(array $words, int $daysAgo = 1): Lesson
    {
        $lesson = Lesson::create([
            'title' => 'Занятие',
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'lesson_date' => today()->subDays($daysAgo),
        ]);

        $payload = ['results' => ['channels' => [['alternatives' => [['words' => array_map(
            fn (string $w, int $i) => ['word' => $w, 'punctuated_word' => $w, 'start' => $i, 'end' => $i + 1],
            $words,
            array_keys($words),
        )]]]]]];

        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('public')->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        return $lesson->fresh();
    }

    /** @test */
    public function it_tags_lessons_with_enough_keyword_mentions(): void
    {
        $lesson = $this->lessonWithTranscript([
            'Сегодня', 'разбираем', 'Эмено.', 'Правила', 'Эмено', 'важны,', 'читаем', 'Emeneau', 'дальше.',
        ]);

        app(LessonMaterialTagger::class)->tagLesson($lesson);

        $lesson->refresh();
        $this->assertSame('emeno', $lesson->material_tag);
        $this->assertSame('auto', $lesson->material_tag_source);
    }

    /** @test */
    public function it_ignores_lessons_below_the_mention_threshold(): void
    {
        // Два упоминания < порога (3): случайная отсылка, не тема занятия.
        $lesson = $this->lessonWithTranscript([
            'Вспомним', 'Эмено', 'мельком', 'и', 'ещё', 'раз', 'Эмено', 'и', 'хватит.',
        ]);

        app(LessonMaterialTagger::class)->tagLesson($lesson);

        $this->assertNull($lesson->fresh()->material_tag);
    }

    /** @test */
    public function manual_tags_are_never_overwritten(): void
    {
        $lesson = $this->lessonWithTranscript(['Эмено', 'Эмено', 'Эмено']);
        $lesson->forceFill(['material_tag' => 'buhler', 'material_tag_source' => 'manual'])->save();

        app(LessonMaterialTagger::class)->tagLesson($lesson->fresh());

        $this->assertSame('buhler', $lesson->fresh()->material_tag);
        $this->assertSame('manual', $lesson->fresh()->material_tag_source);
    }

    /** @test */
    public function material_milestone_fires_on_the_nth_tagged_lesson(): void
    {
        $tagger = app(LessonMaterialTagger::class);
        $issuer = app(MilestoneCertificateIssuer::class);

        $first = $this->lessonWithTranscript(['Эмено', 'Эмено', 'Эмено'], daysAgo: 3);
        $tagger->tagLesson($first);
        $this->assertCount(0, $issuer->dueTargets()); // 1 занятие < порога 2

        $second = $this->lessonWithTranscript(['Emeneau', 'Emeneau', 'Эмено'], daysAgo: 1);
        $tagger->tagLesson($second);

        $targets = $issuer->dueTargets();
        $this->assertCount(1, $targets);
        $this->assertTrue($targets->first()->milestone->is($this->milestone));
    }

    /** @test */
    public function catch_up_is_idempotent(): void
    {
        $this->lessonWithTranscript(['Эмено', 'Эмено', 'Эмено']);

        $tagger = app(LessonMaterialTagger::class);
        $this->assertSame(1, $tagger->catchUp());
        $this->assertSame(0, $tagger->catchUp()); // уже размечен — второй проход пуст
    }
}
