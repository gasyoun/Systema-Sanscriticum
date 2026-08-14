<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\HindiTgCuratedPractice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H2446 — curated TG practice: flag, access, pilot items, no raw chat.
 */
class HindiTgCuratedPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_returns_404(): void
    {
        config(['features.hindi_tg_curated_practice' => false]);
        [$user] = $this->seedHindiStudent();

        $this->actingAs($user)
            ->get(route('student.programme.hindi.tg'))
            ->assertNotFound();
    }

    public function test_guest_is_redirected(): void
    {
        config(['features.hindi_tg_curated_practice' => true]);

        $this->get(route('student.programme.hindi.tg'))
            ->assertRedirect();
    }

    public function test_entitled_student_can_run_pilot_and_check_answer(): void
    {
        config([
            'features.hindi_tg_curated_practice' => true,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user] = $this->seedHindiStudent();

        $html = $this->actingAs($user)
            ->get(route('student.programme.hindi.tg'))
            ->assertOk()
            ->assertSee('data-testid="hindi-tg-curated-practice"', false)
            ->assertSee('data-testid="hindi-tg-privacy-note"', false)
            ->assertSee('Как по-русски: kitāb', false)
            ->assertSee('data-item-type="cloze"', false)
            ->assertSee('data-item-type="vocab_pick"', false)
            ->assertDontSee('telegram_user_id', false)
            ->assertDontSee('@ivan_student', false)
            ->getContent();

        $this->assertSame(10, substr_count((string) $html, 'data-testid="hindi-tg-item"'));

        $this->actingAs($user)
            ->postJson(route('student.programme.hindi.tg.check'), [
                'item_id' => 'htg-001',
                'answer' => 'Книга!',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'item_id' => 'htg-001']);

        $this->actingAs($user)
            ->postJson(route('student.programme.hindi.tg.check'), [
                'item_id' => 'htg-001',
                'answer' => 'тетрадь',
            ])
            ->assertOk()
            ->assertJson(['ok' => false, 'correct_answer' => 'книга']);
    }

    public function test_student_without_hindi_access_is_forbidden(): void
    {
        config(['features.hindi_tg_curated_practice' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('student.programme.hindi.tg'))
            ->assertForbidden();
    }

    public function test_sanskrit_only_student_is_forbidden(): void
    {
        config(['features.hindi_tg_curated_practice' => true]);
        $sanskrit = Category::factory()->create(['slug' => 'sanskrit']);
        $course = Course::factory()->create(['title' => 'Санскрит гр. 1', 'slug' => 'sa-1-tg']);
        $course->categories()->attach($sanskrit->id);
        $group = Group::create(['name' => 'Sa-1-tg']);
        $course->groups()->attach($group->id);
        Lesson::factory()->for($course)->create([
            'title' => 'Санскрит 1',
            'is_published' => true,
            'is_free' => false,
            'block_number' => 1,
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
            ->get(route('student.programme.hindi.tg'))
            ->assertForbidden();
    }

    public function test_playlist_shows_cta_only_when_flag_on(): void
    {
        config([
            'features.hindi_programme_playlist' => true,
            'features.hindi_tg_curated_practice' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user] = $this->seedHindiStudent();

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertSee('data-testid="hindi-playlist-tg-practice"', false)
            ->assertSee(route('student.programme.hindi.tg', [], false), false);

        config(['features.hindi_tg_curated_practice' => false]);

        $this->actingAs($user)
            ->get(route('student.programme.hindi'))
            ->assertOk()
            ->assertDontSee('data-testid="hindi-playlist-tg-practice"', false);
    }

    public function test_items_for_entitled_user_never_include_forbidden_keys(): void
    {
        config(['features.hindi_tg_curated_practice' => true]);
        [$user] = $this->seedHindiStudent();

        $items = app(HindiTgCuratedPractice::class)->itemsFor($user);
        $this->assertCount(10, $items);
        foreach ($items as $item) {
            foreach (HindiTgCuratedPractice::FORBIDDEN_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $item);
            }
        }
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiStudent(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-tg']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-tg']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => '1-е занятие',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
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
}
