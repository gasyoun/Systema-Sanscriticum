<?php

declare(strict_types=1);

namespace App\Services\Webinar;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * BigBlueButton driver — СКЕЛЕТ (GC-B3, H601). Целевой провайдер по ruling R1
 * ({@see https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md}) —
 * этот тикет доставляет только шов+скелет, полная интеграция (провижининг сервера,
 * секреты, живая сверка посещаемости) — отдельная Q4-задача. Методы ниже
 * НЕ делают реальных HTTP-вызовов к BBB и намеренно бросают исключение — вызывать
 * их до реализации Q4-задачи не нужно.
 *
 * API-форма (для будущей реализации): BBB не использует OAuth, как Zoom — каждый
 * запрос подписывается SHA-параметрами вида
 * `sha256(apiCallName + queryString + secret)`, см. {@see checksum()}/{@see apiUrl()}.
 */
class BigBlueButtonService implements WebinarProvider
{
    private const API_CREATE = 'create';

    private const API_GET_MEETING_INFO = 'getMeetingInfo';

    private const API_GET_RECORDINGS = 'getRecordings';

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $secret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('services.bigbluebutton.base_url'),
            secret: config('services.bigbluebutton.secret'),
        );
    }

    /** Заданы ли base_url + secret (симметрично ZoomService::isConfigured()). */
    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->secret);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{meeting_id: string, join_url: ?string, start_url: ?string}
     */
    public function createMeeting(array $options): array
    {
        $this->assertConfigured();

        // Демонстрирует реальную форму запроса (create + meetingID/name), но
        // намеренно не отправляется — см. class docblock.
        $this->apiUrl(self::API_CREATE, [
            'name' => $options['topic'] ?? 'Занятие',
            'meetingID' => $options['meeting_id'] ?? (string) Str::uuid(),
        ]);

        throw new RuntimeException(
            'BigBlueButtonService::createMeeting() — скелет без реализации; полная интеграция BBB вне области GC-B3 (Q4, см. H601).'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchParticipants(string $meetingUuid): array
    {
        $this->assertConfigured();

        $this->apiUrl(self::API_GET_MEETING_INFO, ['meetingID' => $meetingUuid]);

        throw new RuntimeException(
            'BigBlueButtonService::fetchParticipants() — скелет без реализации; полная интеграция BBB вне области GC-B3 (Q4, см. H601).'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{event: string, meeting_id: string, occurrence_uuid: ?string, event_time: mixed, participant: array<string, mixed>}|null
     */
    public function normalizeWebhook(array $payload): ?array
    {
        throw new RuntimeException(
            'BigBlueButtonService::normalizeWebhook() — скелет без реализации; полная интеграция BBB вне области GC-B3 (Q4, см. H601).'
        );
    }

    /**
     * URL запроса к BBB API с checksum-подписью (форма, не для реального вызова
     * в этом скелете). Реализация getRecordings() — тоже Q4, но константа уже
     * зарезервирована, чтобы имя эндпоинта было согласовано заранее.
     *
     * @param  array<string, mixed>  $params
     */
    private function apiUrl(string $apiCall, array $params = []): string
    {
        $query = http_build_query($params);

        return rtrim((string) $this->baseUrl, '/')."/bigbluebutton/api/{$apiCall}?{$query}&checksum=".$this->checksum($apiCall, $query);
    }

    private function checksum(string $apiCall, string $queryString): string
    {
        return hash('sha256', $apiCall.$queryString.$this->secret);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('BigBlueButton не сконфигурирован: задайте BBB_BASE_URL/BBB_SECRET в .env');
        }
    }
}
