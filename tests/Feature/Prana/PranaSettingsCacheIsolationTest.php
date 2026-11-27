<?php

declare(strict_types=1);

namespace Tests\Feature\Prana;

use App\Models\MarketingSetting;
use App\Services\Prana\PranaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * YMC: PranaSettings хранит процессный статик со строкой MarketingSetting.
 * RefreshDatabase откатывает строку между тестами, но статик в том же процессе
 * PHPUnit живёт дальше — следующий тест наследует чужую награду (50 вместо 10).
 * Два метода в одном классе — воспроизведение и гейт против повторения.
 */
class PranaSettingsCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_01_previous_test_populates_the_static_cache(): void
    {
        MarketingSetting::create(['prana_reward_payment_success' => 50]);

        $this->assertSame(50, PranaSettings::reward('payment_success'));
    }

    public function test_02_next_test_sees_clean_settings(): void
    {
        // Строка откачена RefreshDatabase — конфиг должен стать единственным источником.
        $this->assertNull(MarketingSetting::query()->first());
        config(['prana.rewards.payment_success' => 10]);

        $this->assertSame(10, PranaSettings::reward('payment_success'));
    }
}
