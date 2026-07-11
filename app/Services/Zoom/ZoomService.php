<?php

declare(strict_types=1);

namespace App\Services\Zoom;

use App\Services\Webinar\WebinarProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент Zoom API на Server-to-Server OAuth (issue #78, Phase 1).
 *
 * Поток: account_credentials grant → access_token (кэшируется ~55 мин) →
 * POST /v2/users/me/meetings. Секреты — только из config('services.zoom'),
 * в коде их нет; без полного набора кредов сервис «не сконфигурирован».
 *
 * Реализует провайдеро-независимый {@see WebinarProvider} (GC-B3, H601) —
 * извлечение интерфейса без изменения существующего поведения (методы ниже
 * оставлены как есть; createMeeting/fetchParticipants/normalizeWebhook —
 * тонкий адаптер поверх них).
 */
class ZoomService implements WebinarProvider
{
    private const OAUTH_URL = 'https://zoom.us/oauth/token';

    private const API_BASE = 'https://api.zoom.us/v2';

    private const TOKEN_CACHE_KEY = 'zoom.s2s.access_token';

    public function __construct(
        private readonly ?string $accountId,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            accountId: config('services.zoom.account_id'),
            clientId: config('services.zoom.client_id'),
            clientSecret: config('services.zoom.client_secret'),
            timeout: (int) config('services.zoom.timeout', 30),
        );
    }

    /** Заданы ли все креды (иначе обращения к Zoom API не делаем). */
    public function isConfigured(): bool
    {
        return ! empty($this->accountId) && ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Access-token через account_credentials grant. Кэшируем чуть короче, чем
     * реальный TTL (Zoom отдаёт ~3600с), чтобы не словить просрочку на границе.
     */
    public function accessToken(): string
    {
        $this->assertConfigured();

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(55), function (): string {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->timeout($this->timeout)
                ->post(self::OAUTH_URL, [
                    'grant_type' => 'account_credentials',
                    'account_id' => $this->accountId,
                ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException("Zoom: не удалось получить access_token (HTTP {$response->status()})");
            }

            return $token;
        });
    }

    /**
     * Прошедшие запуски recurring-встречи: [{uuid, start_time}, ...].
     * При едином meeting_id каждый запуск имеет свой uuid — по нему привязываем
     * посещаемость к конкретной дате занятия.
     *
     * @return array<int, array{uuid?: string, start_time?: string}>
     */
    public function pastMeetingInstances(string $meetingId): array
    {
        $response = $this->apiRequest()->get(self::API_BASE."/past_meetings/{$meetingId}/instances");

        return $response->successful() ? (array) $response->json('meetings', []) : [];
    }

    /**
     * Участники прошедшей встречи/запуска (Reports API), с пагинацией.
     * $meetingUuid — uuid конкретного запуска (instances) либо meeting id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function meetingParticipants(string $meetingUuid): array
    {
        $encoded = $this->encodeUuid($meetingUuid);
        $participants = [];
        $token = '';

        do {
            $response = $this->apiRequest()->get(
                self::API_BASE."/report/meetings/{$encoded}/participants",
                array_filter(['page_size' => 300, 'next_page_token' => $token ?: null]),
            );

            if (! $response->successful()) {
                break;
            }

            $participants = array_merge($participants, (array) $response->json('participants', []));
            $token = (string) $response->json('next_page_token', '');
        } while ($token !== '');

        return $participants;
    }

    /**
     * WebinarProvider::createMeeting. Ранее вызывалось из ScheduleResource
     * (issue #78), убрано в H487 — встречи создаются вручную (H487 dev notes).
     * Метод восстановлен как часть провайдерного шва: ни один текущий вызывающий
     * код на него не полагается, поведение существующих методов не менялось.
     *
     * @param  array<string, mixed>  $options  topic/start_time/duration/timezone/settings.
     * @return array{meeting_id: string, join_url: ?string, start_url: ?string}
     */
    public function createMeeting(array $options): array
    {
        $response = $this->apiRequest()->post(self::API_BASE.'/users/me/meetings', [
            'topic' => $options['topic'] ?? 'Занятие',
            'type' => $options['type'] ?? 2, // 2 = scheduled meeting
            'start_time' => $options['start_time'] ?? null,
            'duration' => $options['duration'] ?? null,
            'timezone' => $options['timezone'] ?? 'Europe/Moscow',
            'settings' => $options['settings'] ?? [],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zoom: не удалось создать встречу (HTTP {$response->status()})");
        }

        return [
            'meeting_id' => (string) $response->json('id'),
            'join_url' => $response->json('join_url'),
            'start_url' => $response->json('start_url'),
        ];
    }

    /**
     * WebinarProvider::fetchParticipants — тонкий алиас над {@see meetingParticipants()},
     * которая остаётся публичной ради существующих вызывающих ({@see \App\Console\Commands\SyncZoomAttendance}).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchParticipants(string $meetingUuid): array
    {
        return $this->meetingParticipants($meetingUuid);
    }

    /**
     * WebinarProvider::normalizeWebhook — тот же разбор payload, что раньше жил
     * внутри {@see \App\Http\Controllers\Webhooks\ZoomWebhookController::handleParticipant()},
     * вынесенный сюда без изменения формы результата. Проверка подписи и
     * url_validation-челлендж остаются в контроллере (это транспортный слой
     * Zoom-вебхука, не часть провайдерного контракта).
     *
     * @param  array<string, mixed>  $payload  Полное тело Zoom-вебхука (event + payload.object).
     * @return array{event: string, meeting_id: string, occurrence_uuid: ?string, event_time: mixed, participant: array<string, mixed>}|null
     */
    public function normalizeWebhook(array $payload): ?array
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event !== 'meeting.participant_joined' && $event !== 'meeting.participant_left') {
            return null;
        }

        $object = (array) (($payload['payload'] ?? [])['object'] ?? []);
        $participant = (array) ($object['participant'] ?? []);

        return [
            'event' => $event,
            'meeting_id' => isset($object['id']) ? (string) $object['id'] : '',
            'occurrence_uuid' => isset($object['uuid']) ? (string) $object['uuid'] : null,
            'event_time' => $object['start_time'] ?? ($participant['join_time'] ?? null),
            'participant' => $participant,
        ];
    }

    /**
     * Zoom требует ДВОЙНОГО url-кодирования UUID, если он начинается с '/' или
     * содержит '//'. Иначе — одинарное.
     */
    private function encodeUuid(string $uuid): string
    {
        if (str_starts_with($uuid, '/') || str_contains($uuid, '//')) {
            return rawurlencode(rawurlencode($uuid));
        }

        return rawurlencode($uuid);
    }

    private function apiRequest(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Zoom не сконфигурирован: задайте ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET в .env');
        }
    }
}
