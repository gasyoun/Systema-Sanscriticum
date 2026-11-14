<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

use App\Models\SupportAnswerSuggestion;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\FaqScoreFloor;
use App\Services\Support\Hermes\Routing\W1FirstContactRouter;
use App\Services\Support\Hermes\Routing\W1RoutingDecision;
use App\Services\Support\SupportQuestionCategorizer;
use App\Services\Support\SupportStudentPiiMasker;

/**
 * H6185 (Epic E026, Hermes W1): роль 20 `faq-rag` — RAG-сборка ответа с
 * ПРОВЕРЯЕМЫМИ цитатами.
 *
 * Конвейер (каждый ответ обязан нести цитату, разрешимую против
 * утверждённого FAQ-корпуса):
 *  1. роль OFF по умолчанию — W1 deploy gate stays OFF (features.hermes_faq_rag,
 *     default false): без флага ни черновика, ни LLM-вызова;
 *  2. PII-граница H6182: вход маскируется ДО любого LLM-вызова
 *     (SupportStudentPiiMasker::maskOrFail, fail-closed: сбой или пустой
 *     маскированный текст = вызов отменён, текст не уходит никуда);
 *  3. контур-гейт на ОБЩЕМ классификаторе H6190 (W1FirstContactRouter) —
 *     не параллельный механизм: money/injection → refused (forbidden_contour),
 *     runbook-триггеры → escalated, чужая роль (per-student факт, fq-002) →
 *     refused + delegate_hint, неуверенное намерение → refused без угадывания;
 *  4. ретривал H2448 (Bm25FaqRetriever) + score floor H3766 B5 (FaqScoreFloor,
 *     планка precision из пилота НЕ понижается): ниже порога нет
 *     уверенного источника → отказ человеку;
 *  5. mock-LLM путь (FaqRagAnswerLlm / MockFaqRagAnswerLlm — детерминированный,
 *     без платных API): ответ только из извлечённых разделов, каждый с
 *     маркером [cite:chunk_id]; без маркеров — отказ (must_cite);
 *  6. верификация цитат (W1FaqRagCitationVerifier): missing/stale/
 *     contradictory → отказ или человеку; подтверждённая цитата проходит.
 *
 * Роль ничего не отправляет: drafted-исход несёт human_send_required=true
 * (human-send per H6183), исходящих отправок нет ни на одном пути.
 */
final class W1FaqRagAnswerComposer
{
    /** Канонический slug роли 20 ростера V2 (Uprava docs/FLEET_…_ROSTER_GRILL_04-10-2026.md). */
    public const ROLE_SLUG = 'faq-rag';

    /** Маркер проверяемой цитаты в черновике: [cite:chunk_id]. */
    private const CITE_MARKER = '/\[cite:([^\]\s]+)\]/u';

    public function __construct(
        private readonly W1FirstContactRouter $router = new W1FirstContactRouter,
        private readonly SupportStudentPiiMasker $piiMasker = new SupportStudentPiiMasker,
        private readonly Bm25FaqRetriever $retriever = new Bm25FaqRetriever(new FaqCorpusParser),
        private readonly W1FaqRagCitationVerifier $verifier = new W1FaqRagCitationVerifier,
        private readonly FaqRagAnswerLlm $llm = new MockFaqRagAnswerLlm,
    ) {}

    /** Роль 20 OFF по умолчанию (W1 deploy gate): включение — решение человека. */
    public function isEnabled(): bool
    {
        return (bool) config('features.hermes_faq_rag', false);
    }

