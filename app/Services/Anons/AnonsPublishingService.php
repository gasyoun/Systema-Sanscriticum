<?php

declare(strict_types=1);

namespace App\Services\Anons;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsPublication;
use App\Services\Anons\Adapters\AdapterRegistry;
use App\Services\Stories\StoryPublisher;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * H5049 R2/R3/R8/R10/R12/R14: оркестратор declarative-публикации.
 *
 * Один вход (манифест) → validate → preview (R3) → per-destination publish
 * (R8: свой автомат, bounded backoff, fail-closed на постоянных ошибках)
 * → idempotent-резюм по publication_key (R2) → серия кадров в порядке
 * манифеста с разными utm_content (R12) → safe test mode (R14: приватный
 * контур, тестовые UTM, прод-адресаты недостижимы, промоция переиспользует
 * принятый артефакт по content-hash без перерендера).
 *
 * CLI (anons:*) и HTTP API (AnonsApiController) зовут ОДИН этот сервис.
 */
final class AnonsPublishingService
{
    private const MAX_ATTEMPTS = 3;

    private const BACKOFF_BASE = 120;

    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly CtaPlaqueCompositor $compositor,
        private readonly PlaqueInspector $inspector,
        private readonly SessionHealthProbe $probe,
        private readonly StoryPublisher $storyPublisher,
    ) {}

    /** R1+R10: validate — схемная проверка + адаптеры. */
    public function validate(PublicationManifest $manifest): array
    {
        $errors = $manifest->validate();
        foreach ($manifest->effectiveDestinations() as $dest) {
            try {
                $this->registry->for($dest['platform']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * R3+R10: preview — рендерит каждый кадр ТОЧНО как его получит адаптер,
     * публикует ничего. Возвращает план с артефактами, границами плашки,
     * клик-прямоугольником, подписями и UTM-кортежами.
     *
     * @return array<string, mixed>
     */
    public function preview(PublicationManifest $manifest): array
    {
        if ($errors = $this->validate($manifest)) {
            throw new RuntimeException('Manifest validation failed: '.implode(' ', $errors));
        }

        $plan = [];
        $dir = storage_path('app/anons/preview/'.$manifest->hash());
        foreach ($manifest->effectiveDestinations() as $dest) {
            foreach ($manifest->frames() as $i => $frame) {
                $artifact = $this->renderFrame($frame, "{$dir}/f{$i}-{$dest['account']}.jpg");
                $plan[] = $this->framePlan($manifest, $dest, $i, $frame, $artifact);
            }
        }

        return ['manifest_hash' => $manifest->hash(), 'publication_key' => PublicationKey::fromManifest($manifest), 'preview_dir' => $dir, 'frames' => $plan];
    }

    /**
     * R2+R8+R10+R12+R14: publish — идемпотентно публикует манифест.
     * Повторный вызов того же манифеста резюмирует/отчитывает существующую
     * публикацию; дубль создать невозможно (publication_key unique).
     */
    public function publish(PublicationManifest $manifest, bool $promote = false): AnonsPublication
    {
        $errors = $this->validate($manifest);
        if ($errors !== []) {
            throw new RuntimeException('Manifest validation failed: '.implode(' ', $errors));
        }

        if ($manifest->isTestMode() && $promote) {
            throw new RuntimeException('Cannot promote: manifest is still test_mode=true — flip test_mode in the manifest first.');
        }

        $key = PublicationKey::fromManifest($manifest);
        $existing = AnonsPublication::query()->where('publication_key', $key)->first();

        if ($existing !== null) {
            // Иммутабельность принятого артефакта: изменение манифеста при
            // том же ключе запрещено — нужен новый creative/slot.
            if ($existing->manifest_hash !== $manifest->hash()) {
                throw new RuntimeException(
                    "Publication {$key} exists with a DIFFERENT manifest ({$existing->manifest_hash} vs {$manifest->hash()}). "
                    .'Idempotency forbids silent mutation — bump creative or slot.'
                );
            }
            $publication = $existing;
            if ($publication->status === AnonsPublication::STATUS_PUBLISHED) {
                return $publication; // R2: nothing to do, report existing
            }
        } else {
            $publication = AnonsPublication::query()->create([
                'publication_key' => $key,
                'campaign_id' => (string) $manifest->data['campaign'],
                'creative_id' => (string) $manifest->data['creative'],
                'slot' => (string) $manifest->data['slot'],
                'manifest_hash' => $manifest->hash(),
                'manifest' => $manifest->data,
                'test_mode' => $manifest->isTestMode(),
                'frame_total' => count($manifest->frames()),
                'status' => AnonsPublication::STATUS_READY,
            ]);
        }

        $publication->forceFill(['status' => AnonsPublication::STATUS_PUBLISHING])->save();

        // R13: сессия должна быть ЖИВОЙ, не «есть файл на диске».
        $sessionOk = true;
        foreach ($manifest->effectiveDestinations() as $dest) {
            if ($dest['platform'] === 'telegram_story' && $this->storyPublisher->viaSubprocess()) {
                $health = $this->probe->probe($dest['account']);
                if (! $health['healthy']) {
                    if ($health['needs_reauth']) {
                        $publication->forceFill(['status' => AnonsPublication::STATUS_FAILED,
                            'journal' => trim((string) $publication->journal.'
'.now()->toDateTimeString()
                                ." R13: session {$dest['account']} needs reauthorization: {$health['reason']}")])->save();
                        throw new RuntimeException("Session for {$dest['account']} needs human reauthorization: {$health['reason']}");
                    }
                    Log::warning('Anons publish: session unhealthy, retry later', $health);
                    $sessionOk = false;
                }
            }
        }
        if (! $sessionOk) {
            $publication->forceFill(['status' => AnonsPublication::STATUS_FAILED])->save();

            throw new RuntimeException('Session health probe failed — publication deferred (bounded backoff, no attempt made).');
        }

        $dir = storage_path('app/anons/rendered/'.$key);
        $allPublished = true;

        foreach ($manifest->effectiveDestinations() as $dest) {
            $destination = strtolower($dest['platform'].'@'.$dest['account']);
            $adapter = $this->registry->for($dest['platform']);

            foreach ($manifest->frames() as $i => $frame) {
                $run = AnonsDestinationRun::query()->firstOrCreate(
                    [
                        'anons_publication_id' => $publication->id,
                        'destination' => $destination,
                        'frame_index' => $i,
                    ],
                    [
                        'platform' => $dest['platform'], 'account' => $dest['account'],
                        'state' => AnonsDestinationRun::STATE_PENDING, 'attempts' => 0,
                    ],
                );

                // R8: свой автомат каждого адресата; published не трогаем.
                if ($run->state === AnonsDestinationRun::STATE_PUBLISHED) {
                    continue;
                }
                if ($run->state === AnonsDestinationRun::STATE_BLOCKED) {
                    $allPublished = false;

                    continue;
                }
                // failed ретраится ручным репаблишем немедленно; next_retry_at —
                // diagnostics для планировщика, потолок — MAX_ATTEMPTS ниже.

                if ($run->attempts >= (int) config('services.anons.max_attempts', self::MAX_ATTEMPTS)) {
                    $run->forceFill(['state' => AnonsDestinationRun::STATE_BLOCKED,
                        'last_error' => 'attempts exhausted ('.self::MAX_ATTEMPTS.')'])->save();
                    $allPublished = false;

                    continue;
                }

                try {
                    $artifact = $this->renderFrame($frame, "{$dir}/f{$i}-{$dest['account']}.jpg");
                    $run->forceFill(['state' => AnonsDestinationRun::STATE_RUNNING, 'artifact_path' => $artifact])->save();

                    $plan = $this->framePlan($manifest, $dest, $i, $frame, $artifact);
                    $result = $adapter->publishFrame([
                        'artifact_path' => $artifact,
                        'caption' => $plan['caption'],
                        'link' => $plan['short_link'],
                        'account' => $dest['account'],
                        'frame_index' => $i,
                        'media_area' => $plan['media_area'],
                    ]);

                    $remoteIds = $run->remote_ids ?? [];
                    $remoteIds[$i] = $result['id'];
                    $run->forceFill([
                        'state' => AnonsDestinationRun::STATE_PUBLISHED,
                        'remote_ids' => $remoteIds,
                        'content_hash' => hash_file('sha256', $artifact) ?: null,
                        'short_link' => $plan['short_link'],
                        'utm' => $plan['utm'],
                        'last_error' => null,
                    ])->save();
                } catch (Throwable $e) {
                    $attempts = $run->attempts + 1;
                    $permanent = $this->isPermanent($e);
                    $run->forceFill([
                        'state' => $permanent ? AnonsDestinationRun::STATE_BLOCKED : AnonsDestinationRun::STATE_FAILED,
                        'attempts' => $attempts,
                        'last_error' => mb_substr($e->getMessage(), 0, 500),
                        'next_retry_at' => $permanent ? null : now()->addSeconds(self::BACKOFF_BASE * 2 ** ($attempts - 1)),
                    ])->save();
                    $allPublished = false;
                }
            }
        }

        $publication->forceFill([
            'status' => $allPublished
                ? AnonsPublication::STATUS_PUBLISHED
                : AnonsPublication::STATUS_PARTIAL,
        ])->save();

        return $publication;
    }

    /** R10: статус по ключу (CLI/API). */
    public function status(string $publicationKey): ?array
    {
        $publication = AnonsPublication::query()->where('publication_key', $publicationKey)->first();
        if ($publication === null) {
            return null;
        }

        return [
            'publication_key' => $publication->publication_key,
            'status' => $publication->status,
            'test_mode' => $publication->test_mode,
            'runs' => $publication->runs()->get()->map(static fn (AnonsDestinationRun $r) => [
                'destination' => $r->destination, 'frame' => $r->frame_index, 'state' => $r->state,
                'attempts' => $r->attempts, 'remote_id' => $r->remote_ids[$r->frame_index] ?? null,
                'short_link' => $r->short_link, 'last_error' => $r->last_error,
            ])->all(),
        ];
    }

    /**
     * R10: bounded rollback — удаляет ТОЛЬКО те remote id, что записаны
     * этой подсистемой (published-руны с deletion_policy=rollback_target).
     * Ручные/чужие публикации недостижимы по построению.
     */
    public function rollback(string $publicationKey): array
    {
        $publication = AnonsPublication::query()->where('publication_key', $publicationKey)->first();
        if ($publication === null) {
            throw new RuntimeException("Unknown publication key: {$publicationKey}");
        }

        $manifest = PublicationManifest::fromArray($publication->manifest);
        if (($manifest->data['deletion_policy'] ?? 'retain') !== 'rollback_target') {
            throw new RuntimeException("Publication {$publicationKey} has deletion_policy=retain — rollback refused (explicit target required).");
        }

        $deleted = [];
        foreach ($publication->runs()->where('state', AnonsDestinationRun::STATE_PUBLISHED)->get() as $run) {
            $remoteId = $run->remote_ids[$run->frame_index] ?? null;
            if ($remoteId === null) {
                continue;
            }
            $this->registry->for($run->platform)->delete($remoteId, $run->account);
            $deleted[] = ['destination' => $run->destination, 'remote_id' => $remoteId];
            $run->forceFill(['state' => AnonsDestinationRun::STATE_PENDING, 'remote_ids' => null])->save();
        }

        $publication->forceFill(['status' => AnonsPublication::STATUS_ROLLED_BACK])->save();

        return $deleted;
    }

    /** Рендер кадра: плашка ВЖАРИВАЕТСЯ в пиксели до загрузки (R4/R5). */
    private function renderFrame(array $frame, string $outPath): string
    {
        $this->compositor->renderPlaque((string) $frame['asset'], ['cta_text' => (string) $frame['cta_text']], $outPath);

        $problems = $this->inspector->inspect($outPath, $this->compositor->bounds->rectPx ?? throw new RuntimeException('No bounds'));
        if ($problems !== []) {
            throw new RuntimeException('Rendered acceptance check FAILED: '.implode(' ', $problems));
        }

        return $outPath;
    }

    /**
     * Нормализованный план кадра: подпись (URL не первым элементом),
     * короткая /ga/-ссылка с полным UTM-кортежем (R12: свой utm_content
     * на кадр), измеренный прямоугольник media-зоны.
     *
     * @return array<string, mixed>
     */
    private function framePlan(PublicationManifest $manifest, array $dest, int $frameIndex, array $frame, string $artifact): array
    {
        $link = (string) $frame['cta_url'];
        $caption = (string) ($frame['caption'] ?? '');
        // URL никогда не первый текстовый элемент (anons-правило публикаций).
        if ($caption !== '' && preg_match('/^https?:\/\//i', $caption) === 1) {
            throw new RuntimeException('Caption must not START with a URL — the URL goes after the copy (anons publication rule).');
        }
        if ($caption === '') {
            $caption = $link; // единственный допустимый случай пустой подписи
        }

        $utm = $this->buildUtm($manifest, $dest, $frameIndex);
        $mediaArea = $this->compositor->bounds->canvasW !== null
            ? $this->compositor->bounds->asMediaAreaCoordinates()
            : CtaPlaqueCompositor::RECT;

        return [
            'frame' => $frameIndex,
            'destination' => strtolower($dest['platform'].'@'.$dest['account']),
            'artifact' => $artifact,
            'plaque_rect_px' => $this->compositor->bounds->rectPx,
            'caption' => $caption,
            'alt_text' => (string) $frame['alt_text'],
            'short_link' => $link,
            'utm' => $utm,
            'media_area' => $mediaArea,
            'safe_zones' => ['top_pct' => CtaPlaqueCompositor::SAFE_TOP, 'bottom_pct' => CtaPlaqueCompositor::SAFE_BOTTOM],
        ];
    }

    /**
     * UTM-кортеж: тест-режим помечает utm_content суффиксом test (R14),
     * кадры серии получают разные utm_content (R12: -f<index>).
     *
     * @return array<string, string>
     */
    private function buildUtm(PublicationManifest $manifest, array $dest, int $frameIndex): array
    {
        $campaign = (string) $manifest->data['campaign'];
        $creative = (string) $manifest->data['creative'];
        $slotDate = preg_replace('/[^0-9]/', '', (string) $manifest->data['slot']) ?: now()->format('Ymd');

        return [
            'utm_source' => (string) $dest['account'],
            'utm_medium' => $dest['platform'] === 'telegram_story' ? 'story' : 'post',
            'utm_campaign' => $campaign,
            'utm_content' => implode('_', [
                $creative,
                $manifest->isTestMode() ? 'test' : 'prod',
                'f'.$frameIndex,
                $slotDate,
            ]),
            'utm_term' => $manifest->isTestMode() ? 'test' : '',
        ];
    }

    /** Постоянные ошибки валидации не ретраятся (fail-closed R8). */
    private function isPermanent(Throwable $e): bool
    {
        return (bool) preg_match('/missing|unreadable|validation|not configured|No platform adapter|must not START/i', $e->getMessage());
    }

    private function markAll(AnonsPublication $publication, string $reason): void
    {
        Log::warning('Anons publish: '.$reason, ['key' => $publication->publication_key]);
    }
}
