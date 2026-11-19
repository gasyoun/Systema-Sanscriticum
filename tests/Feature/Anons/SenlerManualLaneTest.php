<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\PublicationManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * H5935: Senler — задокументированный manual lane, не адаптер. Реестр
 * отказывает fail-closed, но с указателем на процедуру; имя платформы
 * остаётся валидным на уровне схемы; документация lane не может молча
 * исчезнуть (doc-presence pin = stop condition хендоффа).
 */
class SenlerManualLaneTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function registry_refuses_senler_with_manual_lane_pointer(): void
    {
        $registry = new AdapterRegistry;

        try {
            $registry->for('senler');
            $this->fail('senler must stay fail-closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('manual lane', $e->getMessage());
            $this->assertStringContainsString('ANONS_PUBLISHING_V2.md', $e->getMessage());
        }
    }

    /** @test */
    public function senler_stays_a_valid_manifest_platform(): void
    {
        $this->assertContains('senler', PublicationManifest::PLATFORMS);

        $errors = PublicationManifest::fromArray([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'sep-start',
            'slot' => '2026-10-04T08:00',
            'frames' => [[
                'asset' => '/tmp/unused-asset.jpg',
                'caption' => 'Набор осенней группы с нуля.',
                'alt_text' => 'Анонс осенней группы',
                'cta_text' => 'страница записи',
                'cta_url' => 'https://samskrte.ru/ga/m26-vk-s',
            ]],
            'destinations' => [
                ['platform' => 'senler', 'account' => 'samskrte_community'],
            ],
        ])->validate();

        $this->assertNotContains(
            'destinations[0].platform must be one of: '.implode(', ', PublicationManifest::PLATFORMS).'.',
            $errors,
            'senler must validate at schema level — the refusal belongs to the registry.'
        );
    }

    /** @test */
    public function manual_lane_doc_carries_checklist_smoke_and_journal(): void
    {
        $doc = (string) file_get_contents(realpath(__DIR__.'/../../../docs/ANONS_PUBLISHING_V2.md'));

        $this->assertStringContainsString('Senler manual lane (H5935)', $doc);
        $this->assertStringContainsString('https://samskrte.ru/ga/m26-vk-s', $doc);
        $this->assertStringContainsString('curl -sI https://samskrte.ru/ga/m26-vk-s', $doc);
        $this->assertStringContainsString('no PII', $doc);
        $this->assertStringContainsString('VK_PUBLISH_AUTHORIZED', $doc);
    }
}
