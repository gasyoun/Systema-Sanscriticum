<?php

declare(strict_types=1);

namespace App\Services\Zoom;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
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

    /** Заданы ли все креды (иначе кнопку создания встречи не показываем). */
    public function isConfigured(): bool
    {
        return ! empty($this->accountId) && ! empty($this->clientId) && ! empty($this->clientSecret);
    }

    /**
     * Создаёт запланированную встречу. Возвращает то, что нужно сохранить в Schedule.
     *
     * @return array{id: string, join_url: string, start_url: string}
     */
    public function createMeeting(
        string $topic,
        Carbon $start,
        int $durationMinutes,
        ?string $agenda = null,
        ?string $timezone = null,
    ): array {
        $this->assertConfigured();

        $response = $this->apiRequest()->post(self::API_BASE.'/users/me/meetings', [
            'topic' => $topic,
            'type' => 2, // scheduled meeting
            'start_time' => $start->format('Y-m-d\TH:i:s'),
            'duration' => max(1, $durationMinutes),
            'timezone' => $timezone ?: config('app.timezone', 'Europe/Moscow'),
            'agenda' => $agenda ? mb_substr($agenda, 0, 2000) : '',
            'settings' => [
                'join_before_host' => true,
                'waiting_room' => false,
                'approval_type' => 2, // no registration required
            ],
        ]);

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || empty($body['id'])) {
            $msg = is_array($body) ? ($body['message'] ?? 'неизвестная ошибка') : 'не-JSON ответ';
            throw new RuntimeException("Zoom: не удалось создать встречу (HTTP {$response->status()}): {$msg}");
        }

        return [
            'id' => (string) $body['id'],
            'join_url' => (string) ($body['join_url'] ?? ''),
            'start_url' => (string) ($body['start_url'] ?? ''),
        ];
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
