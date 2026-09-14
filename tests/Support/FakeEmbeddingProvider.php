<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Support\Faq\EmbeddingProvider;

/**
 * H4001 — подменный dense-ноги: детерминированные вектора из карты
 * текст→вектор (точное совпадение), с дефолтом и счётчиком вызовов для
 * ассертов «dense-нога не звалась».
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var int */
    public $calls = 0;

    /**
     * @param  array<string, list<float>>  $vectors
     * @param  list<float>|null  $default
     */
    public function __construct(
        private readonly array $vectors = [],
        private readonly ?array $default = null,
    ) {}

    /** @return list<float> */
    public function embed(string $text): array
    {
        $this->calls++;

        $vector = $this->vectors[$text] ?? $this->default;

        return $vector === null ? [] : array_values($vector);
    }

    public function embedBatch(array $texts): array
    {
        $this->calls++;

        return array_map(fn (string $text): array => $this->embedWithoutCounting($text), $texts);
    }

    /** @return list<float> */
    private function embedWithoutCounting(string $text): array
    {
        $vector = $this->vectors[$text] ?? $this->default;

        return $vector === null ? [] : array_values($vector);
    }
}
