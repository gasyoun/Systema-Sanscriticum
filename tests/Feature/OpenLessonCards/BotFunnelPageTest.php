<?php

declare(strict_types=1);

namespace Tests\Feature\OpenLessonCards;

use App\Filament\Resources\BotSubscriberResource;
use App\Filament\Resources\BotSubscriberResource\Pages\ListBotSubscribers;
use App\Filament\Resources\BotSubscriberResource\Widgets\BotFunnelStatsWidget;
use App\Filament\Resources\BotSubscriberResource\Widgets\OpenLessonSendsWidget;
use App\Models\BotSubscriber;
use App\Models\Lead;
use App\Models\OpenLessonCardSend;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;
use App\Models\User;
use App\Services\OpenLessonCards\OpenLessonFunnelReport;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Страница «Маркетинг → Воронка @samskrte_bot»: цифры воронки, подписчики, рассылки по занятиям. */
class BotFunnelPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function subscriber(string $chatId, array $attrs = []): BotSubscriber
    {
        return BotSubscriber::create(['chat_id' => $chatId, 'source' => BotSubscriber::SOURCE_START] + $attrs);
    }

    private function seedFunnel(): void
    {
        $lead = Lead::create(['name' => 'Анна Петрова', 'contact' => '@anya', 'telegram_chat_id' => 4, 'status' => 'new']);

        $this->subscriber('1', ['first_name' => 'Лурдес']);
        $this->subscriber('2', ['source' => BotSubscriber::SOURCE_LEAD, 'blocked_at' => now()]);
        $this->subscriber('3', ['join_step' => BotSubscriber::STEP_SUBSCRIBE]);
        $this->subscriber('4', ['join_step' => BotSubscriber::STEP_DONE, 'join_name' => 'Анна Петрова', 'username' => 'anya',
            'consent_at' => now(), 'joined_at' => now(), 'lead_id' => $lead->id]);
    }

    public function test_report_counts_each_step_of_the_funnel(): void
    {
        $this->seedFunnel();

        $steps = collect(app(OpenLessonFunnelReport::class)->steps())->keyBy('key');

        $this->assertSame(4, $steps['audience']['count']);
        $this->assertNull($steps['audience']['percent']);
        $this->assertSame(2, $steps['join']['count']);
        $this->assertSame(50, $steps['join']['percent']);
        $this->assertSame(1, $steps['consent']['count']);
        $this->assertSame(1, $steps['joined']['count']);
        $this->assertSame(1, $steps['lead']['count']);
        $this->assertSame(100, $steps['lead']['percent']);
        $this->assertSame(['started' => 3, 'blocked' => 1], app(OpenLessonFunnelReport::class)->audience());
    }

    public function test_empty_audience_does_not_divide_by_zero(): void
    {
        $steps = app(OpenLessonFunnelReport::class)->steps();

        $this->assertSame([null, 0, 0, 0, 0], array_column($steps, 'percent'));
    }

    public function test_admin_sees_subscribers_with_step_and_block_status(): void
    {
        $this->seedFunnel();
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(ListBotSubscribers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(BotSubscriber::all())
            ->assertSee('Лурдес')
            ->assertSee('Анна Петрова')
            ->assertSee('Анкета заполнена')
            ->assertSee('Подписка на канал')
            ->filterTable('blocked', true)
            ->assertCanSeeTableRecords(BotSubscriber::query()->where('chat_id', '2')->get())
            ->assertCanNotSeeTableRecords(BotSubscriber::query()->where('chat_id', '!=', '2')->get());

        Livewire::test(BotFunnelStatsWidget::class)
            ->assertSee('Нажали «Участвовать»')
            ->assertSee('50% от предыдущего шага')
            ->assertSee('заблокировали: 1');
    }

    public function test_lessons_widget_counts_delivered_cards_and_answers(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $lesson = Schedule::create(['title' => 'Открытое занятие по Гите', 'start' => now()->addDays(2), 'bot_card_enabled' => true]);
        Schedule::create(['title' => 'Обычное занятие группы', 'start' => now()->addDays(2)]);

        foreach (['1', '2', '3'] as $chat) {
            OpenLessonCardSend::create(['schedule_id' => $lesson->id, 'chat_id' => $chat, 'stage' => OpenLessonCardSend::STAGE_ANNOUNCE, 'sent_at' => now()]);
        }
        // Заявка на отправку без sent_at (в полёте или потеряна) — не доставлено.
        OpenLessonCardSend::create(['schedule_id' => $lesson->id, 'chat_id' => '4', 'stage' => OpenLessonCardSend::STAGE_ANNOUNCE]);
        OpenLessonRsvp::create(['schedule_id' => $lesson->id, 'chat_id' => '1', 'going_at' => now()]);
        OpenLessonRsvp::create(['schedule_id' => $lesson->id, 'chat_id' => '2', 'declined_at' => now()]);

        Livewire::test(OpenLessonSendsWidget::class)
            ->assertCanSeeTableRecords([$lesson])
            ->assertCanNotSeeTableRecords(Schedule::query()->where('bot_card_enabled', false)->get())
            ->assertTableColumnStateSet('announce_count', 3, $lesson)
            ->assertTableColumnStateSet('morning_count', 0, $lesson)
            ->assertTableColumnStateSet('going_count', 1, $lesson)
            ->assertTableColumnStateSet('declined_count', 1, $lesson);
    }

    public function test_page_is_admin_only_and_read_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(BotSubscriberResource::canViewAny());
        $this->get(BotSubscriberResource::getUrl('index'))->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->assertTrue(BotSubscriberResource::canViewAny());
        $this->assertFalse(BotSubscriberResource::canCreate());
        $this->assertFalse(BotSubscriberResource::canEdit($this->subscriber('9')));
    }
}
