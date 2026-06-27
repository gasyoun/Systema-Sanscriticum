<?php

declare(strict_types=1);

namespace App\Services\Zoom;

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
class ZoomService
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
