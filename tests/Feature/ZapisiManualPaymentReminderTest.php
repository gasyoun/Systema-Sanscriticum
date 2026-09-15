<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Telegram\SlotNoticeService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Кнопка «Напомнить в чат об оплате» (Расписание / Группы): черновик текста
 * по положению в блоке, ручная отправка через @zapisi_ORSbot, защита от
 * повторного нажатия тем же текстом.
 */
class ZapisiManualPaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100777';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.telegram_zapisi_bot' => true]);
        Carbon::setTestNow('2026-09-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function group(?string $chatId = self::CHAT_ID, string $name = 'Грамматика гр.61'): Group
    {
        return Group::create(['name' => $name, 'telegram_chat_id' => $chatId]);
    }

    private function lesson(Group $group, int $n, string $date): Schedule
    {
        return Schedule::create([
            'title' => "Грамматика гр.61 (#{$n}, ".Carbon::parse($date)->format('d.m.y').')',
            'start' => "{$date} 08:00:00",
            'end' => "{$date} 10:00:00",
            'group_id' => $group->id,
        ]);
    }

    private function admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
    }

    public function test_draft_after_block_end_matches_auto_reminder(): void
    {
        $group = $this->group();
        $this->lesson($group, 7, '2026-09-07');
        $this->lesson($group, 8, '2026-09-14');
        $this->lesson($group, 9, '2026-09-21');

        $draft = app(SlotNoticeService::class)->paymentDraft($group, now());

        $this->assertNotNull($draft);
        $this->assertStringContainsString('Блок занятий завершён — 8-е из 9', $draft['text']);
        $this->assertStringContainsString('21.09.2026', $draft['text']);
        $this->assertStringContainsString('игры, просмотр бесплатных вебинаров', $draft['text']);
    }

    public function test_draft_mid_block_names_current_block(): void
    {
        $group = $this->group();
        $this->lesson($group, 5, '2026-09-14');
        $this->lesson($group, 6, '2026-09-21');

        $draft = app(SlotNoticeService::class)->paymentDraft($group, now());

        $this->assertStringContainsString('Напоминание об оплате', $draft['text']);
        $this->assertStringContainsString('Идёт 2-й блок занятий', $draft['text']);
        $this->assertStringContainsString('до <b>21.09.2026</b>', $draft['text']);
    }

    public function test_draft_before_first_lesson_says_block_starts(): void
    {
        $group = $this->group();
        $this->lesson($group, 1, '2026-09-21');

        $draft = app(SlotNoticeService::class)->paymentDraft($group, now());

        $this->assertStringContainsString('Начинается 1-й блок занятий', $draft['text']);
    }

    public function test_no_draft_when_stream_is_over(): void
    {
        $group = $this->group();
        $this->lesson($group, 12, '2026-09-14');

        $this->assertNull(app(SlotNoticeService::class)->paymentDraft($group, now()));
    }

    public function test_schedule_anchor_uses_that_lesson_not_today(): void
    {
        $group = $this->group();
        $block = $this->lesson($group, 8, '2026-09-14');
        $this->lesson($group, 9, '2026-09-21');

        $draft = app(SlotNoticeService::class)->paymentDraft($group, $block->end);

        $this->assertStringContainsString('8-е из 9', $draft['text']);
    }

    public function test_send_manual_queues_normalised_text(): void
    {
        Redis::shouldReceive('exists')->once()->andReturn(0);
        $group = $this->group();

        $status = app(SlotNoticeService::class)->sendManual($group, "  Строка 1\r\nСтрока 2  ");

        $this->assertSame(SlotNoticeService::MANUAL_QUEUED, $status);
        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === self::CHAT_ID
            && $job->text === "Строка 1\nСтрока 2");
    }

    public function test_send_manual_refuses_same_text_within_dedup_window(): void
    {
        Redis::shouldReceive('exists')->once()->andReturn(1);

        $status = app(SlotNoticeService::class)->sendManual($this->group(), 'Оплата до 21.09');

        $this->assertSame(SlotNoticeService::MANUAL_DUPLICATE, $status);
        Queue::assertNothingPushed();
    }

    public function test_send_manual_without_chat(): void
    {
        $status = app(SlotNoticeService::class)->sendManual($this->group(null), 'Оплата до 21.09');

        $this->assertSame(SlotNoticeService::MANUAL_NO_CHAT, $status);
        Queue::assertNothingPushed();
    }

    public function test_schedule_button_prefills_draft_and_sends(): void
    {
        $this->admin();
        Redis::shouldReceive('exists')->once()->andReturn(0);
        $group = $this->group();
        $block = $this->lesson($group, 8, '2026-09-14');
        $this->lesson($group, 9, '2026-09-21');
        $expected = app(SlotNoticeService::class)->paymentDraft($group, $block->end)['text'];

        Livewire::test(ListSchedules::class)
            ->filterTable('time_range', false) // занятие 14.09 уже прошло, по умолчанию список — только будущие
            ->mountTableAction('remind_payment_in_chat', $block)
            ->assertTableActionDataSet(['text' => $expected])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->chatId === self::CHAT_ID
            && str_contains($job->text, '8-е из 9'));
    }

    public function test_group_button_sends_edited_text(): void
    {
        $this->admin();
        Redis::shouldReceive('exists')->once()->andReturn(0);
        $group = $this->group();
        $this->lesson($group, 9, '2026-09-21');

        Livewire::test(ListGroups::class)
            ->callTableAction('remind_payment_in_chat', $group, data: ['text' => 'Коллеги, оплата до 21.09'])
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(SendZapisiBotMessageJob::class, fn (SendZapisiBotMessageJob $job): bool => $job->text === 'Коллеги, оплата до 21.09');
    }

    public function test_button_hidden_without_chat_or_with_bot_off(): void
    {
        $this->admin();
        $noChat = $this->group(null);
        $row = $this->lesson($noChat, 8, '2026-09-21');

        Livewire::test(ListSchedules::class)->assertTableActionHidden('remind_payment_in_chat', $row);
        Livewire::test(ListGroups::class)->assertTableActionHidden('remind_payment_in_chat', $noChat);

        config(['features.telegram_zapisi_bot' => false]);
        $withChat = $this->group(self::CHAT_ID, 'Грамматика гр.62');

        Livewire::test(ListGroups::class)->assertTableActionHidden('remind_payment_in_chat', $withChat);
    }

    public function test_button_visible_for_admin_with_chat(): void
    {
        $this->admin();
        $group = $this->group();
        $row = $this->lesson($group, 8, '2026-09-21');

        Livewire::test(ListSchedules::class)->assertTableActionVisible('remind_payment_in_chat', $row);
        Livewire::test(ListGroups::class)->assertTableActionVisible('remind_payment_in_chat', $group);
    }
}
