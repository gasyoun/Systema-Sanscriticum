<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\PublishCalendarSlotJob;
use App\Models\ContentCalendarSlot;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\CalendarPublicationRunner;
use App\Services\Content\CalendarPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * H8643: the course-post completeness gate sits in the single pre-send choke
 * point CalendarPublishService::passesChecklist() — a non-compliant series post
 * is held as draft exactly like a §2.8 hit, a compliant one is dispatched.
 */
class CoursePostCompletenessGateTest extends TestCase
{
    use RefreshDatabase;

    /** ОРИГИНАЛ t.me/samskrtamru/4371 (27-11-2026) дословно. */
    private const RED_4371 = <<<'TXT'
        🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ

        В учебном упражнении попалась фраза о том, что глаза — под бровями. Глагол в ней тот, что в словаре стоит как вартате (vartate).

        Русскому уху сразу слышится «вертеться». Значение «вертеться, вращаться» у этого глагола и правда есть. Так и хочется перевести: «глаза вертятся под бровями».

        Но у вартате есть и другое значение — «находиться, пребывать». Здесь нужно именно оно: глаза просто находятся под бровями.

        Преподаватель заметил, что фраза как раз и ловит на знакомое «вертеться».

        Полезно помнить: если значение слова кажется знакомым, сверьте его со смыслом всей фразы.

        Как проходят занятия, можно увидеть в записи открытого занятия.

        👉 samskrte.ru/online/poprobovat

        #санскрит #ловушка
        TXT;

    /** Исправленный черновик САХИТЬЯ (текст поста) — позитивный контроль. */
    private const GREEN_2382 = <<<'TXT'
        ❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ

        На курсе «Санскритская литература», на занятии о поэтике, спросили:
        встретилось выражение «литература — сахитья», что оно значит?

        Преподаватель курса — Максим Леонов, переводчик санскритской литературы,
        исследователь индийской философии.

        Ответ с занятия: сахитья — это соединение. В литературе гармонично
        соединяются слово и смысл, форма и содержание. Это одно из определений
        кавьи, поэтического произведения.

        В Большом петербургском словаре: साहित्य sāhitya — от sahita «соединённый».
        Два смысла: 1〉 «соединение, сочетание; согласованность»; 2〉 «поэтическое
        произведение, искусство поэзии».

        Курс «Санскритская литература» прошёл в 2025 году — сегодня доступен
        только в записи: тексты эпоса, литературы и поэзии в историческом
        контексте. Доступ к записям бессрочный.

        #вопрос #санскрит #литературоведение #леонов #санскритская_литература
        TXT;

    /** Как звучит удержание гейтом в meta.course_post_hold и в логе. */
    private const HOLD_PREFIX = 'course-post-hold: C1 титул: ';

    protected function setUp(): void
    {
        parent::setUp();

        // Без площадок тик ничего не трогает (CalendarPublishService::publishDue).
        config([
            'content_calendar.destinations' => [
                'tg_a' => ['platform' => 'telegram', 'label' => 'TG A', 'chat_id' => '@chan_a'],
                'vk_a' => ['platform' => 'vk', 'label' => 'VK A', 'group_id' => 111],
            ],
        ]);
    }

    public function test_gate_is_switched_on_by_default(): void
    {
        $this->assertTrue(config('content_calendar.course_post_gate'), 'fail-closed default');
    }

