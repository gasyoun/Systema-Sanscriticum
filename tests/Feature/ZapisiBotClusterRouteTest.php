<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ZapisiBot\ZapisiBotDashboard;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Регресс: у ZapisiBotDashboard был $slug = '/', из-за чего Filament вычислял
 * имя роута как 'filament.admin.zapisi-bot.pages..' (пустой сегмент), которого
 * нет в таблице роутов. При открытии кластера «Записи (бот)» под-навигация
 * дергала route() на это имя → RouteNotFoundException (500 на весь раздел).
 * Реальный slug даёт консистентное имя, совпадающее с зарегистрированным роутом.
 */
class ZapisiBotClusterRouteTest extends TestCase
{
    public function test_dashboard_route_name_is_valid_and_registered(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $name = ZapisiBotDashboard::getRouteName();

        // Никаких пустых сегментов ('..') в имени роута.
        $this->assertStringNotContainsString('..', $name);
        $this->assertSame('filament.admin.zapisi-bot.pages.dashboard', $name);

        // Имя реально зарегистрировано — route()/getUrl() не бросит исключение.
        $this->assertTrue(Route::has($name), "Роут {$name} должен быть зарегистрирован");
    }
}
