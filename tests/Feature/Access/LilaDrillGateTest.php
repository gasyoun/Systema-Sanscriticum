<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\GameEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * H4396 — серверная половина ворот бесплатных тренажёров /lila.
 *
 * Дыра: бюджет «5 бесплатных раундов на семейство» жил только в localStorage
 * (gate.js) — очистка сбрасывала счёт. Теперь счёт живёт в game_events
 * (event=round), а ключ — производный от web-СЕССИИ: очистка localStorage
 * бюджет не сбрасывает. Ноль изъятия бесплатных возможностей: бюджет прежний
 * (5 на семейство), залогиненные не запираются, сбой сервера = прежнее
 * локальное поведение (контракт голого статического хоста).
 */
class LilaDrillGateTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET = '/api/games/budget';

    private const ROUND = '/api/games/round';

    /**
     * Тестовый клиент Laravel не переживает cookie между запросами сам —
     * сессию протаскиваем руками: первый ответ ставит cookie сессии,
     * дальше каждый запрос несёт её же (один и тот же «браузер»).
     *
     * @param  array<string, string>  $cookies  накопитель cookie браузера
     */
    private function carrySession(TestResponse $response, array &$cookies): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
    }

    /** @return array{0: TestResponse, 1: array<string, string>} */
    private function hit(string $verb, string $uri, array $data = [], array $cookies = []): array
    {
        // withUnencryptedCookies СЛИВАЕТ (merge), поэтому перед каждым запросом
        // сбрасываем — иначе сессия прошлого «браузера» утекала бы в «новый».
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        // Cookie сессии зашифрованы — сырые значения отправляем как есть
        // (withUnencryptedCookies), иначе двойное шифрование ломает сессию.
        $request = $this->withUnencryptedCookies($cookies)
            ->{strtolower($verb) === 'get' ? 'get' : 'post'}($uri, $data);

        $this->carrySession($request, $cookies);

        return [$request, $cookies];
    }

    /** @test */
    public function fresh_anonymous_session_starts_with_zero_used(): void
    {
        [$response] = $this->hit('GET', self::BUDGET.'?family=sort');

        $response->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('used', 0)
            ->assertJsonPath('free', 5)
            ->assertJsonPath('family', 'sort');

        $anon = $response->json('anon_id');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $anon);
    }

    /** @test */
    public function five_rounds_open_the_wall_and_the_sixth_reports_gated(): void
    {
        [, $cookies] = $this->hit('GET', self::BUDGET.'?family=sort');

        for ($i = 0; $i < 5; $i++) {
            [$response, $cookies] = $this->hit('POST', self::ROUND, ['family' => 'sort'], $cookies);
        }

        $response->assertOk()
            ->assertJsonPath('used', 5)
            ->assertJsonPath('gated', true);

        $this->assertDatabaseCount('game_events', 5);
        $this->assertSame(['round'], GameEvent::query()->distinct()->pluck('event')->all());
    }

    /** @test */
    public function clearing_local_storage_does_not_reset_the_session_bound_budget(): void
    {
        // Корень дыры H4396: раньше budget жил в localStorage. Теперь ключ —
        // сессия: «браузер» (те же cookie) при чистом localStorage получает
        // тот же счёт.
        [, $cookies] = $this->hit('GET', self::BUDGET.'?family=match');
        [$first, $cookies] = $this->hit('GET', self::BUDGET.'?family=match', [], $cookies);
        $firstAnon = $first->json('anon_id');

        for ($i = 0; $i < 3; $i++) {
            [, $cookies] = $this->hit('POST', self::ROUND, ['family' => 'match'], $cookies);
        }

        // Тот же «браузер», localStorage «очищен» (клиент ничего не шлёт).
        [$response] = $this->hit('GET', self::BUDGET.'?family=match', [], $cookies);

        $response->assertOk()->assertJsonPath('used', 3);
        $this->assertSame($firstAnon, $response->json('anon_id'));
    }

    /** @test */
    public function budgets_are_per_family_and_per_session(): void
    {
        [, $cookiesA] = $this->hit('GET', self::BUDGET.'?family=sort');
        for ($i = 0; $i < 5; $i++) {
            [, $cookiesA] = $this->hit('POST', self::ROUND, ['family' => 'sort'], $cookiesA);
        }
        [$walled] = $this->hit('GET', self::BUDGET.'?family=sort', [], $cookiesA);
        $walled->assertJsonPath('used', 5);

        // Другая сессия (другой «браузер») — свой бюджет.
        [$fresh] = $this->hit('GET', self::BUDGET.'?family=sort');
        $fresh->assertJsonPath('used', 0);

        // Другое семейство в той же сессии — свой бюджет (H1678 per-family).
        [$otherFamily] = $this->hit('GET', self::BUDGET.'?family=cloze', [], $cookiesA);
        $otherFamily->assertJsonPath('used', 0);
    }

    /** @test */
    public function logged_in_students_are_never_gated_and_post_no_rounds(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::BUDGET.'?family=sort')
            ->assertOk()
            ->assertJsonPath('authenticated', true);

        $this->actingAs($user)->postJson(self::ROUND, ['family' => 'sort'])
            ->assertOk()
            ->assertJsonPath('authenticated', true);

        $this->assertDatabaseCount('game_events', 0);
    }

    /** @test */
    public function unknown_or_dirty_family_is_rejected(): void
    {
        $this->get(self::BUDGET.'?family=')->assertStatus(422);
        $this->post(self::ROUND, ['family' => str_repeat('x', 60)])->assertStatus(422);

        [$response] = $this->hit('POST', self::ROUND, ['family' => 'sort<x>']);
        $response->assertOk()->assertJsonPath('family', 'sortx');
    }

    /** @test */
    public function telemetry_complete_events_do_not_count_toward_the_round_budget(): void
    {
        // Перезагрузки страницы шлют воронковый complete (по одному на загрузку,
        // H1360) — бюджет по ним НЕ растёт, иначе перезагрузки сжигали бы раунды.
        $this->post('/api/games/event', [
            'anon_id' => 'whatever', 'drill' => 'sort', 'event' => 'complete',
        ]);

        [$response] = $this->hit('GET', self::BUDGET.'?family=sort');
        $response->assertJsonPath('used', 0);
    }

    /** @test */
    public function gate_js_wires_the_server_budget_with_local_fallback(): void
    {
        $js = (string) file_get_contents(public_path('lila/gate.js'));

        $this->assertStringContainsString('/api/games/budget', $js);
        $this->assertStringContainsString('/api/games/round', $js);
        // Голый статический хост: сбой сервера -> прежнее локальное поведение.
        $this->assertStringContainsString('catch', $js);
        $this->assertStringContainsString('sgx_plays_v2', $js);
    }
}
