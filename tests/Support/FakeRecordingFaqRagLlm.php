<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Hermes\FaqRag\FaqRagAnswerLlm;

/**
 * H6185: записывающий fake LLM для тестов роли 20 `faq-rag`. Фиксирует, что
 * реально ушло за PII-границу (вопрос + разделы), и позволяет подсунуть
 * заведомо плохой ответ (missing/stale маркер) для negative-контролей.
 */
final class FakeRecordingFaqRagLlm implements FaqRagAnswerLlm
{
    public bool $called = false;

    /** @var list<array{question: string, chunks: list<string>}> */
    public array $calls = [];

    public function __construct(private readonly ?string $forcedAnswer = null)
    {
    }

    public function complete(string $maskedQuestion, array $chunks): string
    {
        $this->called = true;
        $this->calls[] = [
            'question' => $maskedQuestion,
            'chunks' => array_map(static fn ($c): string => $c->searchText(), $chunks),
        ];

        return $this->forcedAnswer
            ?? 'Ответ по базе знаний: замаскировано. [cite:'.($chunks[0]->chunkId ?? 'x').']';
    }
}
