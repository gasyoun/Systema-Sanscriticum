<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\HindiTranscriptDrills;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H2443 — Hindi transcript drills: flag, access, fixture items, playlist CTA.
 */
class HindiTranscriptDrillsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_flag_off_returns_404(): void
    {
        config(['features.hindi_transcript_drills' => false]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    public function test_guest_is_redirected(): void
    {
        config(['features.hindi_transcript_drills' => true]);
        [, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $this->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertRedirect();
    }

    public function test_student_can_run_fixture_cloze_and_check_answer(): void
    {
        config([
            'features.hindi_transcript_drills' => true,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $html = $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="hindi-transcript-drills"', false)
            ->assertSee('Упражнения', false)
            ->assertSee('Как по-русски: kitāb', false)
            ->assertSee('data-item-type="cloze"', false)
            ->assertSee('data-item-type="translate"', false)
            ->getContent();

        $this->assertStringContainsString('data-testid="hindi-drill-item"', $html);

        $items = app(HindiTranscriptDrills::class)->itemsFor($lesson);
        $this->assertNotSame([], $items);
        $translate = collect($items)->firstWhere('type', 'translate');
        $this->assertNotNull($translate);

        $this->actingAs($user)
            ->postJson(route('student.lesson.drills.check', [$course->slug, $lesson->id]), [
                'item_id' => $translate['id'],
                'answer' => 'Книга!',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'item_id' => $translate['id']]);

        $this->actingAs($user)
            ->postJson(route('student.lesson.drills.check', [$course->slug, $lesson->id]), [
                'item_id' => $translate['id'],
                'answer' => 'тетрадь',
            ])
            ->assertOk()
            ->assertJson(['ok' => false, 'correct_answer' => 'книга']);
    }

    public function test_sanskrit_lesson_is_not_served(): void
    {
        config(['features.hindi_transcript_drills' => true]);
        $sanskrit = Category::factory()->create(['slug' => 'sanskrit']);
        $course = Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-1']);
        $course->categories()->attach($sanskrit->id);
        $group = Group::create(['name' => 'Sa-1']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Санскрит 1',
            'is_published' => true,
            'is_free' => false,
            'block_number' => 1,
            'transcript_file' => $this->putFixture(),
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    public function test_locked_lesson_is_forbidden(): void
    {
        config(['features.hindi_transcript_drills' => true]);
        $hindi = Category::factory()->create(['slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-lock']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'H3-lock']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Закрытый блок 2',
            'sort_order' => 2,
            'block_number' => 2,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
            'transcript_file' => $this->putFixture(),
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'block_1',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]));

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertForbidden();
    }

    public function test_playlist_and_lesson_show_drills_entry_when_flag_on(): void
    {
        config([
            'features.hindi_transcript_drills' => true,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-playlist-drills"', false)
            ->assertSee(route('student.lesson.drills', [$course->slug, $lesson->id], false), false);

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="hindi-transcript-drills-cta"', false);
    }

    public function test_playlist_hides_drills_link_when_drills_flag_off(): void
    {
        config([
            'features.hindi_transcript_drills' => false,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user] = $this->seedHindiLessonWithFixture();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertDontSee('data-testid="hindi-playlist-drills"', false);
    }

    public function test_youtube_auto_ru_orig_source_yields_no_drills(): void
    {
        config(['features.hindi_transcript_drills' => true]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $path = 'transcripts/youtube_auto.json';
        Storage::disk('public')->put($path, json_encode([
            'metadata' => ['source' => 'youtube-auto-ru-orig'],
            'results' => [
                'channels' => [[
                    'alternatives' => [[
                        'words' => [
                            ['word' => 'नमस्ते', 'punctuated_word' => 'नमस्ते', 'start' => 0.1, 'end' => 0.4],
                            ['word' => 'kitāb', 'punctuated_word' => 'kitāb.', 'start' => 0.4, 'end' => 0.8],
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        $this->assertSame([], app(\App\Services\HindiTranscriptDrills::class)->itemsFor($lesson));
    }

    public function test_english_classroom_loans_are_not_drill_targets(): void
    {
        $extractor = new \App\Services\HindiTranscriptDrillExtractor();
        $items = $extractor->extract([
            [
                'text' => 'Я тут вижу пользователя Zoom и ссылку в Google документ.',
                'start' => 1.0,
            ],
            [
                'text' => 'Пришлите фото в Telegram или файл PDF.',
                'start' => 2.0,
            ],
        ]);

        $this->assertSame([], $items);
    }

    public function test_probe_lists_redacted_items_with_flag_off(): void
    {
        config(['features.hindi_transcript_drills' => false]);
        [, , $lesson] = $this->seedHindiLessonWithFixture();

        $this->artisan('hindi:drills-probe', ['lesson' => $lesson->id, '--json' => true])
            ->assertSuccessful();

        $items = app(HindiTranscriptDrills::class)->itemsFor($lesson);
        $this->assertGreaterThanOrEqual(3, count($items));
        $this->assertContains('translate', array_column($items, 'type'));
        $this->assertContains('cloze', array_column($items, 'type'));
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiLessonWithFixture(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-drills']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-drills']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
            'transcript_file' => $this->putFixture(),
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        return [$user, $course, $lesson];
    }

    private function putFixture(): string
    {
        $path = 'transcripts/hindi_lesson_sample.json';
        Storage::disk('public')->put(
            $path,
            (string) file_get_contents(base_path('tests/fixtures/hindi_transcript/hindi_lesson_sample.json')),
        );

        return $path;
    }
}
