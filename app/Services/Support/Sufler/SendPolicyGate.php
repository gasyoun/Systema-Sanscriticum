<?php

declare(strict_types=1);

namespace App\Services\Support\Sufler;

use App\Services\Support\SupportDmAutoReply;
use Tests\Feature\Support\SuflerSendPolicyTest;

/**
 * H5776: суфлёр, политика v1 — enforcement-движок гейтов из
 * policy/sufler.policy.yml. Каждый пункт verify_gates_before_external_action
 * живёт здесь как проверяемое правило, а не как комментарий: send-path
 * {@see SupportDmAutoReply::sendAuto()} проходит через
 * violations() перед КАЖДОЙ автоотправкой, и нарушение любого гейта = отправки
 * нет, студенту молчание, куратору подсказка, трейс событием (не молча).
 *
 * Гейты:
 *  - money_amount_matches_system: сумма в тексте разрешена ТОЛЬКО когда черновик
 *    собран системным резолвером фактов (kind 'facts') — расчёт идёт по кабинету,
 *    а расхождение заявленной/расчётной суммы гасится выше по конвейеру
 *    (dm_balance_dispute, рулинг A1). Статичный шаблон, FAQ-цитата или
 *    формулировка LLM с цифрой и «₽/руб» не могут «совпасть с системой»
 *    по определению — их блокируем и уводим к человеку.
 *  - no_personal_data_in_corpus: исходящий черновик не несёт ПДн (e-mail,
 *    телефон РФ, номер карты). Узкие паттерны, чтобы не душить легитимные
 *    факты LMS (zoom-ссылки/IDs паттерны не матчатся).
 *  - citation_present_for_every_fact: факт-несущие виды отправки (facts /
 *    faq_rag / llm_draft) обязаны нести цитату в мете: fact_type / chunk_id /
 *    faq_chunk_ids. Ответ студента без источника не отправляется.
 *
 * Класс чистый (никакого I/O) — вся решающая логика покрыта юнит-тестами
 * {@see SuflerSendPolicyTest}.
 */
final class SendPolicyGate
{
    /** Виды отправки, которые несут утверждения о фактах и обязаны нести цитату. */
    public const FACT_KINDS = ['facts', 'faq_rag', 'llm_draft'];

    /** Единственный вид отправки, которому разрешена сумма: системный резолвер. */
    public const SYSTEM_AMOUNT_KIND = 'facts';

    public const GATE_MONEY = 'money_amount_matches_system';

    public const GATE_NO_PII = 'no_personal_data_in_corpus';

    public const GATE_CITATION = 'citation_present_for_every_fact';

    /** Сумма денег в тексте: «3 200 ₽», «₽1500», «1 234,50 руб», «3200 руб.» */
    private const MONEY_AMOUNT_PATTERN
        = '/\b\d[\d\s\x{00A0}.,]{0,11}(?:₽|руб\.?)|(?:₽|руб\.?)\s*\d[\d\s\x{00A0}.,]{0,11}/iu';

    /**
     * Узкие ПДн-паттерны: e-mail, телефон РФ (+7/8 с форматированием),
     * номер карты (16 цифр группами). Сознательно НЕ матчатся:
     * zoom-ссылки/IDs, даты «03.10.2026», номера уроков.
     */
    private const PII_PATTERNS = [
        'email' => '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
        'phone_ru' => '/(?:\+7|8)[\s(-]?\d{3}[\s)-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}\b/u',
        'card_number' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/u',
    ];

    /**
     * @param  array<string, mixed>  $meta  metaExtra будущей отправки (citation lives here)
     * @return list<array{gate: string, detail: string}>
     */
    public function violations(string $draft, string $kind, array $meta): array
    {
        $violations = [];

        if (preg_match(self::MONEY_AMOUNT_PATTERN, $draft) === 1 && $kind !== self::SYSTEM_AMOUNT_KIND) {
            $violations[] = [
                'gate' => self::GATE_MONEY,
                'detail' => sprintf('money amount in draft of kind "%s" — only system-resolved facts may carry amounts', $kind),
            ];
        }

        foreach (self::PII_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $draft) === 1) {
                $violations[] = [
                    'gate' => self::GATE_NO_PII,
                    'detail' => sprintf('personal data (%s) in outbound draft', $name),
                ];
            }
        }

        if (in_array($kind, self::FACT_KINDS, true) && ! $this->hasCitation($kind, $meta)) {
            $violations[] = [
                'gate' => self::GATE_CITATION,
                'detail' => sprintf('kind "%s" carries facts but meta has no citation', $kind),
            ];
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function hasCitation(string $kind, array $meta): bool
    {
        return match ($kind) {
            'facts' => trim((string) ($meta['fact_type'] ?? '')) !== '',
            'faq_rag' => trim((string) ($meta['chunk_id'] ?? '')) !== '',
            'llm_draft' => isset($meta['faq_chunk_ids']) && is_array($meta['faq_chunk_ids']) && $meta['faq_chunk_ids'] !== [],
            default => false,
        };
    }
}
