<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\HomeworkAutoOpener;
use App\Services\KocherginaExerciseSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Критерии приёмки A1–A13 волны 1 (H1764).
 *
 * @see docs/VERIFICATION_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md
 *
 * Текст учебника Кочергиной здесь НЕ используется (D6, следствие 1): источник
 * подменяется синтетическим mdx-фрагментом, сочинённым для теста. Он повторяет
 * только СТРУКТУРУ оцифровки — заголовки занятий и блок «Упражнения».
 */
class AutoOpenHomeworkTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'СИНТЕТИЧЕСКОЕ УПРАЖНЕНИЕ ДЛЯ ТЕСТА';

    private ?string $sourcePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Http::fake();

        date_default_timezone_set('Europe/Moscow');
        KocherginaExerciseSource::flush();

        config([
            'homework.auto_open.enabled' => true,
            'homework.auto_open.textbook_lessons' => [1, 2, 3, 4, 5],
            'homework.auto_open.delay_hours' => 12,
            'homework.auto_open.align_hour' => 9,
            'homework.auto_open.notify_channels' => ['telegram', 'vk'],
            'services.telegram.student_bot_token' => 'TESTTOKEN',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        KocherginaExerciseSource::flush();

        if ($this->sourcePath && is_file($this->sourcePath)) {
            @unlink($this->sourcePath);
        }

        parent::tearDown();
    }

    // ===================================================================
    // A1 — точка отсчёта
    // ===================================================================

    /** @test */
    public function recording_stamp_is_set_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 06:00', 'Europe/Moscow'));

        $lesson = Lesson::factory()->for(Course::factory())->create();
        $this->assertNull($lesson->recording_attached_at, 'У урока без видео отметки быть не должно.');

        $lesson->update(['youtube_url' => 'https://youtu.be/first']);
        $firstStamp = $lesson->fresh()->recording_attached_at;
        $this->assertNotNull($firstStamp);

        // Перезаливка записи отметку НЕ двигает: иначе замена битого видео
        // уносила бы момент открытия ДЗ на сутки вперёд.
        Carbon::setTestNow(Carbon::parse('2026-07-29 15:00', 'Europe/Moscow'));
        $lesson->update(['youtube_url' => 'https://youtu.be/second']);

        $this->assertTrue(
            $firstStamp->equalTo($lesson->fresh()->recording_attached_at),
            'Повторная заливка видео сдвинула recording_attached_at.'
        );
    }

    // ===================================================================
    // A2 — расчёт момента (табличный тест по четырём краям из архитектуры)
    // ===================================================================

    /** @test */
    public function opens_at_aligns_to_next_nine_msk(): void
    {
        $cases = [
            // запись               → ожидаемое открытие
            ['2026-07-28 06:00', '2026-07-29 09:00'], // вт утро → ср 09:00
            ['2026-07-28 20:00', '2026-07-29 09:00'], // вт вечер → ср 09:00
            ['2026-07-28 21:30', '2026-07-30 09:00'], // поздний вечер → чт 09:00
            ['2026-07-28 09:00', '2026-07-29 09:00'], // ровно 09:00 → ср 09:00
        ];

        foreach ($cases as [$recordedAt, $expected]) {
            $actual = HomeworkAutoOpener::opensAtFor(Carbon::parse($recordedAt, 'Europe/Moscow'));

            $this->assertSame(
                $expected,
                $actual->format('Y-m-d H:i'),
                "Запись {$recordedAt} должна открывать ДЗ в {$expected}."
            );
        }
    }

    // ===================================================================
    // A3 / A4 — открытие и идемпотентность
    // ===================================================================

    /** @test */
    public function lesson_opens_after_delay(): void
    {
        [$course, $lesson] = $this->scopedLessonWithRecording();

        // До момента открытия команда не трогает урок.
        Carbon::setTestNow(Carbon::parse('2026-07-28 23:00', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();
        $this->assertFalse((bool) $lesson->fresh()->homework_enabled, 'ДЗ открылось раньше срока.');

        // После — открывает и помечает владение.
        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled);
        $this->assertNotNull($lesson->homework_auto_opened_at, 'Не проставлен маркер владения автомата.');
    }

    /** @test */
    public function second_run_does_not_reopen(): void
    {
        [$course, $lesson] = $this->scopedLessonWithRecording();
        $student = $this->studentInGroupOf($lesson, telegramId: '555001');

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $openedAt = $lesson->fresh()->homework_auto_opened_at;
        $pushesAfterFirstRun = count(Http::recorded());

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:59', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $this->assertTrue(
            $openedAt->equalTo($lesson->fresh()->homework_auto_opened_at),
            'Повторный прогон переоткрыл урок.'
        );
        $this->assertCount($pushesAfterFirstRun, Http::recorded(), 'Повторный прогон отправил второй пуш.');
    }

    // ===================================================================
    // A5 / A6 — условие задания
    // ===================================================================

    /** @test */
    public function prompt_is_filled_from_source(): void
    {
        $this->fakeSource();
        [$course, $lesson] = $this->scopedLessonWithRecording();

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $prompt = (string) $lesson->fresh()->homework_prompt;
        $this->assertStringContainsString('Занятию 1', $prompt);
        $this->assertStringContainsString(self::MARKER, $prompt, 'Текст упражнений из источника не подставлен.');
    }

    /** @test */
    public function manual_prompt_is_preserved(): void
    {
        $this->fakeSource();
        [$course, $lesson] = $this->scopedLessonWithRecording();
        $lesson->update(['homework_prompt' => 'Условие, написанное преподавателем']);

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $this->assertSame('Условие, написанное преподавателем', $lesson->fresh()->homework_prompt);
    }

    // ===================================================================
    // A7 — ограничение D14
    // ===================================================================

    /** @test */
    public function preview_lesson_never_gets_textbook_text(): void
    {
        Log::spy();
        $this->fakeSource();
        [$course, $lesson] = $this->scopedLessonWithRecording(['is_preview' => true]);

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled, 'Публичный урок обязан открыться несмотря на отказ подставить текст.');
        $this->assertStringNotContainsString(self::MARKER, (string) $lesson->homework_prompt, 'Текст учебника попал в публичный урок.');
        $this->assertSame(
            KocherginaExerciseSource::fallbackPrompt(1),
            $lesson->homework_prompt,
            'Ожидалась отсылочная формулировка.'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'D14'))
            ->atLeast()->once();
    }

    // ===================================================================
    // A8 — источник недоступен
    // ===================================================================

    /** @test */
    public function missing_source_does_not_block_opening(): void
    {
        config(['homework.auto_open.source_path' => 'C:/nope/does-not-exist.mdx']);
        KocherginaExerciseSource::flush();

        [$course, $lesson] = $this->scopedLessonWithRecording();

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled, 'Недоступный источник заблокировал открытие.');
        $this->assertSame(KocherginaExerciseSource::fallbackPrompt(1), $lesson->homework_prompt);
    }

    // ===================================================================
    // A9 — уведомление
    // ===================================================================

    /** @test */
    public function opening_pushes_once_and_sends_no_mail(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        [$course, $lesson] = $this->scopedLessonWithRecording();
        $student = $this->studentInGroupOf($lesson, telegramId: '778899');

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '778899'
            && str_contains($request['text'], 'Открылось домашнее задание'));

        // D9: письма после каждого занятия — шум, канала mail в контуре нет.
        Mail::assertNothingSent();
    }

    // ===================================================================
    // A10 — охват D4
    // ===================================================================

    /** @test */
    public function out_of_scope_lessons_are_untouched(): void
    {
        [$course, $inScope] = $this->scopedLessonWithRecording();

        // Тот же курс, но занятие учебника вне списка.
        $outByLesson = $this->lessonWithRecording($course, ['textbook_lesson' => 6]);

        // Курс вне охвата.
        $otherCourse = Course::factory()->create();
        $outByCourse = $this->lessonWithRecording($otherCourse, ['textbook_lesson' => 1]);

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open')->assertSuccessful();

        $this->assertTrue((bool) $inScope->fresh()->homework_enabled);
        $this->assertFalse((bool) $outByLesson->fresh()->homework_enabled, 'Открыт урок с занятием вне списка.');
        $this->assertFalse((bool) $outByCourse->fresh()->homework_enabled, 'Открыт урок курса вне охвата.');
    }

    // ===================================================================
    // A11 — серверный гейт
    // ===================================================================

    /** @test */
    public function submitting_to_closed_homework_is_rejected(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => true,
            'homework_enabled' => true,
            'homework_prompt' => 'Условие',
            'homework_closed_at' => now(),
        ]);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->post(route('student.homework.store', [$course->slug, $lesson->id]), [
                'action' => 'submit',
                'body' => 'Пытаюсь сдать в закрытый приём',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('homework_submissions', 0);
    }

    // ===================================================================
    // A12 — dry-run
    // ===================================================================

    /** @test */
    public function dry_run_changes_nothing(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        [$course, $lesson] = $this->scopedLessonWithRecording();
        $this->studentInGroupOf($lesson, telegramId: '112233');

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open --dry-run')
            ->expectsOutputToContain('--dry-run')
            ->assertSuccessful();

        $lesson->refresh();
        $this->assertFalse((bool) $lesson->homework_enabled, '--dry-run включил ДЗ.');
        $this->assertNull($lesson->homework_auto_opened_at, '--dry-run записал маркер владения.');
        $this->assertNull($lesson->homework_prompt, '--dry-run подставил условие.');
        Http::assertNothingSent();
    }

    // ===================================================================
    // A13 — бэкфилл D11
    // ===================================================================

    /** @test */
    public function backfill_touches_only_the_last_lesson(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $course = Course::factory()->create();
        config(['homework.auto_open.course_slugs' => [$course->slug]]);

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00', 'Europe/Moscow'));
        $older = $this->lessonWithRecording($course, ['textbook_lesson' => 1]);

        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00', 'Europe/Moscow'));
        $newest = $this->lessonWithRecording($course, ['textbook_lesson' => 2]);

        $this->studentInGroupOf($newest, telegramId: '445566');

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:30', 'Europe/Moscow'));
        $this->artisan('homework:auto-open --backfill-last')->assertSuccessful();

        $this->assertTrue((bool) $newest->fresh()->homework_enabled, 'Бэкфилл не открыл самый свежий урок.');
        $this->assertFalse((bool) $older->fresh()->homework_enabled, 'Бэкфилл воскресил историю дальше одного урока.');

        // D11: бэкфилл открывает молча — пуш задним числом не шлётся.
        Http::assertNothingSent();
    }

    // ===================================================================
    // Помощники
    // ===================================================================

    /**
     * Урок курса в охвате, у которого запись появилась во вторник в 06:00 МСК
     * (⇒ открытие в среду 09:00 по таблице краёв).
     *
     * @return array{0: Course, 1: Lesson}
     */
    private function scopedLessonWithRecording(array $attributes = []): array
    {
        $course = Course::factory()->create();
        config(['homework.auto_open.course_slugs' => [$course->slug]]);

        Carbon::setTestNow(Carbon::parse('2026-07-28 06:00', 'Europe/Moscow'));
        $lesson = $this->lessonWithRecording($course, $attributes + ['textbook_lesson' => 1]);

        return [$course, $lesson];
    }

    /** Урок с уже приложенной записью — отметку ставит хук `Lesson::saving`. */
    private function lessonWithRecording(Course $course, array $attributes = []): Lesson
    {
        return Lesson::factory()->for($course)->create($attributes + [
            'group_id' => Group::factory()->create()->id,
            'youtube_url' => 'https://youtu.be/'.uniqid(),
            'homework_enabled' => false,
        ]);
    }

    /** Активный студент группы урока — получатель пуша об открытии. */
    private function studentInGroupOf(Lesson $lesson, string $telegramId): User
    {
        $student = User::factory()->create(['telegram_id' => $telegramId, 'vk_id' => null]);
        Group::find($lesson->group_id)->users()->attach($student->id);

        return $student;
    }

    /**
     * Синтетический источник: повторяет СТРУКТУРУ оцифровки (заголовки занятий
     * римскими цифрами + блок «Упражнения»), но текста учебника не содержит.
     */
    private function fakeSource(): void
    {
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'kochergina_synthetic_').'.mdx';

        file_put_contents($this->sourcePath, implode("\n", [
            'Предисловие, к занятиям не относится.',
            '',
            'Занятие I',
            '',
            '1. Теоретическая часть, в блок упражнений не входит.',
            '',
            'Упражнения',
            '',
            'I. '.self::MARKER.' — первое.',
            'II. '.self::MARKER.' — второе.',
            '',
            'Занятие II',
            '',
            'Упражнения',
            '',
            'I. Упражнение второго занятия.',
            '',
        ]));

        config(['homework.auto_open.source_path' => $this->sourcePath]);
        KocherginaExerciseSource::flush();
    }
}
