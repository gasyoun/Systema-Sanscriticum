<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Filament\Resources\LessonAiEventResource;
use App\Filament\Resources\LessonAiEventResource\Pages\ListLessonAiEvents;
use App\Models\Consent;
use App\Models\LessonAiEvent;
use App\Models\User;
use App\Services\Consent\ConsentRecorder;
use App\Services\Knowledge\LessonAiEventLog;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Журнал запросов к ИИ-помощнику занятия (тест «Спроси Сократа»).
 *
 * Пины приватности, а не только удобства:
 *  - текст вопроса/фрагмента/ответа хранится ТОЛЬКО у тестировщика с
 *    действующим согласием ai_test; после отзыва согласия — уже нет;
 *  - у остальных остаются счётчики, и это сознательно: журнал не должен
 *    превращаться в слежку за теми, кто тест не выбирал;
 *  - заметки к занятию текстом не хранятся никогда — только длина;
 *  - журнал чистится по сроку хранения (30 дней), и это в расписании;
 *  - страница админки — только RoleGate::adminOnly().
 */
class LessonAiEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-24 13:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_texts_are_stored_for_consenting_tester(): void
    {
        $tester = User::factory()->create(['lesson_ai_tester' => true]);
        $this->giveConsent($tester);

        app(LessonAiEventLog::class)->record($tester->id, 17, 'explain', LessonAiEvent::STATUS_DONE, [
            'question' => 'что такое висарга?',
            'selection' => 'висарга',
            'answer_md' => 'Висарга — придыхание в конце слога.',
            'notes_chars' => 137,
            'latency_ms' => 4200,
            'source' => LessonAiEvent::SOURCE_MODEL,
        ]);

        $event = LessonAiEvent::query()->sole();
        $this->assertSame($tester->id, $event->user_id);
        $this->assertSame(17, $event->lesson_id);
        $this->assertSame('explain', $event->task);
        $this->assertSame(LessonAiEvent::STATUS_DONE, $event->status);
        $this->assertSame('что такое висарга?', $event->question);
        $this->assertSame('висарга', $event->selection);
        $this->assertStringContainsString('Висарга', (string) $event->answer_md);
        $this->assertSame(137, $event->notes_chars);
        $this->assertSame(4200, $event->latency_ms);
    }

    public function test_texts_are_dropped_without_consent_even_for_tester(): void
    {
        // Тестировщик по флагу, но согласие не давал (или это ставил админ руками).
        $user = User::factory()->create(['lesson_ai_tester' => true]);

        app(LessonAiEventLog::class)->record($user->id, 17, 'ask', LessonAiEvent::STATUS_DONE, [
            'question' => 'когда следующее занятие?',
            'answer_md' => 'Ответ модели.',
        ]);

        $event = LessonAiEvent::query()->sole();
        $this->assertNull($event->question);
        $this->assertNull($event->selection);
        $this->assertNull($event->answer_md);
        // Счётчики при этом есть — иначе нечем мерить объём теста.
        $this->assertSame('ask', $event->task);
        $this->assertSame(LessonAiEvent::STATUS_DONE, $event->status);
    }

    public function test_withdrawn_consent_stops_text_storage(): void
    {
        $tester = User::factory()->create(['lesson_ai_tester' => true]);
        $this->giveConsent($tester);

        app(ConsentRecorder::class)->withdrawn(Consent::TYPE_AI_TEST, 'test', null, $tester);

        app(LessonAiEventLog::class)->record($tester->id, 17, 'explain', LessonAiEvent::STATUS_DONE, [
            'question' => 'что такое сандхи?',
            'answer_md' => 'Слияние звуков.',
        ]);

        $this->assertNull(LessonAiEvent::query()->sole()->answer_md);
    }

    public function test_plain_student_gets_counters_only(): void
    {
        $student = User::factory()->create();

        app(LessonAiEventLog::class)->record($student->id, 17, 'summary', LessonAiEvent::STATUS_ERROR, [
            'error_code' => 'offline',
        ]);

        $event = LessonAiEvent::query()->sole();
        $this->assertSame('offline', $event->error_code);
        $this->assertNull($event->answer_md);
    }

    public function test_notes_text_is_never_stored_as_a_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('lesson_ai_events', 'notes'),
            'В журнале не должно быть колонки с текстом заметок — только notes_chars.',
        );
    }

    public function test_purge_removes_events_older_than_retention(): void
    {
        $user = User::factory()->create();

        $old = new LessonAiEvent([
            'user_id' => $user->id,
            'lesson_id' => 17,
            'task' => 'summary',
            'status' => LessonAiEvent::STATUS_DONE,
            'source' => LessonAiEvent::SOURCE_MODEL,
        ]);
        $old->created_at = Carbon::now()->subDays(40);
        $old->save();

        $fresh = new LessonAiEvent([
            'user_id' => $user->id,
            'lesson_id' => 17,
            'task' => 'summary',
            'status' => LessonAiEvent::STATUS_DONE,
            'source' => LessonAiEvent::SOURCE_MODEL,
        ]);
        $fresh->created_at = Carbon::now()->subDays(3);
        $fresh->save();

        $this->artisan('lesson-ai:purge-events')->assertSuccessful();

        $this->assertSame([$fresh->id], LessonAiEvent::query()->pluck('id')->all());
    }

    public function test_purge_dry_run_keeps_rows(): void
    {
        $user = User::factory()->create();

        $old = new LessonAiEvent([
            'user_id' => $user->id,
            'task' => 'summary',
            'status' => LessonAiEvent::STATUS_DONE,
        ]);
        $old->created_at = Carbon::now()->subDays(90);
        $old->save();

        $this->artisan('lesson-ai:purge-events --dry')->assertSuccessful();

        $this->assertSame(1, LessonAiEvent::query()->count());
    }

    public function test_page_is_admin_only(): void
    {
        $student = User::factory()->create();
        $this->actingAs($student);
        $this->assertFalse(LessonAiEventResource::canViewAny());

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $this->actingAs($admin);
        $this->assertTrue(LessonAiEventResource::canViewAny());
    }

    /**
     * Страница обязана РЕНДЕРИТЬСЯ, а не просто существовать: 24-11-2026 прод
     * отдал 500 на /admin/lesson-ai-events, потому что в фильтре периода стоял
     * `Forms\Components\DatePicker` без импорта `Filament\Forms` — статический
     * вызов класса, которого нет. Тест поднимает настоящую таблицу с настоящей
     * строкой, поэтому ловит и такие ошибки неймспейсов, и падения колонок.
     */
    public function test_page_renders_for_admin_with_a_row(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
        $tester = User::factory()->create(['name' => 'Анна Тестова', 'lesson_ai_tester' => true]);
        $this->giveConsent($tester);

        app(LessonAiEventLog::class)->record($tester->id, 17, 'explain', LessonAiEvent::STATUS_DONE, [
            'question' => 'что такое висарга?',
            'selection' => 'висарга',
            'answer_md' => 'Придыхание в конце слога.',
            'notes_chars' => 42,
            'latency_ms' => 1200,
        ]);

        Livewire::actingAs($admin)
            ->test(ListLessonAiEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(LessonAiEvent::query()->get());
    }

    private function giveConsent(User $user): void
    {
        app(ConsentRecorder::class)->given(Consent::TYPE_AI_TEST, 'test', null, $user);
    }
}
