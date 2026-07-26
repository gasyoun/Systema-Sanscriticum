<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Webinar\BigBlueButtonService;
use App\Services\Webinar\WebinarProvider;
use App\Services\Zoom\ZoomService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Шов GC-B3: интерфейс WebinarProvider, Zoom-драйвер, BBB-скелет.
 * Чистые unit-проверки (без контейнера/БД) — normalizeWebhook и стабы
 * не трогают ни HTTP, ни кэш.
 */
class WebinarProviderSeamTest extends TestCase
{
    private function zoom(): ZoomService
    {
        return new ZoomService(accountId: 'acc', clientId: 'id', clientSecret: 'secret');
    }

    public function test_zoom_service_implements_the_seam(): void
    {
        $this->assertInstanceOf(WebinarProvider::class, $this->zoom());
    }

    public function test_bbb_skeleton_implements_the_seam(): void
    {
        $this->assertInstanceOf(WebinarProvider::class, new BigBlueButtonService(null, null));
    }

    public function test_normalize_webhook_maps_participant_joined(): void
    {
        $norm = $this->zoom()->normalizeWebhook([
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => [
                'id' => 987654321,
                'uuid' => 'abc==',
                'start_time' => '2026-07-17T10:00:00Z',
                'participant' => ['user_name' => 'Ученик', 'email' => 's@example.org', 'join_time' => '2026-07-17T10:01:00Z'],
            ]],
        ]);

        $this->assertNotNull($norm);
        $this->assertSame('participant_joined', $norm['event']);
        $this->assertSame('meeting.participant_joined', $norm['provider_event']);
        $this->assertSame('987654321', $norm['meeting_id']);
        $this->assertSame('abc==', $norm['occurrence_uuid']);
        $this->assertSame('2026-07-17T10:00:00Z', $norm['event_time']);
        $this->assertSame('Ученик', $norm['participant']['user_name']);
    }

    public function test_normalize_webhook_falls_back_to_join_time(): void
    {
        $norm = $this->zoom()->normalizeWebhook([
            'event' => 'meeting.participant_left',
            'payload' => ['object' => [
                'id' => '1',
                'participant' => ['join_time' => '2026-07-17T10:01:00Z'],
            ]],
        ]);

        $this->assertNotNull($norm);
        $this->assertSame('participant_left', $norm['event']);
        $this->assertNull($norm['occurrence_uuid']);
        $this->assertSame('2026-07-17T10:01:00Z', $norm['event_time']);
    }

    public function test_normalize_webhook_ignores_non_attendance_events(): void
    {
        $zoom = $this->zoom();
        $this->assertNull($zoom->normalizeWebhook(['event' => 'recording.completed']));
        $this->assertNull($zoom->normalizeWebhook(['event' => 'endpoint.url_validation']));
        $this->assertNull($zoom->normalizeWebhook([]));
    }

    /**
     * GC-B1 rescope (H1642, MG-руление 19-07-2026 → опция (b)): `createMeeting()`
     * больше не перманентно удалён — за флагом `zoom_auto_create` он делает
     * реальный вызов Zoom API (см. `tests/Feature/ZoomAutoCreateTest.php`,
     * которому нужен контейнер Laravel + `Http::fake` — недоступно в этом чистом
     * PHPUnit-юните). Что этот юнит-тест ещё МОЖЕТ проверить без сети: гвард
     * кредов срабатывает ДО любого сетевого вызова, как и во всех остальных
     * методах ZoomService — сохраняем тест как «страховку от полной пустоты»,
     * а не удаляем. Per-schedule авто-создание НЕ возвращается — это отдельно
     * закрыто `ZoomAutoCreateTest::test_per_schedule_creation_direct_schedule_never_calls_zoom`.
     */
    public function test_create_meeting_requires_configured_credentials(): void
    {
        $unconfigured = new ZoomService(accountId: null, clientId: null, clientSecret: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не сконфигурирован');
        $unconfigured->createMeeting(['topic' => 'x']);
    }

    public function test_bbb_skeleton_methods_throw_until_q4_deployment(): void
    {
        $bbb = new BigBlueButtonService(null, null);
        $this->assertFalse($bbb->isConfigured());

        foreach (['createMeeting' => [[]], 'fetchParticipants' => ['m1'], 'normalizeWebhook' => [[]]] as $method => $args) {
            try {
                $bbb->{$method}(...$args);
                $this->fail("BBB skeleton {$method}() must throw");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('GC-B3', $e->getMessage());
            }
        }
    }
}
