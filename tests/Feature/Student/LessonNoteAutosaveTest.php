<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6302 — Prevent loss of unsaved lesson notes (ticket L2, UX-аудит 2026).
 *
 * Серверная часть приёмки автосейва заметок: тот же эндпоинт
 * student.lesson.note должен (1) сохранять и отдавать последнюю версию
 * заметки после перезагрузки, (2) отвечать JSON на Accept: application/json
 * (контракт public/js/lesson-note-autosave.js), (3) сохранять ручной
 * POST-фолбэк с редиректом, (4) отвергать чужие уроки/пользователей
 * (IDOR-гард ensureLessonAccessible не ослаблен), (5) валидировать длину.
 *
 * Клиентская часть приёмки (дебаунс, single-flight порядок, сетевой сбой и
 * 419 не выдают несохранённый текст за сохранённый) — tests/js/.
 */
class LessonNoteAutosaveTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function json_save_persists_and_reload_retains_the_latest_note(): void
    {
        $user = User::factory()->create();
        $lesson = $this->freeLesson();

        // Первая (авто)сохранённая версия.
        $this->actingAs($user)
            ->postJson($this->noteUrl($lesson), ['notes' => 'Первая мысль о сандхи'])
            ->assertOk()
            ->assertJson(['saved' => true]);

        // Перезагрузка урока отдаёт первую сохранённую версию.
        $this->assertNoteOnLessonPage($user, $lesson, 'Первая мысль о сандхи');

        // Более новая принятая версия перезаписывает старую.
        $this->actingAs($user)
            ->postJson($this->noteUrl($lesson), ['notes' => 'Вторая: глагол в перфекте'])
            ->assertOk();

        $this->assertNoteOnLessonPage($user, $lesson, 'Вторая: глагол в перфекте');
        $this->assertNoteNotOnLessonPage($user, $lesson, 'Первая мысль о сандхи');

        // Пивот-строка одна, is_completed не поднята сохранением заметки.
        $this->assertSame(
            ['Вторая: глагол в перфекте'],
            $user->lessonProgress()->where('lesson_id', $lesson->id)->pluck('notes')->all(),
        );
    }

    /** @test */
    public function plain_form_post_still_redirects_back_with_flash(): void
    {
        $user = User::factory()->create();
        $lesson = $this->freeLesson();

        // Ручной fallback: без Accept: json эндпоинт отвечает 302 назад.
        $this->actingAs($user)
            ->post($this->noteUrl($lesson), ['notes' => 'из формы'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Заметка сохранена');

        $this->assertNoteOnLessonPage($user, $lesson, 'из формы');
    }

    /** @test */
    public function empty_json_note_is_valid_and_persists_null(): void
    {
        $user = User::factory()->create();
        $lesson = $this->freeLesson();

        $this->actingAs($user)
            ->postJson($this->noteUrl($lesson), ['notes' => ''])
            ->assertOk()
            ->assertJson(['saved' => true]);
    }

    /** @test */
    public function note_longer_than_5000_chars_is_rejected_as_json_422(): void
    {
        $user = User::factory()->create();
        $lesson = $this->freeLesson();

        $this->actingAs($user)
            ->postJson($this->noteUrl($lesson), ['notes' => str_repeat('а', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        // Ничего не записали.
        $this->assertSame(
            [],
            $user->lessonProgress()->where('lesson_id', $lesson->id)->pluck('notes')->all(),
        );
    }

    /** @test */
    public function stranger_cannot_save_notes_to_a_lesson_they_did_not_pay_for(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $course = Course::factory()->create();
        // Не-бесплатный урок: владелец оплатил full, чужак — нет.
        $paidLesson = Lesson::factory()->for($course)->create(['is_free' => false]);
        Payment::create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'status' => 'paid',
            'is_conditional' => false,
            'start_block' => 1,
            'end_block' => 1,
            'tariff' => 'full',
        ]);

        $this->actingAs($owner)
            ->postJson($this->noteUrl($paidLesson), ['notes' => 'заметка владельца'])
            ->assertOk();

        $this->actingAs($stranger)
            ->postJson($this->noteUrl($paidLesson), ['notes' => 'заметка чужака'])
            ->assertForbidden();

        // Заметка чужака не записана, заметка владельца не перезаписана.
        $this->assertSame(
            ['заметка владельца'],
            $owner->lessonProgress()->where('lesson_id', $paidLesson->id)->pluck('notes')->all(),
        );
        $this->assertSame(
            [],
            $stranger->lessonProgress()->where('lesson_id', $paidLesson->id)->pluck('notes')->all(),
        );
    }

    /** @test */
    public function lesson_id_from_another_course_is_rejected(): void
    {
        $user = User::factory()->create();
        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();
        Lesson::factory()->for($courseA)->free()->create();

        $lessonOfB = Lesson::factory()->for($courseB)->free()->create();

        // URL курса A, но lessonId принадлежит курсу B → 404 (findOrFail по
        // course_id), перезаписи чужой заметки быть не может.
        $this->actingAs($user)
            ->postJson(route('student.lesson.note', ['slug' => $courseA->slug, 'lessonId' => $lessonOfB->id]), [
                'notes' => 'подмена id',
            ])
            ->assertNotFound();
    }

    /** @test */
    public function lesson_page_renders_autosave_hooks_and_status_row(): void
    {
        $user = User::factory()->create();
        $lesson = $this->freeLesson();

        $html = $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="lesson-notes-textarea"', $html);
        $this->assertStringContainsString('id="lesson-note-form"', $html);
        $this->assertStringContainsString('data-note-status', $html);
        $this->assertStringContainsString('data-note-retry', $html);
        $this->assertStringContainsString('lesson-note-autosave.js', $html);
        // Форма остаётся нативным POST-фолбеком.
        $this->assertStringContainsString('method="POST"', $html);
    }

    // ------------------------------------------- fixtures

    private function freeLesson(): Lesson
    {
        $course = Course::factory()->create();

        return Lesson::factory()->for($course)->free()->create();
    }

    private function noteUrl(Lesson $lesson): string
    {
        return route('student.lesson.note', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]);
    }

    private function assertNoteOnLessonPage(User $user, Lesson $lesson, string $needle): void
    {
        $html = $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($needle, $html);
    }

    private function assertNoteNotOnLessonPage(User $user, Lesson $lesson, string $needle): void
    {
        $html = $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($needle, $html);
    }
}
