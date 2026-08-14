<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\HindiTranscriptDrills;
use App\Support\HindiMySrsDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H2445 — private «Мой хинди» deck from playlist / drill items.
 */
class HindiMySrsDeckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'srs.enabled' => false,
            'features.hindi_my_srs_deck' => false,
            'features.hindi_transcript_drills' => false,
            'features.hindi_programme_playlist' => false,
            'features.cabinet_hybrid' => false,
        ]);
    }

    public function test_flag_off_returns_404_and_hides_buttons(): void
    {
        config([
            'features.hindi_transcript_drills' => true,
            'features.hindi_programme_playlist' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $item = $this->firstTranslateItem($lesson);

        $this->actingAs($user)
            ->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
                'item_id' => $item['id'],
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertDontSee('data-testid="hindi-drill-srs"', false);

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertDontSee('data-testid="hindi-playlist-srs"', false);

        $this->assertSame(0, SrsDeck::query()->count());
        $this->assertSame(0, SrsCard::query()->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        config(['features.hindi_my_srs_deck' => true]);
        [, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $this->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => 'htd-translate-0-x',
        ])->assertRedirect();

        $this->assertSame(0, SrsCard::query()->count());
    }

    public function test_entitled_student_adds_one_drill_item_to_private_deck(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $item = $this->firstTranslateItem($lesson);

        $this->actingAs($user)
            ->from(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
                'item_id' => $item['id'],
            ])
            ->assertRedirect(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertSessionHas('hindi_srs_status', 'added')
            ->assertSessionHas('hindi_srs_word', 'kitāb');

        $deck = SrsDeck::query()->where('user_id', $user->id)->sole();
        $this->assertSame(HindiMySrsDeck::DECK_SLUG, $deck->slug);
        $this->assertSame('private', $deck->visibility);
        $this->assertSame('hi', $deck->language);
        $this->assertSame('Мой хинди', $deck->name);

        $card = SrsCard::query()->where('deck_id', $deck->id)->sole();
        $this->assertSame('kitāb', $card->fields['lemma']);
        $this->assertSame('книга', $card->fields['gloss']);
        $this->assertSame((string) $lesson->id, $card->fields['lesson_id']);
        $this->assertSame($item['id'], $card->fields['item_id']);
        $this->assertSame($course->slug, $card->fields['course_slug']);
    }

    public function test_same_lemma_twice_does_not_create_a_second_card(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $item = $this->firstTranslateItem($lesson);

        $this->actingAs($user)->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => $item['id'],
        ]);
        $second = $this->actingAs($user)->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => $item['id'],
        ]);

        $second->assertSessionHas('hindi_srs_status', 'duplicate');
        $this->assertSame(1, SrsCard::query()->count());
    }

    public function test_translate_and_vocab_pick_of_the_same_word_dedupe(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $items = app(HindiTranscriptDrills::class)->itemsFor($lesson);
        $translate = collect($items)->firstWhere('type', 'translate');
        $vocab = collect($items)->firstWhere('type', 'vocab_pick');
        $this->assertNotNull($translate);
        $this->assertNotNull($vocab);
        $this->assertSame(
            HindiMySrsDeck::lemmaFromItem($translate),
            HindiMySrsDeck::lemmaFromItem($vocab),
        );

        $this->actingAs($user)->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => $translate['id'],
        ]);
        $this->actingAs($user)->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => $vocab['id'],
        ])->assertSessionHas('hindi_srs_status', 'duplicate');

        $this->assertSame(1, SrsCard::query()->count());
    }

    public function test_playlist_add_fills_unique_lemmas_from_transcript(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_programme_playlist' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $html = $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-playlist-srs"', false)
            ->getContent();
        $this->assertSame(1, substr_count((string) $html, 'data-testid="hindi-playlist-srs"'));

        $this->actingAs($user)
            ->from(route('student.programme.hindi'))
            ->post(route('student.programme.hindi.srs'), ['lesson_id' => $lesson->id])
            ->assertRedirect(route('student.programme.hindi'))
            ->assertSessionHas('hindi_srs_status', 'added_batch');

        $lemmas = SrsCard::query()->get()->map(
            fn (SrsCard $card): string => (string) ($card->fields['lemma'] ?? ''),
        )->all();
        $this->assertEqualsCanonicalizing(['kaise', 'kitāb', 'पानी', 'नमस्ते'], $lemmas);

        $this->actingAs($user)
            ->post(route('student.programme.hindi.srs'), ['lesson_id' => $lesson->id])
            ->assertSessionHas('hindi_srs_status', 'duplicate');
        $this->assertSame(4, SrsCard::query()->count());
        $this->assertSame(1, SrsDeck::query()->count());
        $this->assertSame($course->slug, SrsCard::query()->first()->fields['course_slug']);
    }

    public function test_does_not_write_the_public_hindi_core_deck(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $shared = $this->makeSharedHindiCoreDeck();
        $before = SrsCard::query()->where('deck_id', $shared->id)->count();
        $item = $this->firstTranslateItem($lesson);

        $this->actingAs($user)->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
            'item_id' => $item['id'],
        ])->assertSessionHas('hindi_srs_status', 'added');

        $this->assertSame($before, SrsCard::query()->where('deck_id', $shared->id)->count());
        $this->assertSame(1, SrsDeck::query()->where('slug', HindiMySrsDeck::DECK_SLUG)->count());
        $this->assertNotSame('hindi-core', SrsDeck::query()->where('user_id', $user->id)->value('slug'));
    }

    public function test_locked_or_non_hindi_lesson_cannot_post(): void
    {
        config(['features.hindi_my_srs_deck' => true]);
        $hindi = Category::factory()->create(['slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-srs-lock']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'H3-srs-lock']);
        $course->groups()->attach($group->id);
        $locked = Lesson::factory()->for($course)->create([
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
            ->post(route('student.lesson.srs.add', [$course->slug, $locked->id]), [
                'item_id' => 'htd-cloze-0-x',
            ])
            ->assertForbidden();

        $sanskrit = Category::factory()->create(['slug' => 'sanskrit']);
        $saCourse = Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-srs']);
        $saCourse->categories()->attach($sanskrit->id);
        $saGroup = Group::create(['name' => 'Sa-srs']);
        $saCourse->groups()->attach($saGroup->id);
        $saLesson = Lesson::factory()->for($saCourse)->create([
            'title' => 'Санскрит 1',
            'is_published' => true,
            'transcript_file' => $this->putFixture(),
        ]);
        $user->groups()->attach($saGroup->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $saCourse->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        $this->actingAs($user)
            ->post(route('student.lesson.srs.add', [$saCourse->slug, $saLesson->id]), [
                'item_id' => 'htd-cloze-0-x',
            ])
            ->assertNotFound();

        $this->assertSame(0, SrsCard::query()->count());
    }

    public function test_unknown_item_id_is_not_invented(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();

        $this->actingAs($user)
            ->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
                'item_id' => 'forged-lemma-text',
            ])
            ->assertNotFound();

        $this->assertSame(0, SrsCard::query()->count());
    }

    public function test_drills_page_offers_one_button_per_item_when_flag_on(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $items = app(HindiTranscriptDrills::class)->itemsFor($lesson);

        $html = $this->actingAs($user)
            ->get(route('student.lesson.drills', [$course->slug, $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(count($items), substr_count((string) $html, 'data-testid="hindi-drill-srs"'));
    }

    public function test_adding_does_not_require_global_srs_switch(): void
    {
        config([
            'features.hindi_my_srs_deck' => true,
            'features.hindi_transcript_drills' => true,
            'srs.enabled' => false,
        ]);
        [$user, $course, $lesson] = $this->seedHindiLessonWithFixture();
        $item = $this->firstTranslateItem($lesson);

        $this->actingAs($user)
            ->post(route('student.lesson.srs.add', [$course->slug, $lesson->id]), [
                'item_id' => $item['id'],
            ])
            ->assertSessionHas('hindi_srs_status', 'added');

        $this->actingAs($user)
            ->get('/dvaram/koloda/'.HindiMySrsDeck::DECK_SLUG)
            ->assertNotFound();

        config(['srs.enabled' => true]);
        $this->actingAs($user)
            ->get('/dvaram/koloda/'.HindiMySrsDeck::DECK_SLUG)
            ->assertOk();
        $this->actingAs($user)
            ->get('/dvaram/koloda?lang=hi')
            ->assertOk()
            ->assertSee('Мой хинди', false)
            ->assertSee('/dvaram/koloda/my-hindi', false);
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiLessonWithFixture(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-srs']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-srs']);
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

    /** @return array<string, mixed> */
    private function firstTranslateItem(Lesson $lesson): array
    {
        $item = collect(app(HindiTranscriptDrills::class)->itemsFor($lesson))
            ->firstWhere('type', 'translate');
        $this->assertNotNull($item);

        return $item;
    }

    private function makeSharedHindiCoreDeck(): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'hindi_core_shared',
            'name' => 'Hindi Core',
            'language' => 'hi',
            'fields' => ['devanagari', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'user_id' => null,
            'note_type_id' => $noteType->id,
            'name' => 'Hindi Core 100',
            'slug' => 'hindi-core',
            'language' => 'hi',
            'visibility' => 'system',
        ]);

        SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => ['devanagari' => 'हफ्ता', 'translation' => 'week'],
        ]);

        return $deck;
    }
}
