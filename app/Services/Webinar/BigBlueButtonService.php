<?php

declare(strict_types=1);

namespace App\Services\Webinar;

use RuntimeException;

/**
 * Скелет BBB-драйвера (GC-B3, руление R1: цель = BigBlueButton — есть
 * attendance API и записи Moodle-класса, в отличие от Jitsi/LiveKit).
 *
 * НЕ рабочая интеграция: развертывание BBB (сервер, секреты, живой синк
 * посещаемости) — отдельная инфраструктурная задача Q4. Здесь — форма API
 * и место, куда она встанет, чтобы шов WebinarProvider был проверяем уже сейчас.
 *
 * Форма BBB API (для будущей реализации; все вызовы — GET с checksum
 * = sha1(callName + queryString + sharedSecret)):
 *  - create        → POST/GET /api/create?meetingID=...&checksum=...
 *  - join          → /api/join?fullName=...&meetingID=...&role=...
 *  - getMeetingInfo→ /api/getMeetingInfo?meetingID=...   (участники live)
 *  - getRecordings → /api/getRecordings?meetingID=...
 *  Вебхуки — отдельное приложение bbb-webhooks (события meeting/user/rap).
 */
class BigBlueButtonService implements WebinarProvider
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $sharedSecret,
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('services.bbb.base_url'),
            sharedSecret: config('services.bbb.shared_secret'),
            timeout: (int) config('services.bbb.timeout', 30),
        );
    }

    /** Заданы ли креды (сервер + секрет). Скелет всегда «не сконфигурирован» без них. */
    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->sharedSecret);
    }

    public function createMeeting(array $params): array
    {
        throw new RuntimeException(
            'BigBlueButton: драйвер — скелет (GC-B3). Развертывание BBB — отдельная задача Q4; '
            .'см. docs/ROADMAP_GETCOURSE_PARITY_2026.md и руление R1.'
        );
    }

    public function fetchParticipants(string $meetingRef): array
    {
        throw new RuntimeException(
            'BigBlueButton: драйвер — скелет (GC-B3). getMeetingInfo/getRecordings появятся с развертыванием (Q4).'
        );
    }

    public function normalizeWebhook(array $payload): ?array
    {
        throw new RuntimeException(
            'BigBlueButton: драйвер — скелет (GC-B3). События придут из bbb-webhooks с развертыванием (Q4).'
        );
    }
}
