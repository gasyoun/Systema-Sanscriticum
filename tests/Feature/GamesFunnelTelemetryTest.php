<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\GamesFunnel;
use App\Models\GameEvent;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H1360 — анонимная воронка бесплатных тренажёров /exercises:
 * приёмник /api/games/event, агрегат games:funnel, Filament-страница,
 * и приватный контракт (никаких IP/UA/PII).
 */
class GamesFunnelTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/games/event';

    /** @test */
    public function unknown_event_name_is_rejected_422(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'abc123', 'drill' => 'ligatures', 'event' => 'hack',
        ])->assertStatus(422);

        $this->assertDatabaseCount('game_events', 0);
    }

    /** @test */
    public function valid_post_writes_exactly_one_row(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0001', 'drill' => 'ligatures', 'band' => 'top-50', 'event' => 'start',
        ])->assertNoContent();

        $this->assertDatabaseCount('game_events', 1);
        $row = GameEvent::first();
        $this->assertSame('anon0001', $row->anon_id);
        $this->assertSame('ligatures', $row->drill);
        $this->assertSame('top-50', $row->band);
        $this->assertSame('start', $row->event);
        $this->assertFalse($row->authenticated);
    }

    /** @test */
    public function the_table_stores_no_ip_or_user_agent_by_design(): void
    {
        // Приватный контракт H1360: в game_events физически нет колонок для PII.
        $this->assertFalse(Schema::hasColumn('game_events', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('game_events', 'ip'));
        $this->assertFalse(Schema::hasColumn('game_events', 'user_agent'));
        $this->assertFalse(Schema::hasColumn('game_events', 'user_id'));
    }

    /** @test */
    public function anon_id_is_stripped_to_alphanumerics(): void
    {
        // Даже если клиент пришлёт мусор/PII-подобное — очищается до [A-Za-z0-9].
        $this->postJson(self::URL, [
            'anon_id' => 'a@b.c/d e', 'drill' => 'roots', 'event' => 'start',
        ])->assertNoContent();

        $this->assertSame('abcde', GameEvent::first()->anon_id);
    }

    /** @test */
    public function authenticated_hit_is_flagged_server_side(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(self::URL, [
            'anon_id' => 'anon0002', 'drill' => 'cloze', 'band' => 'verb-fill', 'event' => 'complete',
        ])->assertNoContent();

        $this->assertTrue(GameEvent::first()->authenticated);
    }

    /** @test */
    public function throttling_engages_after_the_per_minute_cap(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson(self::URL, ['drill' => 'sort', 'event' => 'start'])->assertNoContent();
        }

        $this->postJson(self::URL, ['drill' => 'sort', 'event' => 'start'])->assertStatus(429);
    }

    /** @test */
    public function funnel_aggregation_matches_a_seeded_fixture(): void
    {
        // Показы 3, решения 2, стена 1, CTA 1 для ligatures/top-50.
        $this->seedEvents('ligatures', 'top-50', GameEvent::START, 3);
        $this->seedEvents('ligatures', 'top-50', GameEvent::COMPLETE, 2);
        $this->seedEvents('ligatures', 'top-50', GameEvent::GATE_SHOWN, 1);
        $this->seedEvents('ligatures', 'top-50', GameEvent::GATE_CTA_CLICK, 1);
        // Другой уровень — отдельная строка.
        $this->seedEvents('roots', 'top-25', GameEvent::START, 5);
        // Вне окна — не должно попасть.
        GameEvent::create([
            'anon_id' => 'old', 'drill' => 'ligatures', 'band' => 'top-50',
            'event' => GameEvent::START, 'authenticated' => false,
            'created_at' => now()->subDays(30),
        ]);

        $funnel = collect(GameEvent::funnel(now()->subDays(14)))->keyBy(
            fn ($r) => $r['drill'].'/'.$r['band']
        );

        $lig = $funnel['ligatures/top-50'];
        $this->assertSame(3, $lig['plays']);
        $this->assertSame(2, $lig['completes']);
        $this->assertSame(1, $lig['walls']);
        $this->assertSame(1, $lig['cta']);

        $this->assertSame(5, $funnel['roots/top-25']['plays']);
        $this->assertSame(0, $funnel['roots/top-25']['completes']);
    }

    /** @test */
    public function games_funnel_command_runs_and_reports_missing_surfaces(): void
    {
        $this->seedEvents('ligatures', 'top-50', GameEvent::START, 2);

        $this->artisan('games:funnel --days=14')
            ->assertExitCode(0)
            ->expectsOutputToContain('ligatures')
            // Семейства без данных перечисляются честно, не молча нулём.
            ->expectsOutputToContain('cloze');
    }

    /** @test */
    public function funnel_page_is_visible_to_manager_and_admin_but_not_teacher(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::MANAGER]));
        $this->assertTrue(GamesFunnel::canAccess());

        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->assertTrue(GamesFunnel::canAccess());

        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(GamesFunnel::canAccess());
    }

    /** @test */
    public function funnel_page_renders_for_admin(): void
    {
        $this->seedEvents('ligatures', 'top-50', GameEvent::START, 4);
        $this->seedEvents('ligatures', 'top-50', GameEvent::COMPLETE, 1);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(GamesFunnel::class)
            ->assertOk()
            ->assertSee('ligatures');
    }

    /** @test */
    public function gate_js_gating_state_is_untouched_by_telemetry(): void
    {
        // Контракт H1360: gate.js не трогаем, а telemetry.js — пассивный наблюдатель.
        $base = base_path('public/exercises');
        $gate = file_get_contents($base.'/gate.js');
        $telemetry = file_get_contents($base.'/telemetry.js');

        // gate.js сохраняет свой ключ и поведение и НЕ вызывает телеметрию.
        $this->assertStringContainsString('sgx_played_v1', $gate);
        $this->assertStringContainsString('showWall', $gate);
        $this->assertStringNotContainsString('telemetry', $gate);
        $this->assertStringNotContainsString('/api/games/event', $gate);

        // telemetry.js использует ДРУГОЙ ключ и не читает/не пишет gate-состояние.
        $this->assertStringContainsString('sgx_anon_v1', $telemetry);
        $this->assertStringNotContainsString('sgx_played_v1', $telemetry);
    }

    private function seedEvents(string $drill, ?string $band, string $event, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            GameEvent::create([
                'anon_id' => "seed{$i}",
                'drill' => $drill,
                'band' => $band,
                'event' => $event,
                'authenticated' => false,
                'created_at' => now()->subHours(1),
            ]);
        }
    }
}