    public function test_non_compliant_trap_slot_is_held_as_draft_and_never_dispatched(): void
    {
        Bus::fake([PublishCalendarSlotJob::class]);
        Log::spy();
        $slot = $this->slot(['title' => '🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ', 'body' => $this->redBody()]);

        $result = app(CalendarPublishService::class)->publishDue();

        $this->assertSame(['dispatched' => 0, 'held' => 1, 'skipped_no_destinations' => false], $result);
        Bus::assertNotDispatched(PublishCalendarSlotJob::class);

        $held = $slot->fresh();
        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $held->status, 'held for a human edit, not retried every tick');
        $this->assertStringStartsWith(self::HOLD_PREFIX, (string) $held->meta['course_post_hold']);
        foreach (['C3 ссылка: ', 'C4 тэги: ', 'C5 доступ: ', 'C7 якорь: '] as $needle) {
            $this->assertStringContainsString($needle, (string) $held->meta['course_post_hold']);
        }
        $this->assertStringNotContainsString("\n", (string) $held->meta['course_post_hold']);
        $this->assertArrayHasKey('course_post_hold_at', $held->meta);
        $this->assertArrayNotHasKey('prohibition_hold', $held->meta, 'the §2.8 verdict passed — the hold comes from the course-post gate');
        $this->assertArrayNotHasKey('checklist_passed_at', $held->meta, 'unstamped: a re-scheduled slot runs through both checks again');

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'CalendarPublishService: слот удержан гейтом постов курсов'
                && $context['calendar_slot_id'] === $slot->id
                && str_starts_with($context['hold'], self::HOLD_PREFIX),
        );

        // Второй тик: слот в draft, тик его не берёт — ни повторного удержания, ни отправки.
        $this->assertSame(['dispatched' => 0, 'held' => 0, 'skipped_no_destinations' => false], app(CalendarPublishService::class)->publishDue());
        Bus::assertNotDispatched(PublishCalendarSlotJob::class);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_compliant_series_slot_is_dispatched_and_the_gate_stamps_nothing(): void
    {
        Bus::fake([PublishCalendarSlotJob::class]);
        Log::spy();
        $slot = $this->slot(['title' => '❓ ВОПРОС ИЗ КЛАССА · САХИТЬЯ', 'body' => $this->greenBody()]);

        $result = app(CalendarPublishService::class)->publishDue();

        $this->assertSame(['dispatched' => 1, 'held' => 0, 'skipped_no_destinations' => false], $result);
        Bus::assertDispatched(PublishCalendarSlotJob::class, fn (PublishCalendarSlotJob $job): bool => $job->slotId === $slot->id);

        $fresh = $slot->fresh();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $fresh->status, 'the job is faked — the slot only got dispatched');
        $this->assertArrayNotHasKey('course_post_hold', $fresh->meta);
        $this->assertArrayNotHasKey('course_post_hold_at', $fresh->meta);
        $this->assertArrayNotHasKey('prohibition_hold', $fresh->meta);
        $this->assertArrayHasKey('checklist_passed_at', $fresh->meta, 'the pre-existing one-time stamp of the passed checklist');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_kill_switch_off_holds_nothing(): void
    {
        config(['content_calendar.course_post_gate' => false]);
        Bus::fake([PublishCalendarSlotJob::class]);
        Log::spy();
        $slot = $this->slot(['title' => '🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ', 'body' => $this->redBody()]);

        $result = app(CalendarPublishService::class)->publishDue();

        $this->assertSame(['dispatched' => 1, 'held' => 0, 'skipped_no_destinations' => false], $result);
        Bus::assertDispatched(PublishCalendarSlotJob::class, fn (PublishCalendarSlotJob $job): bool => $job->slotId === $slot->id);

        $fresh = $slot->fresh();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $fresh->status);
        $this->assertArrayNotHasKey('course_post_hold', $fresh->meta);
        $this->assertArrayNotHasKey('course_post_hold_at', $fresh->meta);
        $this->assertArrayHasKey('checklist_passed_at', $fresh->meta);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Путь TG-ингеста: слот минует content:publish-due и проходит чек-лист внутри
     * джобы. До правки 27-11-2026 джоба читала причину удержания только из
     * meta.prohibition_hold — удержание гейтом падало с «Undefined array key»,
     * админы не узнавали бы о нём (само удержание к тому моменту уже сохранено).
     */
    public function test_telegram_ingest_path_notifies_admins_with_the_course_post_hold(): void
    {
        Log::spy();
        $slot = $this->slot(['title' => '🪤 ЛОВУШКА · ГЛАЗА НЕ ВЕРТЯТСЯ', 'body' => $this->redBody()]);

        $notifier = $this->mock(TelegramAdminNotifier::class);
        $notifier->expects('notifyAdmins')->once()->withArgs(
            fn (string $message): bool => str_contains($message, "слот #{$slot->id}: пост не разнесён по площадкам — ".self::HOLD_PREFIX),
        );
        $this->mock(CalendarPublicationRunner::class)->shouldNotReceive('run');

        (new PublishCalendarSlotJob($slot->id))->handle(
            app(CalendarPublicationRunner::class),
            $notifier,
            app(CalendarPublishService::class),
        );

        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $slot->fresh()->status, 'held, nothing went to any destination');
        Log::shouldHaveReceived('warning')->once();
    }

    /** Тот же путь, прежняя ветка: удержание чек-листом §2.8 по-прежнему доходит до админов. */
    public function test_telegram_ingest_path_still_reports_the_prohibition_hold(): void
    {
        Log::spy();
        $slot = $this->slot(['title' => 'Слово дня', 'body' => '<p>Напишите на ivan@example.com</p>']);

        $notifier = $this->mock(TelegramAdminNotifier::class);
        $notifier->expects('notifyAdmins')->once()->withArgs(
            fn (string $message): bool => str_contains($message, 'площадкам — prohibition-hold §2.8: crm_personal_data'),
        );
        $this->mock(CalendarPublicationRunner::class)->shouldNotReceive('run');

        (new PublishCalendarSlotJob($slot->id))->handle(
            app(CalendarPublicationRunner::class),
            $notifier,
            app(CalendarPublishService::class),
        );

        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $slot->fresh()->status);
    }

    private function slot(array $overrides = []): ContentCalendarSlot
    {
        return ContentCalendarSlot::create(array_merge([
            'slot_date' => now()->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_MANUAL,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => now()->subMinutes(5),
        ], $overrides));
    }

    /** Оригинал 4371 как тело слота; единственная ссылка поста — как в оригинале, в href. */
    private function redBody(): string
    {
        return str_replace(
            '👉 samskrte.ru/online/poprobovat',
            '👉 <a href="http://samskrte.ru/online/poprobovat">samskrte.ru/online/poprobovat</a>',
            self::html(self::RED_4371),
        );
    }

    /** Исправленный черновик как тело слота: якорь преподавателя и ключ курса — в href. */
    private function greenBody(): string
    {
        return str_replace(
            ['Преподаватель курса — Максим Леонов,', 'На курсе «Санскритская литература»,'],
            [
                'Преподаватель курса — <a href="https://samskrte.ru/prepodavately/leonov-maksim">Максим Леонов</a>,',
                'На курсе «<a href="https://samskrte.ru/ga/sl25-inst-p">Санскритская литература</a>»,',
            ],
            self::html(self::GREEN_2382),
        );
    }

    /** Текст поста → HTML слота (Tiptap): абзац — <p>, строки абзаца — <br>. */
    private static function html(string $text): string
    {
        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'.str_replace("\n", '<br>', htmlspecialchars($paragraph, ENT_NOQUOTES, 'UTF-8')).'</p>',
            preg_split('/\n\s*\n/', trim($text)) ?: [],
        ));
    }
}
