<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use App\Services\HindiDictionaryDrills;
use App\Services\HindiKostinaDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3206 — Kostina module dictionaries: flag, access, M1 glossary + check.
 */
class HindiDictionaryDrillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_returns_404_for_student(): void
    {
        config(['features.hindi_dictionary_drills' => false]);
        [$user] = $this->seedHindiStudent();

        $this->actingAs($user)
            ->get(route('student.programme.hindi.vocab'))
            ->assertNotFound();
    }

    public function test_guest_is_redirected(): void
    {
        config(['features.hindi_dictionary_drills' => true]);

        $this->get(route('student.programme.hindi.vocab'))
            ->assertRedirect();
    }

    public function test_entitled_student_sees_m1_glossary_and_checks_translate(): void
    {
        config([
            'features.hindi_dictionary_drills' => true,
            'features.hindi_programme_playlist' => true,
            'features.cabinet_hybrid' => false,
        ]);
        [$user] = $this->seedHindiStudent();

        $this->actingAs($user)
            ->get(route('student.programme.hindi.vocab'))
            ->assertOk()
            ->assertSee('data-testid="hindi-kostina-dict-index"', false)
            ->assertSee('data-module="M1"', false);

        $html = $this->actingAs($user)
            ->get(route('student.programme.hindi.vocab.show', 'M1'))
            ->assertOk()
            ->assertSee('data-testid="hindi-kostina-dict"', false)
            ->assertSee('आदाब', false)
            ->assertSee('здравствуйте (мус.)', false)
            ->assertSee('Как по-русски: आदाब', false)
            ->getContent();

        $this->assertStringContainsString('data-testid="hindi-drill-item"', $html);

        $this->actingAs($user)
            ->postJson(route('student.programme.hindi.vocab.check', 'M1'), [
                'item_id' => 'M1-0004-tr',
                'answer' => 'здравствуйте',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'item_id' => 'M1-0004-tr']);
    }

    public function test_store_has_all_twelve_modules(): void
    {
        $dict = app(HindiKostinaDictionary::class);
        $this->assertCount(12, $dict->modules());
        $this->assertGreaterThanOrEqual(15, count($dict->entriesFor('M1')));
        $probe = app(HindiDictionaryDrills::class)->probe();
        $this->assertGreaterThanOrEqual(900, $probe['entry_total']);
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedHindiStudent(): array
    {
        $hindi = Category::factory()->create(['name' => 'Хинди', 'slug' => 'hindi']);
        $course = Course::factory()->create(['title' => 'Хинди гр. 3', 'slug' => 'hindi-gr3-dict']);
        $course->categories()->attach($hindi->id);
        $group = Group::create(['name' => 'Hindi-3-dict']);
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
