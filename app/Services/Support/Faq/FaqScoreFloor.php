<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

/**
 * Единая точка чтения BM25 score-floor (H3766 B5, калибровка
 * `php artisan faq:score-floor`).
 *
 * Домен порогов — BM25-скор top-1. Категорийный порог всегда строже общего,
 * поэтому берётся максимум; категории без своего порога (и null-категория —
 * вопрос, который SupportQuestionCategorizer не разобрал) получают общий
 * `support.faq_rag.shadow_min_score`.
 *
 * Значения калибровки живут ТОЛЬКО в config/support.php и никогда не
 * понижаются ради PASS (миSSION H6185: «проверить действующую планку
 * precision из пилота, не понижать»). Потребители: TG-полоса
 * (SupportDmAutoReply), web-полоса (SupportWebchatAutoReply) и роль 20
 * faq-rag (H6185 W1FaqRagAnswerComposer) — один lookup, чтобы пороги не
 * разъезжались между полосами.
 */
final class FaqScoreFloor
{
    /**
     * Порог скора для категории вопроса: null → общий порог.
     */
    public static function for(?string $category): float
    {
        $global = (float) config('support.faq_rag.shadow_min_score', 8.0);

        if ($category === null) {
            return $global;
        }

        $perCategory = config('support.faq_rag.shadow_min_score_by_category', []);

        if (! is_array($perCategory) || ! isset($perCategory[$category])) {
            return $global;
        }

        return max($global, (float) $perCategory[$category]);
    }
}
