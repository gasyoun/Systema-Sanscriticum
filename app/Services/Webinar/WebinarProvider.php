<?php

declare(strict_types=1);

namespace App\Services\Webinar;

/**
 * Провайдер-независимый контракт вебинарной встречи (GC-B3, H601). Vendor-агностичный
 * seam между приложением и конкретным сервисом (Zoom/BigBlueButton/...): вместо Zoom,
 * захардкоженного по всем слоям, каждый провайдер реализует три операции ниже.
 *
 * Не расширять этот интерфейс без нового тикета — сознательно минимальный (только то,
 * что реально используется: создание встречи, чтение участников, разбор вебхука).
 */
interface WebinarProvider
{
    /**
     * Создать встречу у провайдера.
     *
     * @param  array<string, mixed>  $options  Провайдеро-специфичные параметры (topic, start_time, duration, ...).
     * @return array{meeting_id: string, join_url: ?string, start_url: ?string}
     */
    public function createMeeting(array $options): array;

    /**
     * Участники прошедшей встречи/запуска (для pull-сверки посещаемости).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchParticipants(string $meetingUuid): array;

    /**
     * Разобрать сырой payload вебхука провайдера в нормализованную форму.
     * null — событие провайдеру известно, но приложению не интересно (игнорируется).
     *
     * @param  array<string, mixed>  $payload
     * @return array{event: string, meeting_id: string, occurrence_uuid: ?string, event_time: mixed, participant: array<string, mixed>}|null
     */
    public function normalizeWebhook(array $payload): ?array;
}
