<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\TelegramSupportAnalytics;
use App\Models\SupportDailyRollup;
use App\Models\SupportTopicAssignment;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3529: секция «Coverage классификации» на telegram-support-analytics —
 * дневной coverage% по каналам + uncategorized rate. Числа обязаны сходиться
 * с прямым SQL по support_topic_assignments за ту же дату.
 */
class SupportCoveragePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_panel_renders_daily_per_channel_numbers(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $admin = User::factory()->create(['role' => Roles::ADMIN]);
        $studentA = User::factory()->create();
        $studentB = User::factory()->create();

        // web: 2 разговора — 1 категоризован, 1 uncategorized → coverage 50%.
        // Уникальный ключ (web_user_id, channel, date) — по пользователю на строку.
        foreach ([$studentA->id => true, $studentB->id => false] as $webUserId => $categorized) {
            $rollup = SupportDailyRollup::create([
                'channel' => SupportDailyRollup::CHANNEL_WEB,
                'web_user_id' => $webUserId,
                'conversation_date' => '2026-08-25 00:00:00',
                'incoming_count' => 1,
            ]);

            SupportTopicAssignment::create([
                'support_daily_rollup_id' => $rollup->id,
                'category' => $categorized ? 'payment_billing' : 'uncategorized',
                'source' => 'keyword',
                'confidence' => $categorized ? 1 : 0,
            ]);
        }

        // vk: 1 разговор без назначений вообще → uncategorized, coverage 0%.
        SupportDailyRollup::create([
            'channel' => SupportDailyRollup::CHANNEL_VK,
            'web_user_id' => $admin->id,
            'conversation_date' => '2026-08-25 00:00:00',
            'incoming_count' => 1,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(TelegramSupportAnalytics::class);
        $component->set('selectedDate', '2026-08-25');

        // Числа сверяются напрямую с вычисляемым свойством страницы (Livewire
        // computed properties не попадают в data массив вью, поэтому через
        // instance, а не assertViewHas).
        $coverage = $component->instance()->coverage;
        $rows = collect($coverage['rows'])->keyBy('channel');

        $this->assertSame(3, $coverage['total']);
        $this->assertSame(50, $rows['web']['coverage'], 'web: 1 of 2 categorized');
        $this->assertSame(0, $rows['vk']['coverage'], 'vk: assignment-less conversation is uncategorized');
        $this->assertSame(1, $coverage['categorized']);
        $this->assertSame(33, $coverage['coverage'], 'overall: 1 of 3');

        // Секция реально рисуется на странице.
        $component->assertSee('Coverage классификации')
            ->assertSeeHtml('data-testid="support-coverage-panel"');
    }

    public function test_harness_report_url_is_null_until_package_freezes_reports(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $page = new TelegramSupportAnalytics;

        $url = $page->harnessReportUrl;

        if (glob(base_path('tools/message-intent-classifier/reports/*.md')) !== false
            && (glob(base_path('tools/message-intent-classifier/reports/*.md')) ?: []) !== []) {
            // Отчёт уже заморожен волной 1 шага 4 — ссылка обязана быть и вести на pin.
            $this->assertNotNull($url);
            $this->assertStringStartsWith('https://github.com/gasyoun/message-intent-classifier/blob/', (string) $url);

            return;
        }

        // Пакет ещё не заморозил reports/*.md — ссылки нет (секция не рисует).
        $this->assertNull($url);
    }
}
