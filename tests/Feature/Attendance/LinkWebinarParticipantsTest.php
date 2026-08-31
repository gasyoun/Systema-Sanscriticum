<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Models\WebinarParticipantLink;
use App\Support\ZoomNameMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3761 — `attendance:link-participants` и матчер имён.
 *
 * Zoom отдаёт почту у 4 % участников, поэтому единственный оставшийся признак —
 * экранное имя. Проверяется, что матчер переживает транслит и обратный порядок
 * слов, но НЕ угадывает там, где кандидатов несколько.
 */
class LinkWebinarParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithPayers(array $names): Course
    {
        $course = Course::factory()->create();
        foreach ($names as $name) {
            $user = User::factory()->create(['name' => $name]);
            DB::table('course_user')->insert([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $course;
    }

    private function attendanceRow(Course $course, string $zoomName): void
    {
        $schedule = Schedule::firstOrCreate(
            ['course_id' => $course->id, 'start' => '2026-07-01 14:00:00'],
            ['title' => 'Занятие', 'end' => '2026-07-01 16:00:00', 'link' => 'https://us02web.zoom.us/j/1?pwd=x'],
        );

        WebinarAttendance::create([
            'schedule_id' => $schedule->id,
            'zoom_participant_uuid' => 'p-'.md5($zoomName),
            'name' => $zoomName,
        ]);
    }

    /** @test */
    public function key_is_stable_across_translit_case_and_word_order(): void
    {
        $this->assertSame(ZoomNameMatcher::key('Анна Иванова'), ZoomNameMatcher::key('Иванова Анна'));
        $this->assertSame(ZoomNameMatcher::key('Анна Иванова'), ZoomNameMatcher::key('ANNA IVANOVA'));
    }

    /** @test */
    public function a_device_name_yields_no_tokens(): void
    {
        $this->assertSame([], ZoomNameMatcher::tokens('iPhone'));
        $this->assertSame([], ZoomNameMatcher::tokens('79161234567'));
        $this->assertSame('', ZoomNameMatcher::key('  --  '));
    }

    /** @test */
    public function full_name_match_is_strong_even_in_latin(): void
    {
        $result = ZoomNameMatcher::match('Anna Ivanova', [7 => 'Анна Иванова', 8 => 'Пётр Сидоров']);

        $this->assertSame(7, $result['user_id']);
        $this->assertSame('strong', $result['confidence']);
    }

    /** @test */
    public function a_single_shared_word_is_weak_not_strong(): void
    {
        $result = ZoomNameMatcher::match('Анна', [7 => 'Анна Иванова', 8 => 'Пётр Сидоров']);

        $this->assertSame(7, $result['user_id']);
        $this->assertSame('weak', $result['confidence']);
    }

    /** @test */
    public function an_ambiguous_name_is_never_guessed(): void
    {
        $result = ZoomNameMatcher::match('Анна', [7 => 'Анна Иванова', 8 => 'Анна Сидорова']);

        $this->assertNull($result['user_id']);
        $this->assertNull($result['confidence']);
    }

    /** @test */
    public function dry_run_writes_no_links(): void
    {
        $course = $this->courseWithPayers(['Анна Иванова']);
        $this->attendanceRow($course, 'Anna Ivanova');

        $this->artisan("attendance:link-participants --course={$course->id}")->assertSuccessful();

        $this->assertSame(0, WebinarParticipantLink::count());
    }

    /** @test */
    public function apply_links_a_strong_match_only(): void
    {
        $course = $this->courseWithPayers(['Анна Иванова', 'Пётр Сидоров']);
        $this->attendanceRow($course, 'Anna Ivanova');   // strong
        $this->attendanceRow($course, 'Пётр');           // weak — по умолчанию не заводится
        $this->attendanceRow($course, 'iPhone');         // без слов — никогда

        $this->artisan("attendance:link-participants --course={$course->id} --apply")->assertSuccessful();

        $this->assertSame(1, WebinarParticipantLink::count());
        $link = WebinarParticipantLink::first();
        $this->assertSame('auto_name', $link->source);
        $this->assertSame('strong', $link->confidence);
    }

    /** @test */
    public function weak_flag_opts_into_single_word_matches(): void
    {
        $course = $this->courseWithPayers(['Анна Иванова', 'Пётр Сидоров']);
        $this->attendanceRow($course, 'Пётр');

        $this->artisan("attendance:link-participants --course={$course->id} --weak --apply")->assertSuccessful();

        $this->assertSame('weak', WebinarParticipantLink::first()?->confidence);
    }

    /** @test */
    public function a_second_run_never_overwrites_a_human_confirmed_link(): void
    {
        $course = $this->courseWithPayers(['Анна Иванова', 'Пётр Сидоров']);
        $this->attendanceRow($course, 'Anna Ivanova');

        $other = User::where('name', 'Пётр Сидоров')->firstOrFail();
        WebinarParticipantLink::create([
            'course_id' => $course->id,
            'user_id' => $other->id,
            'zoom_name' => 'Anna Ivanova',
            'zoom_name_key' => ZoomNameMatcher::key('Anna Ivanova'),
            'source' => 'manual',
            'confidence' => 'strong',
            'confirmed_at' => now(),
        ]);

        $this->artisan("attendance:link-participants --course={$course->id} --apply")->assertSuccessful();

        $this->assertSame(1, WebinarParticipantLink::count());
        $this->assertSame('manual', WebinarParticipantLink::first()->source);
        $this->assertSame($other->id, WebinarParticipantLink::first()->user_id);
    }

    // --- H3772: нечёткий слой -------------------------------------------------

    /**
     * Ровно те промахи, которые нашла перепись боевых данных 31-08-2026:
     * транслитерация систематически теряет ь, й и «-ия».
     *
     * @test
     *
     * @dataProvider translitMisses
     */
    public function fuzzy_recovers_the_translit_misses_found_in_production(string $zoom, string $cabinet): void
    {
        $result = ZoomNameMatcher::match($zoom, [7 => $cabinet, 8 => 'Пётр Сидоров']);

        $this->assertSame(7, $result['user_id'], "{$zoom} должно опознаться как {$cabinet}");
        $this->assertContains($result['confidence'], ['strong', 'fuzzy']);
    }

    public static function translitMisses(): array
    {
        return [
            'Olga -> Ольга (мягкий знак)' => ['Olga Petrova', 'Ольга Петрова'],
            'Yulia -> Юлия (-ия)' => ['Yulia Petrova', 'Юлия Петрова'],
            'Sergey -> Сергей (й)' => ['Sergey Petrov', 'Сергей Петров'],
            'Dmitry -> Дмитрий' => ['Dmitry Petrov', 'Дмитрий Петров'],
            'Anastasia -> Анастасия' => ['Anastasia Petrova', 'Анастасия Петрова'],
            'Natalia -> Наталия' => ['Natalia Petrova', 'Наталия Петрова'],
        ];
    }

    /** @test */
    public function fuzzy_expands_a_diminutive(): void
    {
        $result = ZoomNameMatcher::match('Катя Иванова', [7 => 'Екатерина Иванова', 8 => 'Пётр Сидоров']);

        $this->assertSame(7, $result['user_id']);
        $this->assertSame('fuzzy', $result['confidence']);
    }

    /** @test */
    public function fuzzy_matches_across_a_city_suffix_and_patronymic(): void
    {
        // users.name на проде выглядит как «Фамилия Имя Отчество, Город».
        $result = ZoomNameMatcher::match('Anastasia Vakhromeeva', [
            7 => 'Вахромеева Анастасия Николаевна, Москва',
            8 => 'Пётр Сидоров',
        ]);

        $this->assertSame(7, $result['user_id']);
    }

    /** @test */
    public function fuzzy_refuses_a_tie_between_two_payers(): void
    {
        $result = ZoomNameMatcher::match('Olga Petrova', [7 => 'Ольга Петрова', 8 => 'Ольга Петрова']);

        $this->assertNull($result['user_id'], 'ничья — это отказ, а не выбор первого');
        $this->assertNull($result['confidence']);
    }

    /**
     * Одно слово, совпавшее лишь после складки, — та же тонкость, что и обычный
     * `weak`, поэтому и уровень тот же: за флагом --weak, а не как нечёткое.
     *
     * @test
     */
    public function a_single_folded_word_is_only_weak_not_fuzzy(): void
    {
        $result = ZoomNameMatcher::match('Olga', [7 => 'Ольга Петрова', 8 => 'Пётр Сидоров']);

        $this->assertSame(7, $result['user_id']);
        $this->assertSame('weak', $result['confidence']);
    }

    /** @test */
    public function a_single_word_with_a_typo_is_refused_outright(): void
    {
        // Одно слово И с опечаткой: неточность и единственность признака
        // складываются, опознавать по такому нельзя.
        $result = ZoomNameMatcher::match('Olgna', [7 => 'Ольга Петрова', 8 => 'Пётр Сидоров']);

        $this->assertNull($result['user_id']);
        $this->assertNull($result['confidence']);
    }

    /** @test */
    public function two_fuzzy_words_outrank_one_exact_word(): void
    {
        // «Petrova» совпадает точно и с однофамилицей; имя+фамилия через
        // складку указывают на конкретного человека и должны победить.
        $result = ZoomNameMatcher::match('Yulia Petrova', [
            7 => 'Юлия Петрова',
            8 => 'Ирина Петрова',
        ]);

        $this->assertSame(7, $result['user_id']);
        $this->assertSame('fuzzy', $result['confidence']);
    }

    /** @test */
    public function short_words_get_no_distance_budget(): void
    {
        // «вера» и «вика» — две буквы разницы, но два разных человека.
        $this->assertNull(ZoomNameMatcher::distance('вера', 'вика'));
        $this->assertSame(0, ZoomNameMatcher::distance('вера', 'вера'));
    }

    /** @test */
    public function distance_is_measured_in_characters_not_bytes(): void
    {
        // Встроенный levenshtein() байтовый: на кириллице он считает мусор.
        $this->assertSame(1, ZoomNameMatcher::distance('екатерина', 'екатерена'));
        $this->assertNull(ZoomNameMatcher::distance('екатерина', 'константин'));
    }

    /** @test */
    public function fuzzy_links_are_only_written_behind_the_flag_and_marked_apart(): void
    {
        $course = $this->courseWithPayers(['Ольга Петрова', 'Пётр Сидоров']);
        $this->attendanceRow($course, 'Olga Petrova');

        $this->artisan("attendance:link-participants --course={$course->id} --apply")->assertSuccessful();
        $this->assertSame(0, WebinarParticipantLink::count(), 'без --fuzzy нечёткое не пишется');

        $this->artisan("attendance:link-participants --course={$course->id} --fuzzy --apply")->assertSuccessful();

        $link = WebinarParticipantLink::firstOrFail();
        $this->assertSame('fuzzy', $link->confidence);
        $this->assertSame('auto_fuzzy', $link->source, 'нечёткие связки должны сниматься одним запросом');
    }

    /** @test */
    public function an_exact_match_is_never_displaced_by_a_fuzzy_one(): void
    {
        $course = $this->courseWithPayers(['Анна Иванова', 'Анна Иванченко']);
        $this->attendanceRow($course, 'Анна Иванова');

        $this->artisan("attendance:link-participants --course={$course->id} --fuzzy --apply")->assertSuccessful();

        $exact = User::where('name', 'Анна Иванова')->firstOrFail();
        $this->assertSame($exact->id, WebinarParticipantLink::firstOrFail()->user_id);
        $this->assertSame('strong', WebinarParticipantLink::firstOrFail()->confidence);
    }
}
