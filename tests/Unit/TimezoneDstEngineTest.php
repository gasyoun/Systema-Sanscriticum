<?php

namespace Tests\Unit;

use App\Models\Schedule;
use App\Models\User;
use App\Services\DstShiftAdvisor;
use App\Support\Timezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H4434 — DST-движок на границах (MG 09-09-2026): Мадрид (EU-правила),
 * Лос-Анджелес (US-правила), Дели (без DST вообще), плюс dual-display
 * и эффективная зона с оверрайдом временного пребывания.
 */
class TimezoneDstEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (Carbon::hasTestNow()) {
            Carbon::setTestNow(); // сброс test-now, если ставился
        }
        parent::tearDown();
    }

    // --- Timezone::isValid / render ---

    public function testIsValidAcceptsIanaAndRejectsGarbage(): void
    {
        $this->assertTrue(Timezone::isValid('Europe/Madrid'));
        $this->assertTrue(Timezone::isValid('America/Los_Angeles'));
        $this->assertFalse(Timezone::isValid('Not/AZone'));
        $this->assertFalse(Timezone::isValid(''));
        $this->assertFalse(Timezone::isValid(null));
    }

    public function testRenderMskUserSeesBareTime(): void
    {
        $at = Carbon::parse('2026-03-14 11:00', 'Europe/Moscow');

        $this->assertSame('11:00', Timezone::render($at, null));
        $this->assertSame('11:00', Timezone::render($at, 'Europe/Moscow'));
    }

    public function testRenderNonMskUserGetsDualDisplay(): void
    {
        // Мадрид: зимой UTC+1 → 11:00 МСК = 09:00 Мадрид.
        $at = Carbon::parse('2026-02-14 11:00', 'Europe/Moscow');

        $this->assertSame('11:00 МСК · 09:00 ваше', Timezone::render($at, 'Europe/Madrid'));
    }

    public function testRenderSkipsDualWhenClocksCoincide(): void
    {
        // Зона с тем же оффсетом, что МСК (UTC+3): dual не появляется — время совпадает.
        $at = Carbon::parse('2026-02-14 11:00', 'Europe/Moscow');

        $this->assertSame('11:00', Timezone::render($at, 'Africa/Nairobi')); // UTC+3 круглый год
    }

    // --- User::effectiveTimezone / isNonMskTimezone ---

    public function testEffectiveTimezonePrefersActiveOverride(): void
    {
        $user = new User([
            'timezone' => 'Europe/Madrid',
            'tz_override' => 'Asia/Kolkata',
            'tz_source' => 'manual',
        ]);
        $user->tz_override_until = Carbon::today()->addMonth();

        $this->assertSame('Asia/Kolkata', $user->effectiveTimezone());
        $this->assertTrue($user->isNonMskTimezone());
    }

    public function testEffectiveTimezoneFallsBackAfterOverrideExpiry(): void
    {
        $user = new User([
            'timezone' => 'Europe/Madrid',
            'tz_override' => 'Asia/Kolkata',
            'tz_source' => 'manual',
        ]);
        // Юзер уехал до 20-го; 25-го оверрайд уже протух (ленивый возврат, без крона).
        $user->tz_override_until = Carbon::today()->subWeek();

        $this->assertSame('Europe/Madrid', $user->effectiveTimezone());
    }

    public function testMskUserIsNotNonMsk(): void
    {
        $user = new User(['timezone' => 'Europe/Moscow', 'tz_source' => 'device']);

        $this->assertFalse($user->isNonMskTimezone());
    }

    // --- DstShiftAdvisor: границы зон ---

    public function testMadridTransitionFoundInMarch(): void
    {
        // EU: последнее воскресенье марта (29-03-2026) — переход +1ч в 02:00 локальных.
        $from = Carbon::parse('2026-03-10 12:00', 'UTC');
        $tr = DstShiftAdvisor::nextTransition('Europe/Madrid', $from);

        $this->assertNotNull($tr);
        $this->assertSame('2026-03-29', $tr['date']->toDateString());
        $this->assertSame(3600, $tr['afterOffset'] - $tr['beforeOffset']);
    }

    public function testLosAngelesTransitionFoundInMarch(): void
    {
        // US: второе воскресенье марта (08-03-2026) — переход в 02:00 локальных.
        $from = Carbon::parse('2026-03-01 12:00', 'UTC');
        $tr = DstShiftAdvisor::nextTransition('America/Los_Angeles', $from);

        $this->assertNotNull($tr);
        $this->assertSame('2026-03-08', $tr['date']->toDateString());
    }

    public function testDelhiHasNoTransitions(): void
    {
        // Индия DST не знает — алертов нет никогда (MG-кейс «временно в Индии»).
        $from = Carbon::parse('2026-03-01 12:00', 'UTC');

        $this->assertNull(DstShiftAdvisor::nextTransition('Asia/Kolkata', $from));
    }

    public function testMoscowHasNoTransitions(): void
    {
        // РФ переводы часов отменили навсегда — МСК-юзеров не тревожим.
        $from = Carbon::parse('2026-03-01 12:00', 'UTC');

        $this->assertNull(DstShiftAdvisor::nextTransition('Europe/Moscow', $from));
    }

    public function testLocalShiftDetectsWallClockChange(): void
    {
        $user = new User(['timezone' => 'Europe/Madrid', 'tz_source' => 'manual']);

        // Занятие 2026-04-04 11:00 МСК = 10:00 Мадрид (после перехода, UTC+2).
        // До перехода было бы 09:00 (UTC+1) — сдвиг есть.
        $session = new Schedule(['start' => Carbon::parse('2026-04-04 11:00', 'Europe/Moscow')]);

        $shift = DstShiftAdvisor::localShiftFor($user, $session);

        $this->assertNotNull($shift);
        $this->assertSame('10:00', $shift['before']);
        $this->assertSame('09:00', $shift['after']);
    }

    public function testLocalShiftNullForDelhiUserWithNoDst(): void
    {
        $user = new User(['timezone' => 'Asia/Kolkata', 'tz_source' => 'manual']);
        $session = new Schedule(['start' => Carbon::parse('2026-04-04 11:00', 'Europe/Moscow')]);

        $this->assertNull(DstShiftAdvisor::localShiftFor($user, $session));
    }

    public function testLocalShiftNullForMskUser(): void
    {
        $user = new User(); // без зоны = МСК-дефолт
        $session = new Schedule(['start' => Carbon::parse('2026-04-04 11:00', 'Europe/Moscow')]);

        $this->assertNull(DstShiftAdvisor::localShiftFor($user, $session));
    }
}