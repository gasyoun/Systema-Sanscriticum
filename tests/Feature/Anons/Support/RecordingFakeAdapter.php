<?php

declare(strict_types=1);

namespace Tests\Feature\Anons\Support;

use App\Services\Anons\Adapters\PlatformAdapter;

/**
 * Записывающий двойник платформы: управляемый успех/падение, учёт вызовов.
 * Позволяет тестировать идемпотентность, retry-автомат и метрики без сети.
 */
class RecordingFakeAdapter implements PlatformAdapter
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    /** @var list<string> */
    public array $deleted = [];

    public int $failuresRemaining = 0;

    public string $failureMessage = 'transient boom';

    public function __construct(
        public readonly string $name = 'telegram_story',
        public readonly array $caps = ['albums' => false, 'series' => true, 'visible_cta' => true, 'metrics' => ['views', 'reactions', 'forwards']],
    ) {}

    public function platform(): string
    {
        return $this->name;
    }

    public function capabilities(): array
    {
        return $this->caps;
    }

    /** @param  array<string, mixed>  $frame */
    public function publishFrame(array $frame): array
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException($this->failureMessage);
        }

        $this->published[] = $frame;
        $id = (string) (1000 + count($this->published));

        return ['id' => $id, 'raw' => ['fake' => true]];
    }

    /** @param  list<array<string, mixed>>  $frames */
    public function publishSeries(array $frames): array
    {
        return array_map(fn (array $f) => $this->publishFrame($f), $frames);
    }

    public function metrics(string $remoteId, string $account): array
    {
        // R7: views приходит, reactions/forwards в API отсутствуют —
        // unavailable, НИКОГДА ноль.
        return [
            'views' => ['state' => 'value', 'value' => 42],
            'reactions' => ['state' => 'unavailable', 'value' => null],
            'forwards' => ['state' => 'unavailable', 'value' => null],
        ];
    }

    public function delete(string $remoteId, string $account): void
    {
        $this->deleted[] = $remoteId;
    }
}
