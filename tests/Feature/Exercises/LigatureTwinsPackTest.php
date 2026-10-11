<?php

declare(strict_types=1);

namespace Tests\Feature\Exercises;

use App\Filament\Pages\GamesFunnel;
use App\Models\GameEvent;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H6393 (lila v2) — режим «Близнецы» в public/lila/ligatures:
 * переиспользование match/engine.js без изменений, гарантированная
 * совместная выкладка близнецов (без perRound-сэмплирования), проводка
 * gate/telemetry/locale, пары из топ-200 DCS, и по-лигатурная
 * статистика на Filament-странице воронки.
 */
class LigatureTwinsPackTest extends TestCase
{
    use RefreshDatabase;

    private function packPath(string $file): string
    {
        return public_path('lila/ligatures/twins/'.$file);
    }

    /** @test */
    public function the_twins_pack_exists_and_reuses_the_match_engine_as_is(): void
    {
        $this->assertFileExists($this->packPath('index.html'));
        $this->assertFileExists($this->packPath('data.js'));

        $html = file_get_contents($this->packPath('index.html'));
        $this->assertFileExists(public_path('lila/match/engine.js'));

        // Движок — тот же файл, без копий и без новых движков на странице.
        $this->assertStringContainsString('../../match/engine.js', $html);
        $this->assertStringContainsString('../../match/engine.css', $html);
        $this->assertStringNotContainsString('function mount(', $html, 'the pack must not inline a new engine');
    }

    /** @test */
    public function twins_pack_carries_gate_telemetry_and_locale_wiring(): void
    {
        $html = file_get_contents($this->packPath('index.html'));

        $this->assertStringContainsString('/lila/gate.js', $html);
        $this->assertStringContainsString(
            'data-drill="ligatures" data-band="twins"',
            $html,
            'telemetry must identify the twins band of the ligature drill'
        );
        $this->assertStringContainsString('/lila/locale.js', $html);
    }

    /** @test */
    public function both_twins_are_always_in_play_no_per_round_sampling(): void
    {
        $html = file_get_contents($this->packPath('index.html'));

        // perRound сэмплировал бы ПАРЫ независимо и мог выкинуть одного из
        // близнецов — режим различения обязан держать оба на доске.
        $this->assertStringNotContainsString('perRound:', $html, 'perRound samples pairs independently and could drop a twin from the board');
        $this->assertStringContainsString('TWINS_PAIRS.slice(0, TWINS_BOARD_GROUPS', $html);
        $this->assertStringContainsString('pairs.push({ left: { text: g.a.devanagari }', $html);
        $this->assertStringContainsString('pairs.push({ left: { text: g.b.devanagari }', $html);
    }

    /** @test */
    public function twins_data_pairs_are_distinct_and_come_from_the_dcs_top200(): void
    {
        $js = file_get_contents($this->packPath('data.js'));
        $bands = file_get_contents(public_path('lila/ligatures/data.js'));

        $this->assertStringContainsString('TWINS_PAIRS', $js);
        $this->assertStringContainsString('TWINS_BOARD_GROUPS', $js);

        preg_match_all(
            '/a:\s*\{[^}]*devanagari:\s*"([^"]+)"[^}]*iast:\s*"([^"]+)"[^}]*\},\s*'.
            'b:\s*\{[^}]*devanagari:\s*"([^"]+)"[^}]*iast:\s*"([^"]+)"[^}]*\}/u',
            $js,
            $m,
            PREG_SET_ORDER
        );
        $this->assertGreaterThanOrEqual(20, count($m), 'the interim deterministic rule should yield a substantive pair set');

        foreach ($m as $p) {
            [, $aDeva, $aIast, $bDeva, $bIast] = $p;
            $this->assertNotSame($aIast, $bIast, 'twins must differ');
            $this->assertNotSame($aDeva, $bDeva, 'twins must differ in Devanagari');
            // Оба члена пары — из зафиксированного топ-200 DCS.
            $this->assertStringContainsString('devanagari: "'.$aDeva.'"', $bands);
            $this->assertStringContainsString('devanagari: "'.$bDeva.'"', $bands);
        }
    }

    /** @test */
    public function ligatures_catalogue_links_the_twins_mode(): void
    {
        $html = file_get_contents(public_path('lila/ligatures/index.html'));
        $this->assertStringContainsString('href="twins/"', $html);
        $this->assertStringContainsString('Близнецы', $html);
    }

    /** @test */
    public function funnel_page_shows_per_ligature_difficulty_rows_for_the_twins_band(): void
    {
        GameEvent::create([
            'anon_id' => 'twins1',
            'drill' => 'ligatures',
            'band' => 'twins',
            'event' => GameEvent::ITEM_RESULT,
            'authenticated' => false,
            'payload' => ['hints' => 0, 'items' => [
                ['l' => 'ष्ट', 'r' => 'ṣṭh', 'ms' => 5200, 'wrong' => 2],
                ['l' => 'ष्ठ', 'r' => 'ṣṭh', 'ms' => 3100, 'wrong' => 0],
            ]],
            'created_at' => now()->subMinutes(5),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));

        Livewire::test(GamesFunnel::class)
            ->assertOk()
            ->assertSee('Лигатуры: трудные чтения')
            ->assertSee('twins')
            ->assertSee('ṣṭh');
    }
}
