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
     * Контракт WebinarProvider (GC-B3). Реализовано за флагом `zoom_auto_create`
     * (H1642, GC-B1 rescope, MG-руление 19-07-2026 → опция (b)): создаём РОВНО
     * ОДНУ recurring-встречу — единый-ручной-поток модель (`eda8059`,
     * 27-06-2026) стоит, per-schedule авто-создание НЕ возвращается. Вызывающая
     * сторона (`ScheduleGenerator`) отвечает за идемпотентность (не звать, если
     * у курса уже есть `zoom_meeting_id`) — этот метод сам не проверяет курс,
     * он не знает о моделях.
     *
     * `type = 8` (recurring, без фиксированного времени) — Zoom не требует
     * расписания повторов для этого типа; жёстко фиксируем, параметр `type` в
     * $params игнорируется, чтобы вызов не мог случайно создать одноразовую
     * встречу.
     *
     * @param  array<string, mixed>  $params  минимум 'topic'; необязательно 'settings'
     * @return array{id: string, join_url: string, start_url?: string}
     */
    public function createMeeting(array $params): array
    {
        $this->assertConfigured();

        $payload = array_merge([
            'settings' => [
                'join_before_host' => true,
                'waiting_room' => false,
            ],
        ], $params, [
            'type' => 8,
        ]);

        $response = $this->apiRequest()->post(self::API_BASE.'/users/me/meetings', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Zoom: не удалось создать встречу (HTTP {$response->status()})");
        }

        $id = $response->json('id');
        $joinUrl = $response->json('join_url');
        if ($id === null || ! is_string($joinUrl) || $joinUrl === '') {
            throw new RuntimeException('Zoom: ответ создания встречи без id/join_url');
        }

        return array_filter([
            'id' => (string) $id,
            'join_url' => $joinUrl,
            'start_url' => is_string($response->json('start_url')) ? $response->json('start_url') : null,
        ], static fn ($v) => $v !== null);
    }

    /** Контракт WebinarProvider: делегирует существующей выгрузке участников (Reports API). */
    public function fetchParticipants(string $meetingRef): array
    {
        return $this->meetingParticipants($meetingRef);
    }

    /**
     * Контракт WebinarProvider: нейтрализует Zoom-вебхук посещаемости.
     * Разбор в точности повторяет прежний ZoomWebhookController::handleParticipant —
     * контроллер теперь потребляет эту форму (поведение байт-в-байт).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function normalizeWebhook(array $payload): ?array
    {
        $event = isset($payload['event']) ? (string) $payload['event'] : '';
        if ($event !== 'meeting.participant_joined' && $event !== 'meeting.participant_left') {
            return null;
        }

        $object = (array) ($payload['payload']['object'] ?? []);
        $p = (array) ($object['participant'] ?? []);

        return [
            'event' => $event === 'meeting.participant_joined' ? 'participant_joined' : 'participant_left',
            'provider_event' => $event,
            'meeting_id' => isset($object['id']) ? (string) $object['id'] : '',
            'occurrence_uuid' => isset($object['uuid']) ? (string) $object['uuid'] : null,
            'event_time' => $object['start_time'] ?? ($p['join_time'] ?? null),
            'participant' => $p,
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