    /**
     * @param  array<string, mixed>  $context  машиночитаемый контекст события
     *                                         (channel, author, author_name, author_handle, corpus, …)
     */
    public function compose(string $message, array $context = []): W1FaqRagResult
    {
        // 1. Роль OFF: ни черновика, ни LLM — очередь человеку.
        if (! $this->isEnabled()) {
            return $this->closed(W1FaqRagResult::OUTCOME_REQUIRES_HUMAN, 'role_disabled');
        }

        // 2. PII-граница H6182 (fail-closed: чистоту нельзя доказать → вызова нет).
        [$maskedText, $piiMaskCount] = $this->maskInput($message, $context);
        if ($maskedText === '') {
            return $this->closed(W1FaqRagResult::OUTCOME_REFUSED, 'pii_boundary_closed', piiMaskCount: $piiMaskCount);
        }

        $category = SupportQuestionCategorizer::categorize($maskedText);
        $floor = FaqScoreFloor::for($category);

        // 3. Контур-гейт на общем классификаторе H6190 (без параллельного механизма).
        $decision = $this->router->decide($maskedText, $context);
        $gate = $this->contourGate($decision, $maskedText, $piiMaskCount, $floor);
        if ($gate !== null) {
            return $gate;
        }

        // 4. Ретривал H2448 + score floor H3766 B5 (планка пилота не снижается).
        $scored = $this->retriever->retrieveChunks($maskedText, (int) config('support.faq_rag.answer_top_k', 6));
        if ($scored === []) {
            return $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                'no_corpus_source',
                floor: $floor,
                piiMaskCount: $piiMaskCount,
            );
        }

