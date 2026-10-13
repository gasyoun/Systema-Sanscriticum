<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\VisitorsOnline;
use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\SupportDailyRollup;
use App\Models\SupportVisitorPresence;
use App\Models\User;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportParityReportBuilder;
use App\Support\Roles;
use App\Support\SupportProactiveGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H6601 — проактивные приглашения на горячих страницах в рабочие часы.
 *
 * Гейт (SupportProactiveGate): «Написать первым» активно только для
 * посетителей горячих страниц (лендинг мини-курса, витрина /online+/shop,
 * оплата /checkout) и только в рабочие часы (config/support_hours.php);
 * сервер отказывает fail-closed независимо от UI. Каждая проактивная запись
 * помечается chat_messages.is_proactive, дневной rollup хранит
 * proactive_count/proactive_converted, parity-срез показывает конверсию
 * «проактив → входящее».
 */
class SupportProactiveInvitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.support_visitor_presence', true);
        config()->set('support_hours.enabled', false);
    }

    private function hotPresence(array $attrs = []): SupportVisitorPresence
    {
        return SupportVisitorPresence::create(array_merge([
            'guest_token' => 'tok-'.bin2hex(random_bytes(6)),
            'current_url' => 'https://samskrte.ru/k/podgotovitelnaya-gruppa-sanskrita',
            'first_seen_at' => now()->subMinutes(3),
            'last_seen_at' => now()->subSeconds(10),
        ], $attrs));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    public function test_page_matcher_covers_three_hot_pages_and_rejects_others(): void
    {
        // Конфиг-дефолт: три горячих поверхности руки ( /shop держим из-за 301-легаси).
        config()->set('support_presence.proactive.hot_paths',
            ['/k/podgotovitelnaya-gruppa-sanskrita', '/online', '/shop', '/checkout']);

        $this->assertTrue(SupportProactiveGate::pageMatches('https://samskrte.ru/k/podgotovitelnaya-gruppa-sanskrita'));
        $this->assertTrue(SupportProactiveGate::pageMatches('https://samskrte.ru/shop'));
        $this->assertTrue(SupportProactiveGate::pageMatches('https://samskrte.ru/online'));
        $this->assertTrue(SupportProactiveGate::pageMatches('https://samskrte.ru/online/put'));
        $this->assertTrue(SupportProactiveGate::pageMatches('https://samskrte.ru/checkout/full?promo=1'));

        $this->assertFalse(SupportProactiveGate::pageMatches('https://samskrte.ru/kursy'), 'обычный каталог — не горячая');
        $this->assertFalse(SupportProactiveGate::pageMatches('https://samskrte.ru/shopper'), 'префикс не «съедает» чужие слова');
        $this->assertFalse(SupportProactiveGate::pageMatches(null));
        $this->assertFalse(SupportProactiveGate::pageMatches('https://cabinet.samskrte.ru/cabinet'), 'кабинет — не витрина');
    }

    public function test_empty_hot_paths_fails_closed(): void
    {
        config()->set('support_presence.proactive.hot_paths', []);

        $this->assertFalse(SupportProactiveGate::pageMatches('https://samskrte.ru/online'));
        $this->assertSame(
            'Проактив выключен (список горячих страниц пуст)',
            SupportProactiveGate::refusalReason($this->hotPresence()),
        );
    }

    public function test_proactive_blocked_off_hot_page(): void
    {
        $presence = $this->hotPresence(['guest_token' => 'tok-cold', 'current_url' => 'https://samskrte.ru/kursy']);

        Livewire::actingAs($this->admin())
            ->test(VisitorsOnline::class)
            ->call('startCompose', $presence->id)
            ->set('proactiveMessage', 'Здравствуйте!')
            ->call('sendProactive');

        $this->assertSame(0, ChatMessage::count(), 'off-hot-page проактив не пишет сообщение');
    }

    public function test_proactive_blocked_outside_working_hours(): void
    {
        // Вторник 08:00 по Москве — окно 10:00–20:00 ещё не открыто.
        config()->set('support_hours.enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-10-13 08:00', 'Europe/Moscow'));

        try {
            $presence = $this->hotPresence(['guest_token' => 'tok-early']);

            Livewire::actingAs($this->admin())
                ->test(VisitorsOnline::class)
                ->call('startCompose', $presence->id)
                ->set('proactiveMessage', 'Доброе утро!')
                ->call('sendProactive');

            $this->assertSame(0, ChatMessage::count(), 'вне рабочих часов проактив отказывает');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_proactive_on_hot_page_during_hours_is_flagged_is_proactive(): void
    {
        // Вторник 12:00 по Москве — внутри окна 10:00–20:00.
        config()->set('support_hours.enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00', 'Europe/Moscow'));

        try {
            $presence = $this->hotPresence(['guest_token' => 'tok-live',
                'current_url' => 'https://samskrte.ru/checkout/full']);

            Livewire::actingAs($this->admin())
                ->test(VisitorsOnline::class)
                ->call('startCompose', $presence->id)
                ->set('proactiveMessage', 'Здравствуйте! Помочь с оформлением?')
                ->call('sendProactive');

            $thread = SupportConversation::where('guest_token', 'tok-live')->firstOrFail();
            $this->assertDatabaseHas('chat_messages', [
                'support_conversation_id' => $thread->id,
                'role' => 'curator',
                'is_proactive' => true,
                'text' => 'Здравствуйте! Помочь с оформлением?',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_regular_curator_reply_is_not_flagged_proactive(): void
    {
        // Обычный ответ в существующем треде (не через VisitorsOnline) не должен
        // попадать в метрику проактива: флаг ставит только проактивный путь.
        $presence = $this->hotPresence(['guest_token' => 'tok-plain']);
        $thread = app(SupportConversationManager::class)->openForGuest($presence->guest_token);

        ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'role' => 'curator',
            'text' => 'Ответ в диалоге',
            'is_read' => true,
        ]);

        $this->assertSame(0, ChatMessage::query()->where('is_proactive', true)->count(), 'обычный ответ не в метрике проактива');
        $this->assertSame(1, ChatMessage::query()->where('is_proactive', false)->count());
    }

    public function test_rollup_counts_proactive_and_conversion(): void
    {
        config()->set('features.support_web_rollups', false); // команда пускается через --force

        $presence = $this->hotPresence(['guest_token' => 'tok-roll']);
        $thread = app(SupportConversationManager::class)->openForGuest($presence->guest_token);

        // Фиксируем часы, чтобы created_at обеих записей различались заметно
        // (секундная точность DATETIME иначе съедает интервал «строго позже»).
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00', 'UTC'));
        try {
            ChatMessage::create([
                'support_conversation_id' => $thread->id,
                'role' => 'curator',
                'text' => 'Здравствуйте! Подсказать?',
                'is_read' => true,
                'is_proactive' => true,
            ]);

            // Посетитель ответил через 4 часа — конверсия внутри того же дня.
            Carbon::setTestNow(Carbon::parse('2026-10-13 14:00', 'UTC'));
            ChatMessage::create([
                'support_conversation_id' => $thread->id,
                'role' => 'user',
                'text' => 'Да, расскажите про группу',
            ]);

            $this->artisan('support:rollup-web', ['--date' => '2026-10-13', '--force' => true]);

            $rollup = SupportDailyRollup::query()
                ->where('support_conversation_id', $thread->id)
                ->whereDate('conversation_date', '2026-10-13')
                ->firstOrFail();

            $this->assertSame(1, $rollup->proactive_count);
            $this->assertTrue((bool) $rollup->proactive_converted, 'ответ visitor после проактива = конверсия');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rollup_unconverted_without_visitor_reply(): void
    {
        $presence = $this->hotPresence(['guest_token' => 'tok-noreply']);
        $thread = app(SupportConversationManager::class)->openForGuest($presence->guest_token);

        ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'role' => 'curator',
            'text' => 'Молчание',
            'is_read' => true,
            'is_proactive' => true,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('support:rollup-web', ['--date' => now()->toDateString(), '--force' => true]);

        $rollup = SupportDailyRollup::query()
            ->where('support_conversation_id', $thread->id)
            ->firstOrFail();

        $this->assertSame(1, $rollup->proactive_count);
        $this->assertFalse((bool) $rollup->proactive_converted);
    }

    public function test_parity_report_carries_proactive_metric(): void
    {
        $presence = $this->hotPresence(['guest_token' => 'tok-parity']);
        $thread = app(SupportConversationManager::class)->openForGuest($presence->guest_token);
        $thread2 = app(SupportConversationManager::class)->openForGuest('tok-parity-2');

        // Прямое управление часами: created_at не mass-assignable, а интервал
        // «строго позже» требует различимых секунд.
        Carbon::setTestNow(Carbon::parse('2026-10-13 10:00', 'UTC'));
        try {
            ChatMessage::create([
                'support_conversation_id' => $thread->id,
                'role' => 'curator',
                'text' => 'Проактив с конверсией',
                'is_read' => true,
                'is_proactive' => true,
            ]);
            ChatMessage::create([
                'support_conversation_id' => $thread2->id,
                'role' => 'curator',
                'text' => 'Проактив без ответа',
                'is_read' => true,
                'is_proactive' => true,
            ]);

            Carbon::setTestNow(Carbon::parse('2026-10-13 11:00', 'UTC'));
            ChatMessage::create([
                'support_conversation_id' => $thread->id,
                'role' => 'user',
                'text' => 'Ответил',
            ]);

            $report = (new SupportParityReportBuilder)->build(30);

            $this->assertSame(2, $report['kpis']['proactive_first_writes']);
            $this->assertSame(1, $report['kpis']['proactive_converted_threads']);
            $this->assertSame(0.5, $report['kpis']['proactive_conversion_rate']);
            $this->assertArrayHasKey('support_proactive_invites', $report['rollback']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
