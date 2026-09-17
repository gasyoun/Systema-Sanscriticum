<?php

declare(strict_types=1);

namespace App\Services\Anons;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsLinkClick;
use App\Models\AnonsMetric;
use App\Models\AnonsPublication;
use App\Services\Anons\Adapters\AdapterRegistry;

/**
 * H5049 R6+R7: автоматический сбор статистики и ЯВНАЯ модель отсутствия.
 *
 * Сбор: story views/reactions/forwards (адаптер) + клики /ga/ (anons_link_
 * clicks по publication_key). Каждое наблюдение пишется со state —
 * значение есть (value), API-поле отсутствует (unavailable), поверхность
 * не умеет (not_supported), ещё не время (pending), сбор упал (failed).
 * Ноль в данных ≠ отсутствие данных.
 */
final class AnonsMetricsService
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * Собрать наблюдения по публикации. Возвращает readout: метрики по
     * адресатам + агрегированные клики по короткой ссылке.
     *
     * @return array<string, mixed>
     */
    public function collect(string $publicationKey): array
    {
        $publication = AnonsPublication::query()->where('publication_key', $publicationKey)->first();
        if ($publication === null) {
            return ['publication_key' => $publicationKey, 'error' => 'unknown publication key'];
        }

        $readout = ['publication_key' => $publicationKey, 'campaign' => $publication->campaign_id, 'destinations' => []];

        foreach ($publication->runs()->where('state', AnonsDestinationRun::STATE_PUBLISHED)->get() as $run) {
            $adapter = $this->registry->for($run->platform);
            $observations = [];

            $remoteId = $run->remote_ids[$run->frame_index] ?? null;
            if ($remoteId === null) {
                $observations = ['views' => ['state' => 'pending', 'value' => null]];
            } else {
                foreach ($adapter->capabilities()['metrics'] as $metric) {
                    $result = $adapter->metrics($remoteId, $run->account);
                    $obs = $result[$metric] ?? ['state' => 'not_supported', 'value' => null];
                    $observations[$metric] = $obs;
                }
                if ($adapter->capabilities()['metrics'] === []) {
                    foreach (['views', 'reactions', 'forwards'] as $metric) {
                        $observations[$metric] = ['state' => 'not_supported', 'value' => null];
                    }
                }
            }

            // Клики по /ga/ — always available (счётчик живёт у нас).
            // short_link хранит полный URL; счётчик индексирован слагом.
            $slug = (string) preg_replace('~^https?://[^/]+/ga/~i', '', (string) $run->short_link);
            $clicks = AnonsLinkClick::query()
                ->where('publication_key', $publicationKey)
                ->where('link', $slug)
                ->count();
            $observations['link_clicks'] = ['state' => 'value', 'value' => $clicks];

            $observedAt = now();
            foreach ($observations as $metric => $obs) {
                AnonsMetric::query()->create([
                    'publication_key' => $publicationKey,
                    'destination' => $run->destination,
                    'metric' => $metric,
                    'state' => $obs['state'],
                    'value' => $obs['value'],
                    'utm' => $run->utm,
                    'observed_at' => $observedAt,
                ]);
            }

            $readout['destinations'][] = [
                'destination' => $run->destination,
                'frame' => $run->frame_index,
                'short_link' => $run->short_link,
                'utm' => $run->utm,
                'metrics' => $observations,
            ];
        }

        return $readout;
    }

    /**
     * История наблюдений (кампания-ридаут без ручных join'ов).
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $publicationKey): array
    {
        return AnonsMetric::query()
            ->where('publication_key', $publicationKey)
            ->orderBy('observed_at')
            ->get()
            ->map(static fn (AnonsMetric $m) => [
                'destination' => $m->destination, 'metric' => $m->metric,
                'state' => $m->state, 'value' => $m->value, 'observed_at' => $m->observed_at->toIso8601String(),
            ])->all();
    }
}
