<?php

declare(strict_types=1);

namespace Tests\Feature\Anons;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsPublication;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Anons\Adapters\PlatformAdapter;
use App\Services\Anons\AnonsPublishingService;
use App\Services\Anons\CtaPlaqueCompositor;
use App\Services\Anons\PlaqueInspector;
use App\Services\Anons\PublicationManifest;
use App\Services\Anons\SessionHealthProbe;
use App\Services\Stories\StoryPublisher;
use App\Services\Telegram\MadelineSyncPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Anons\Support\RecordingFakeAdapter;
use Tests\TestCase;

/**
 * H5049 R13 (verifier gap 2): session recovery — классификация ошибок,
 * cooldown-гейт и интеграция «нездоровая сессия блокирует публикацию
 * ДО любого MTProto-вызова».
 */
class SessionHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flood_wait_parses_to_bounded_retry_after(): void
    {
        $probe = new SessionHealthProbe;

        $flood = $probe->classify('FLOOD_WAIT_42 (caused by stories.sendStory)');
        $this->assertSame(42, $flood['retry_after']);
        $this->assertFalse($flood['needs_reauth']);

        $flood2 = $probe->classify('FLOOD WAIT 3600');
        $this->assertSame(3600, $flood2['retry_after']);

        // Потолок час — штормить общий аккаунт нельзя.
        $capped = $probe->classify('FLOOD_WAIT_99999');
        $this->assertSame(3600, $capped['retry_after']);

        // Не-FLOOD → дефолтный backoff, не reauth.
        $default = $probe->classify('Timeout exceeded');
        $this->assertSame(SessionHealthProbe::DEFAULT_BACKOFF, $default['retry_after']);
        $this->assertFalse($default['needs_reauth']);
    }

    /** @test */
    public function auth_errors_require_human_reauthorization(): void
    {
        $probe = new SessionHealthProbe;

        foreach (['AUTH_KEY_UNREGISTERED', 'SESSION_REVOKED', 'USER_DEACTIVATED_BAN', '401 UNAUTHORIZED'] as $error) {
            $classified = $probe->classify($error);
            $this->assertTrue($classified['needs_reauth'], "{$error} must demand reauthorization.");
            $this->assertNull($classified['retry_after'], "{$error} must NOT schedule an automatic retry.");
        }
    }

    /** @test */
    public function post_timeout_cooldown_blocks_the_probe_without_opening_a_session(): void
    {
        // Та же сессия у support/harvest/stories — пост-таймаутный cooldown
        // обязан гасить и верификатор сессии (H3411-форма), без MTProto-вызова.
        MadelineSyncPhase::armCooldown(600);

        $probe = new SessionHealthProbe;
        $health = $probe->probe('rusamskrtam');

        $this->assertFalse($health['healthy']);
        $this->assertStringContainsString('cooldown', (string) $health['reason']);
        $this->assertGreaterThan(0, (int) $health['retry_after']);
        $this->assertFalse($health['needs_reauth']);
    }

    /** @test */
    public function needs_reauth_blocks_publish_fail_closed_before_any_send(): void
    {
        config(['services.telegram_story.subprocess_lane' => true]); // как на проде

        $asset = $this->makeAsset();
        $story = new RecordingFakeAdapter;
        $registry = new AdapterRegistry;
        $registry->register($story);

        $stub = new class implements PlatformAdapter
        {
            public function platform(): string
            {
                return 'telegram_story';
            }

            public function capabilities(): array
            {
                return ['albums' => false, 'series' => true, 'visible_cta' => true, 'metrics' => ['views']];
            }

            public function publishFrame(array $frame): array
            {
                return ['id' => '1', 'raw' => []];
            }

            public function publishSeries(array $frames): array
            {
                return [];
            }

            public function metrics(string $remoteId, string $account): array
            {
                return ['views' => ['state' => 'not_supported', 'value' => null]];
            }

            public function delete(string $remoteId, string $account): void {}
        };
        $registry->register($stub);

        $service = new AnonsPublishingService(
            $registry,
            new CtaPlaqueCompositor,
            new PlaqueInspector,
            new class extends SessionHealthProbe
            {
                public function probe(string $account = 'rusamskrtam'): array
                {
                    return ['healthy' => false, 'account' => $account,
                        'reason' => 'AUTH_KEY_UNREGISTERED', 'retry_after' => null, 'needs_reauth' => true];
                }
            },
            app(StoryPublisher::class),
        );

        try {
            $service->publish($this->manifest($asset));
            $this->fail('Publish must refuse when the session needs human reauthorization.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('reauthorization', $e->getMessage());
        }

        $this->assertSame(0, count($story->published), 'NO send may happen with a dead session (R13 fail-closed).');

        // H5094: автомат отправки даже не стартовал — ни одного run-а
        // (перенос гейта ниже цикла отправки поймала бы только эта проверка:
        // стаб-адаптер публикует успешно, и «после отправки» он бы отработал).
        $this->assertSame(
            0,
            AnonsDestinationRun::query()->count(),
            'send state machine must never start when the session needs reauthorization'
        );

        // Хранимое состояние: отказ обязан быть наблюдаемым в журнале
        // публикации — человеку нужно ВИДЕТЬ, почему история не вышла,
        // а не только ловить исключение в логах процесса.
        $publication = AnonsPublication::query()->first();
        $this->assertSame(AnonsPublication::STATUS_FAILED, $publication->status);
        $this->assertStringContainsString(
            'needs reauthorization',
            (string) $publication->journal,
            'R13 refusal must journal the reason on the publication row'
        );
        $this->assertStringContainsString('AUTH_KEY_UNREGISTERED', (string) $publication->journal);
    }

    private function makeAsset(): string
    {
        $dir = storage_path('app/testing/h5049/'.bin2hex(random_bytes(4)));
        @mkdir($dir, 0775, true);
        $img = imagecreatetruecolor(1080, 1920);
        imagefilledrectangle($img, 0, 0, 1079, 1919, imagecolorallocate($img, 222, 222, 222));
        imagejpeg($img, $dir.'/base.jpg', 90);
        imagedestroy($img);

        return $dir.'/base.jpg';
    }

    private function manifest(string $asset): PublicationManifest
    {
        return PublicationManifest::fromArray([
            'version' => 1,
            'campaign' => 'm26',
            'creative' => 'reauth-test',
            'slot' => '2026-09-17T1900',
            'frames' => [[
                'asset' => $asset,
                'caption' => 'Проверка R13.',
                'alt_text' => 'R13',
                'cta_text' => 'CTA',
                'cta_url' => 'https://samskrte.ru/ga/m26-rs-st-r13-20260917-01',
            ]],
            'destinations' => [['platform' => 'telegram_story', 'account' => 'rusamskrtam']],
        ]);
    }
}
