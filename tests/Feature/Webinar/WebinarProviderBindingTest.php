<?php

declare(strict_types=1);

namespace Tests\Feature\Webinar;

use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\ZoomService;
use Tests\TestCase;

/**
 * Активный провайдер вебинара — Zoom (GC-B3, H601). Смена провайдера — одна
 * строка в AppServiceProvider, вызывающий код (ZoomWebhookController и т.д.)
 * не меняется.
 */
class WebinarProviderBindingTest extends TestCase
{
    /** @test */
    public function resolves_to_zoom_service(): void
    {
        $this->assertInstanceOf(ZoomService::class, app(WebinarProvider::class));
    }
}
