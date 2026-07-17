<?php

declare(strict_types=1);

namespace App\Services\Webinar;

/**
 * Провайдер вебинаров — шов GC-B3 (страховка от ухода Zoom, руление R1:
 * целевой альтернативный провайдер — BigBlueButton).
 *
 * Ровно три метода (контракт тикета): создание встречи, выгрузка участников,
 * нормализация вебхука в нейтральную форму. Всё провайдер-специфичное
 * (подписи транспорта, кодирование UUID, пагинация) остаётся внутри драйверов.
 */
interface WebinarProvider
{
    /**
     * Создать встречу. Возвращает как минимум id + join_url.
     *
     * @param  array<string, mixed>  $params  тема, время старта, длительность и т.п.
     * @return array{id: string, join_url: string, start_url?: string}
     */
    public function createMeeting(array $params): array;

    /**
     * Участники прошедшей встречи/запуска по провайдер-ссылке (id либо uuid запуска).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchParticipants(string $meetingRef): array;

    /**
     * Нормализовать сырой payload вебхука в нейтральное событие посещаемости:
     *
     *  [
     *    'event'          => 'participant_joined'|'participant_left',
     *    'provider_event' => '<сырое имя события провайдера>',
     *    'meeting_id'     => string,
     *    'occurrence_uuid'=> ?string,
     *    'event_time'     => ?string,
     *    'participant'    => array<string, mixed>,  // сырой блок участника провайдера
     *  ]
     *
     * null — событие не про посещаемость (валидации, записи и пр.).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function normalizeWebhook(array $payload): ?array;
}
