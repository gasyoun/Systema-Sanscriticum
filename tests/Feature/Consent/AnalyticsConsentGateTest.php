<?php

declare(strict_types=1);

namespace Tests\Feature\Consent;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 152-ФЗ: счётчики (Яндекс Метрика, VK/Top.Mail.Ru, BotFaqtor) грузятся
 * только после «Принять аналитику» — через window.ssConsent.onAnalytics
 * (partials/analytics-gate). Страж по исходникам шаблонов: новый счётчик,
 * вставленный мимо шлюза, или <noscript>-пиксель (без JS согласие получить
 * нельзя) роняет тест.
 */
class AnalyticsConsentGateTest extends TestCase
{
    /** @return array<string, string> путь => содержимое шаблонов, где грузятся счётчики */
    private function counterViews(): array
    {
        $out = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $src = $file->getContents();
            if (preg_match('~mc\.yandex\.ru/metrika/tag\.js|top-fwz1\.mail\.ru/js/code\.js|cdn\.botfaqtor\.ru~', $src)) {
                $out[$file->getRelativePathname()] = $src;
            }
        }

        return $out;
    }

    public function test_counter_views_exist(): void
    {
        $this->assertNotEmpty($this->counterViews());
    }

    public function test_every_counter_loads_only_through_the_consent_gate(): void
    {
        foreach ($this->counterViews() as $path => $src) {
            $this->assertStringContainsString("@include('partials.analytics-gate')", $src, "{$path}: нет подключения шлюза");

            foreach (['mc.yandex.ru/metrika/tag.js', 'top-fwz1.mail.ru/js/code.js', 'cdn.botfaqtor.ru/one.js'] as $loader) {
                $at = strpos($src, $loader);
                if ($at === false) {
                    continue;
                }
                $gate = strrpos(substr($src, 0, $at), 'ssConsent.onAnalytics(function () {');
                $this->assertNotFalse($gate, "{$path}: {$loader} грузится вне ssConsent.onAnalytics");
            }
        }
    }

    public function test_no_noscript_tracking_pixels_anywhere(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $src = $file->getContents();
            $this->assertDoesNotMatchRegularExpression(
                '~<noscript>[^<]*<div><img src="https://(mc\.yandex\.ru/watch|top-fwz1\.mail\.ru/counter)~',
                $src,
                $file->getRelativePathname().': <noscript>-пиксель грузится без согласия',
            );
        }
    }

    public function test_shop_metrika_renders_gated_with_field_masking_gate(): void
    {
        config()->set('analytics.metrika.enabled', true);
        config()->set('analytics.metrika.shop_counter_id', 106964341);

        $html = (string) view('partials.shop-metrika');

        $this->assertStringContainsString("var KEY = 'cookie_consent_v2'", $html);
        $this->assertStringContainsString('ym-disable-keys', $html);
        $this->assertLessThan(
            strpos($html, 'mc.yandex.ru/metrika/tag.js'),
            strpos($html, 'ssConsent.onAnalytics(function () {'),
        );
        // Хелпер целей остаётся вне шлюза и безопасен без ym.
        $this->assertStringContainsString("typeof ym === 'undefined'", $html);
    }

    public function test_banner_offers_reject_and_accept_and_footer_can_reopen_it(): void
    {
        $banner = (string) view('partials.cookie-consent');
        $this->assertStringContainsString('data-cookie-reject', $banner);
        $this->assertStringContainsString('data-cookie-accept', $banner);
        $this->assertStringNotContainsString('Продолжая им пользоваться', $banner);

        $footer = (string) view('partials.footer-docs');
        $this->assertStringContainsString('ssConsent.reopen()', $footer);
    }
}
