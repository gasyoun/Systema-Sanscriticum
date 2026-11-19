<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

use App\Services\Support\Faq\FaqChunk;

/**
 * H6185 (Epic E026, Hermes W1): граница LLM-вызова роли 20 `faq-rag`.
 *
 * Контракт H6182: сюда доходит ТОЛЬКО замаскированный вопрос и извлечённые
 * разделы корпуса — сырой текст студента не пересекает границу никогда.
 * В production здесь будет провайдер; в W1 — детерминированный mock
 * (MockFaqRagAnswerLlm): никаких платных API, никаких сетевых вызовов.
 */
interface FaqRagAnswerLlm
{
    /**
     * @param  string  $maskedQuestion  вопрос ПОСЛЕ PII-маскирования (H6182)
     * @param  list<FaqChunk>  $chunks  извлечённые разделы утверждённого FAQ-корпуса
     */
    public function complete(string $maskedQuestion, array $chunks): string;
}
