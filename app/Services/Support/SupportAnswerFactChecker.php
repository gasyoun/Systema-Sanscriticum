<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAnswerSuggestion;
use App\Models\User;

/**
 * H4589: per-claim grounding check. Re-resolves the suggestion's factual
 * fields (цена, ссылка на запись, доступ, расписание) from the LMS at
 * curator-action time and diffs them against the facts snapshot captured
 * when the draft was suggested. A drift between suggestion time and curator
 * time (schedule moved, link rotated, balance changed, access revoked) is a
 * mismatch — the human curator still holds the Accept/Edit/Discard button,
 * this only tells the UI whether Accept is currently safe to show enabled.
 * No auto-send: see SupportAnswerSuggestionService, unchanged by this class.
 */
class SupportAnswerFactChecker
{
    /**
     * Verifiable top-level `facts` keys per category — only fields captured
     * by SupportAnswerFactResolver for that category gate the verdict.
     * Categories absent here (F/materials — homework/certificate, freeform
     * text facts) are out of this grounding layer's declared scope.
     *
     * @var array<string, list<string>>
     */
    private const VERIFIABLE_KEYS = [
        SupportAnswerSuggestion::CATEGORY_ZOOM => ['link', 'schedule_id'],
        SupportAnswerSuggestion::CATEGORY_SCHEDULE => ['classes'],
        SupportAnswerSuggestion::CATEGORY_RECORDING => ['url', 'lesson_id'],
        SupportAnswerSuggestion::CATEGORY_PAYMENT => ['courses'],
        SupportAnswerSuggestion::CATEGORY_ACCESS => ['courses'],
    ];

    public function __construct(private readonly SupportAnswerFactResolver $resolver) {}

    /**
     * @return array{ok: bool, checked: bool, mismatches: list<string>}
     */
    public function check(SupportAnswerSuggestion $suggestion): array
    {
        $storedFacts = (array) ($suggestion->facts ?? []);
        $keys = self::VERIFIABLE_KEYS[$suggestion->category] ?? [];
        $verifiable = array_values(array_intersect($keys, array_keys($storedFacts)));

        if ($verifiable === []) {
            // Nothing captured worth re-checking (unsupported category, or a
            // draft whose snapshot never carried a verifiable field).
            return ['ok' => true, 'checked' => false, 'mismatches' => []];
        }

        $freshFacts = $this->resolveFreshFacts($suggestion);

        $mismatches = [];
        foreach ($verifiable as $key) {
            if (! array_key_exists($key, $freshFacts)
                || $this->normalize($freshFacts[$key]) !== $this->normalize($storedFacts[$key])
            ) {
                $mismatches[] = $key;
            }
        }

        return [
            'ok' => $mismatches === [],
            'checked' => true,
            'mismatches' => $mismatches,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFreshFacts(SupportAnswerSuggestion $suggestion): array
    {
        if ($suggestion->user_id === null) {
            if ($suggestion->category !== SupportAnswerSuggestion::CATEGORY_PAYMENT) {
                return [];
            }

            $resolved = $this->resolver->resolvePublicPricing();

            return $resolved['facts'] ?? [];
        }

        $user = User::find($suggestion->user_id);
        if ($user === null) {
            return [];
        }

        $resolved = $this->resolver->resolve($suggestion->category, $user, $suggestion->detected_text);

        return $resolved['facts'] ?? [];
    }

    private function normalize(mixed $value): string
    {
        if (is_array($value)) {
            $this->ksortRecursive($value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function ksortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
        unset($item);

        if (array_is_list($value)) {
            return;
        }

        ksort($value);
    }
}
