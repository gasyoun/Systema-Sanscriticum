<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * H4001 (Wave 3) — dense-нога: один текст → вектор float32.
 *
 * Контракт архитектуры leverage-плана: пустой массив = «dense-нога
 * недоступна», НИКОГДА не «результатов нет». Ретраев в request-path нет —
 * медленный GPU-узел деградирует, а не подвешивает вызвавшую сторону
 * (рулинг D2); ретраи живут внутри Horizon-джобы индексации.
 */
interface EmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text): array;

    /**
     * Порядок результата соответствует порядку входа.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;
}
