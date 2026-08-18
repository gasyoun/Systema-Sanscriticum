<?php

declare(strict_types=1);

namespace Tests\Browser;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksVisualDcsEvidence;
use Tests\DuskTestCase;

/**
 * H2869 — Wave L шаг 8, ветка prefers-reduced-motion.
 *
 * Отдельный класс, потому что предпочтение анимаций задаётся аргументом
 * запуска Chrome (--force-prefers-reduced-motion), а driver() у Dusk — на
 * класс. Проверяем ровно то, что требует контактный лист: при включённом
 * reduced-motion контент не прячется — страницы поверхностей показывают те же
 * якорные тексты, без горизонтального скролла.
 */
class VisualDcsReducedMotionTest extends DuskTestCase
{
    use ChecksVisualDcsEvidence;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Browser::$storeScreenshotsAt = base_path(self::$targetDir);
        $this->requireFlagsOn();
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments([
            '--window-size=1440,900',
            '--force-prefers-reduced-motion',
            '--disable-search-engine-choice-screen',
            '--disable-gpu',
            '--headless=new',
        ]);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options)
        );
    }

    public function test_reduced_motion_hides_nothing(): void
    {
        $this->importFixture('complete');
        $user = $this->paidUser();
        $linked = $this->passageId('hitopade-a:0');

        $this->browse(function (Browser $browser) use ($user, $linked) {
            $reduced = (bool) $browser->visit('/visualdcs/verb/preview')
                ->script("return window.matchMedia('(prefers-reduced-motion: reduce)').matches;")[0];
            $this->assertTrue($reduced, 'Chrome не применил --force-prefers-reduced-motion — проверка не о том.');

            $browser->loginAs($user)
                ->visit('/dvaram/visualdcs')
                ->assertSee('Глагол')
                ->assertSee('Пассаж')
                ->screenshot('reduced-motion-hub-1440');
            $this->assertNoHorizontalOverflow($browser, 'хаб @ reduced-motion');

            $browser->visit('/dvaram/visualdcs/passage/'.rawurlencode($linked))
                ->assertSee('Связанные формы')
                ->assertSee('Hitopadeśa')
                ->screenshot('reduced-motion-passage-show-1440');
            $this->assertNoHorizontalOverflow($browser, 'пассаж @ reduced-motion');
        });
    }
}
