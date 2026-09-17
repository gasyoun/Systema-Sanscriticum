<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsLinkClick;
use App\Models\AnonsMetric;
use App\Models\AnonsPublication;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\AnonsMetricsService;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\PublicationManifest;
use App\Services\Anons\SessionHealthProbe;
use App\Services\Stories\StoryPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Anons\Support\RecordingFakeAdapter;
use Tests\TestCase;

/**
 * H5049 R2/R6/R7/R8/R12/R14: идемпотентный репаблиш, независимые
 * автоматы адресатов, явная отсутствность метрик, резюм серии,
 * изоляция тест-контура, сбор статистики.
 */
class AnonsPublishingServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $asset;

    private RecordingFakeAdapter $story;

    private AdapterRegistry $registry;

    private AnonsPublishingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Тестовая конфигурация: подпроцессная полоса выключена →
        // StoryPublisher и SessionHealthProbe не открывают реальный MP-клиент.
        config(['services.telegram_story.subprocess_lane' => false]);

        $this->asset = storage_path('app/testing/h5049/story-base.jpg');
        @mkdir(dirname($this->asset), 0775, true);
        $img = imagecreatetruecolor(1080, 1920);
        imagefilledrectangle($img, 0, 0, 1079, 1919, imagecolorallocate($img, 220, 210, 200));
        imagejpeg($img, $this->asset, 90);
        imagedestroy($img);

        $this->story = new RecordingFakeAdapter;
        $this->registry = new AdapterRegistry;
        $this->registry->register($this->story);

        $this->service = new AnonsPublishingService(
            $this->registry,
            new CtaPlaqueCompositor,
            new PlaqueInspector,
            new SessionHealthProbe,
            app(StoryPublisher::class),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->asset);
        parent::tearDown();
    }

    private function manifestData(array $overrides = []): array
    {
        $frames = $overrides['frame_count'] ?? 1;
        unset($overrides['frame_count']);
        $frameList = [];
        for ($i = 0; $i < $frames; $i++) {
            $frameList[] = [
                'asset' => $this->asset,
                'caption' => 'Кадр '.($i + 1).': набор осенней группы с нуля.',
                'alt_text' => 'Анонс осенней группы, кадр '.($i + 1),
                'cta_text' => 'страница записи',
                'cta_url' => 'https://samskrte.ru/ga/m26-rusamskrtam-st-sep-20260918-0'.($i + 1),
            ];
        }

        return array_merge([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'sep-start',
            'slot' => '2026-09-18T08:00',
            'frames' => $frameList,
            'destinations' => [
                ['platform' => 'telegram_story', 'account' => 'rusamskrtam'],
            ],
        ], $overrides);
    }

    /** @test */
    public function rerunning_same_manifest_never_duplicates(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData());

        $first = $this->service->publish($m);
        $this->assertSame(AnonsPublication::STATUS_PUBLISHED, $first->status);
        $runsAfterFirst = $first->runs()->count();
        $sentAfterFirst = count($this->story->published);

        // Повторный прогон ТОГО ЖЕ манифеста: дублей нет.
        $second = $this->service->publish(PublicationManifest::fromArray($this->manifestData()));

        $this->assertSame($first->id, $second->id, 'Same manifest resolves the same publication row.');
        $this->assertSame($runsAfterFirst, $second->runs()->count(), 'No new destination runs.');
        $this->assertSame(count($this->story->published), $sentAfterFirst, 'Adapter must NOT be called again for a published publication.');

        // Remote ids пережили второй прогон.
        $run = $second->runs()->first();
        $this->assertNotNull($run->remote_ids);
    }

    /** @test */
    public function mutated_manifest_with_same_key_is_rejected(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData());
        $this->service->publish($m);

        $mutated = $this->manifestData();
        $mutated['frames'][0]['cta_text'] = 'другой CTA';
        $mutated['slot'] = $this->manifestData()['slot']; // тот же slot → тот же ключ

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency forbids silent mutation');
        $this->service->publish(PublicationManifest::fromArray($mutated));
    }

    /** @test */
    public function transient_failure_retries_with_backoff_and_other_frames_continue(): void
    {
        // Падают frame 0 и frame 1: только первые две попытки.
        $this->story->failuresRemaining = 2;

        $m = PublicationManifest::fromArray($this->manifestData(['frame_count' => 3]));
        $publication = $this->service->publish($m);

        $this->assertSame(AnonsPublication::STATUS_PARTIAL, $publication->status);

        $runs = $publication->runs()->orderBy('frame_index')->get();
        $this->assertSame(3, $runs->count(), 'One run row per frame even when failures happen.');
        $this->assertSame(AnonsDestinationRun::STATE_FAILED, $runs[0]->state);
        $this->assertSame(AnonsDestinationRun::STATE_FAILED, $runs[1]->state);
        $this->assertSame(AnonsDestinationRun::STATE_PUBLISHED, $runs[2]->state, 'Failure on one frame must not block others (R8).');
        $this->assertSame(1, $runs[0]->attempts);
        $this->assertNotNull($runs[0]->next_retry_at, 'Transient failure schedules a bounded retry.');

        // Резюм (R2+R12): тот же манифест достраивает серию без дублей.
        $second = $this->service->publish(PublicationManifest::fromArray($this->manifestData(['frame_count' => 3])));
        $this->assertSame(AnonsPublication::STATUS_PUBLISHED, $second->status);
        $this->assertSame(3, $second->runs()->where('state', AnonsDestinationRun::STATE_PUBLISHED)->count());

        // Итог: 3 adapter-вызова (1 успешный кадр первого прогона + 2 достроенных).
        $this->assertSame(3, count($this->story->published));
    }

    /** @test */
    public function permanent_errors_fail_closed(): void
    {
        $this->story->failuresRemaining = 1;
        $this->story->failureMessage = 'asset file missing: /gone.jpg';

        $m = PublicationManifest::fromArray($this->manifestData());
        $publication = $this->service->publish($m);

        $run = $publication->runs()->first();
        $this->assertSame(AnonsDestinationRun::STATE_BLOCKED, $run->state, 'Permanent validation error must fail closed.');
        $this->assertNull($run->next_retry_at);
    }

    /** @test */
    public function metrics_distinguish_zero_from_unavailable(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData());
        $publication = $this->service->publish($m);
        $this->assertSame(AnonsPublication::STATUS_PUBLISHED, $publication->status);

        // Клик по /ga/ (R6): счётчик в нашей БД.
        AnonsLinkClick::query()->create([
            'link' => 'm26-rusamskrtam-st-sep-20260918-01',
            'publication_key' => $publication->publication_key,
            'utm' => null, 'clicked_at' => now(),
        ]);

        $readout = new AnonsMetricsService($this->registry)->collect($publication->publication_key);

        $dest = $readout['destinations'][0];
        $this->assertSame('value', $dest['metrics']['views']['state']);
        $this->assertSame(42, $dest['metrics']['views']['value']);

        // R7: поле API отсутствует → unavailable, НЕ ноль.
        $this->assertSame('unavailable', $dest['metrics']['reactions']['state']);
        $this->assertNull($dest['metrics']['reactions']['value']);

        // Клики — value, и счётчик равен 1 (не ноль, не unavailable).
        $this->assertSame('value', $dest['metrics']['link_clicks']['state']);
        $this->assertSame(1, $dest['metrics']['link_clicks']['value']);

        // Наблюдения персистятся с явными состояниями.
        $states = AnonsMetric::query()
            ->where('publication_key', $publication->publication_key)
            ->pluck('state', 'metric');
        $this->assertSame('value', $states['views']);
        $this->assertSame('unavailable', $states['reactions']);
        $this->assertSame('unavailable', $states['forwards']);
        $this->assertSame('value', $states['link_clicks']);
    }

    /** @test */
    public function test_mode_isolates_from_production(): void
    {
        $m = PublicationManifest::fromArray($this->manifestData([
            'test_mode' => true,
            'test_destination' => ['platform' => 'telegram_story', 'account' => 'marcis_test'],
        ]));

        $publication = $this->service->publish($m);

        $this->assertTrue($publication->test_mode);
        $accounts = $publication->runs()->pluck('account')->all();
        $this->assertSame(['marcis_test'], array_unique($accounts), 'Test mode must reach ONLY the private test destination.');

        $plan = $this->service->preview($m);
        foreach ($plan['frames'] as $frame) {
            $this->assertStringContainsString('_test_', $frame['utm']['utm_content'], 'Test UTM marker present.');
        }
    }
}