        $best = (float) $scored[0]['score'];
        if ($best < $floor) {
            return $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                'below_score_floor',
                floor: $floor,
                bestScore: $best,
                piiMaskCount: $piiMaskCount,
            );
        }

        // 5. Mock-LLM путь: в вызов уходит ТОЛЬКО замаскированный текст.
        $chunks = array_map(static fn (array $s): FaqChunk => $s['chunk'], $scored);
        $draft = (string) $this->llm->complete($maskedText, $chunks);

        // 6. Верификация цитат: каждый маркер обязан разрешаться в корпусе.
        preg_match_all(self::CITE_MARKER, $draft, $markerMatches);
        $keys = array_values(array_unique(array_map('strval', $markerMatches[1] ?? [])));
        if ($keys === []) {
            return $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                'must_cite',
                floor: $floor,
                bestScore: $best,
                piiMaskCount: $piiMaskCount,
            );
        }

        $records = [];
        foreach ($keys as $key) {
            $records[] = [
                'chunk_id' => $key,
                // Подтверждение цитаты — сниппет, который видела отвечающая
                // сторона; маркер без извлечённого раздела не имеет
                // подтверждения (verifier пометит stale).
                'snippet' => $this->retrievedSnippet($scored, $key),
            ];
        }

        $report = $this->verifier->verify($records);
        if ($report['failed'] !== []) {
            return $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                'citation_unverified',
                floor: $floor,
                bestScore: $best,
                piiMaskCount: $piiMaskCount,
                failedCitations: $report['failed'],
            );
        }

        return new W1FaqRagResult(
            outcome: W1FaqRagResult::OUTCOME_DRAFTED,
            draft: $draft,
            source: 'faq_rag',
            confidence: $this->confidence($best),
            route: 'role:20',
            humanSendRequired: true,
            routeToHuman: false,
            noConfidentGuess: false,
            delegateHint: null,
            forbiddenContour: null,
            refusalReason: '',
            piiMaskCount: $piiMaskCount,
            citations: $report['verified'],
            failedCitations: [],
            llmCalled: true,
            scoreFloor: $floor,
            bestScore: $best,
        );
    }

    /**
     * Контур-гейт по решению общего классификатора H6190. Возвращает готовый
     * результат отказа/эскалации, либо null — вопрос проходит к ретривалу
     * (счастливый путь роли 20).
     */
    private function contourGate(W1RoutingDecision $decision, string $maskedText, int $piiMaskCount, float $floor): ?W1FaqRagResult
    {
        if ($decision->route === 'role:20' && $decision->category === W1RoutingDecision::CATEGORY_HAPPY) {
            return null;
        }

        // Чужая роль (fq-002: per-student факт → роль 23) — роль 20 не
        // угадывает и не отвечает: отказ человеку + подсказка делегирования.
        if (str_starts_with($decision->route, 'role:')) {
            return $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                'delegate_'.$decision->reason,
                floor: $floor,
                piiMaskCount: $piiMaskCount,
                noConfidentGuess: true,
                delegateHint: $decision->route,
            );
        }

        return match ($decision->category) {
            W1RoutingDecision::CATEGORY_FORBIDDEN => $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                $decision->reason,
                floor: $floor,
                piiMaskCount: $piiMaskCount,
                forbiddenContour: $this->forbiddenContourOf($decision, $maskedText),
            ),
            W1RoutingDecision::CATEGORY_ESCALATION => $this->closed(
                W1FaqRagResult::OUTCOME_ESCALATED,
                $decision->reason,
                floor: $floor,
                piiMaskCount: $piiMaskCount,
            ),
            default => $this->closed(
                W1FaqRagResult::OUTCOME_REFUSED,
                $decision->reason,
                floor: $floor,
                piiMaskCount: $piiMaskCount,
                noConfidentGuess: true,
                delegateHint: 'human',
            ),
        };
    }

    /** Запрещённый контур: money (fq-003), injection или общий запрет. */
    private function forbiddenContourOf(W1RoutingDecision $decision, string $maskedText): string
    {
        if ($decision->reason === 'injection_suspected') {
            return 'injection';
        }

        return SupportQuestionCategorizer::categorize($maskedText) === SupportAnswerSuggestion::CATEGORY_PAYMENT
            ? 'money'
            : 'forbidden_contour';
    }

    /**
     * PII-маскирование входа по контракту H6182 (fail-closed).
     *
     * @param  array<string, mixed>  $context
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     */
    private function maskInput(string $message, array $context): array
    {
        try {
            $this->piiMasker->newConversation();

            $names = [];
            $nicks = [];
            if (! empty($context['author_name'])) {
                $names[] = (string) $context['author_name'];
            }
            if (! empty($context['author_handle'])) {
                $nicks[] = (string) $context['author_handle'];
            }
            if ($names !== [] || $nicks !== []) {
                $this->piiMasker->seedConversation($names, $nicks);
            }

            [$masked, $count] = $this->piiMasker->maskOrFail($message);

            return [(string) $masked, (int) $count];
        } catch (\Throwable) {
            // Fail-closed (H6182): чистоту нельзя доказать → вызова нет.
            return ['', 0];
        }
    }

    /**
     * @param  list<array{chunk: FaqChunk, score: float}>  $scored
     */
    private function retrievedSnippet(array $scored, string $key): string
    {
        foreach ($scored as $entry) {
            if ($entry['chunk']->chunkId === $key) {
                return $entry['chunk']->snippet();
            }
        }

        return '';
    }

    /**
     * Результат без черновика: отказ/эскалация/требуется человек. LLM не
     * вызывался ни на одном таком пути (no_llm_draft).
     *
     * @param  list<array{chunk_id: string, problem: string}>  $failedCitations
     */
    private function closed(
        string $outcome,
        string $reason,
        float $floor = 0.0,
        ?float $bestScore = null,
        int $piiMaskCount = 0,
        bool $noConfidentGuess = false,
        ?string $delegateHint = null,
        ?string $forbiddenContour = null,
        array $failedCitations = [],
    ): W1FaqRagResult {
        return new W1FaqRagResult(
            outcome: $outcome,
            draft: null,
            source: null,
            confidence: null,
            route: 'human',
            humanSendRequired: false,
            routeToHuman: true,
            noConfidentGuess: $noConfidentGuess,
            delegateHint: $delegateHint,
            forbiddenContour: $forbiddenContour,
            refusalReason: $reason,
            piiMaskCount: $piiMaskCount,
            citations: [],
            failedCitations: $failedCitations,
            llmCalled: false,
            scoreFloor: $floor,
            bestScore: $bestScore,
        );
    }

    /**
     * Мягкая карта уверенности BM25 → 0.55–0.92 (H5065, тот же домен, что в
     * FaqRagDraftBuilder; куратор всё равно проверяет черновик).
     */
    private function confidence(float $best): float
    {
        return round(min(0.92, max(0.55, 0.5 + min($best, 8.0) / 20.0)), 3);
    }
}
