<?php

declare(strict_types=1);

namespace App\Services\Anons\Adapters;

/**
 * H5049 R9: узкий адаптер платформы. Один нормализованный план публикации;
 * платформенные лимиты, entities и трансформации медиа живут ТОЛЬКО здесь,
 * кампании-логика их не дублирует.
 */
interface PlatformAdapter
{
    public function platform(): string;

    /**
     * Возможности поверхности: albums (медиа-группы), series (мультикадровые
     * сториз), visible_cta (вжариваемая плашка), metrics ([] список метрик).
     *
     * @return array{albums: bool, series: bool, visible_cta: bool, metrics: list<string>}
     */
    public function capabilities(): array;

    /**
     * Опубликовать один кадр нормализованного плана. Возвращает remote id.
     *
     * @param  array<string, mixed>  $frame  {artifact_path, caption, link, account, frame_index}
     * @return array{id: ?string, raw: array<string, mixed>}
     */
    public function publishFrame(array $frame): array;

    /**
     * Опубликовать несколько кадров одной серией/альбомом (если capability).
     * Возвращает remote id по каждому кадру в исходном порядке.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array{id: ?string, raw: array<string, mixed>}>
     */
    public function publishSeries(array $frames): array;

    /**
     * Метрики одной публикации: каждое поле ЯВНО состояниями R7
     * (value|unavailable|not_supported|pending|failed) — отсутствие поля
     * API НИКОГДА не превращается в ноль.
     *
     * @param  string  $remoteId  story/message id на платформе
     * @return array<string, array{state: string, value: ?int}>
     */
    public function metrics(string $remoteId, string $account): array;

    /**
     * Удалить СВОЮ публикацию (только recorded remote id; ручные посты
     * вне подсистемы недостижимы — bounded rollback R10).
     */
    public function delete(string $remoteId, string $account): void;
}
