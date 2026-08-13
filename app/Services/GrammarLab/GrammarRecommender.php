<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarMastery;
use App\Models\GrammarTopic;
use App\Models\SrsCard;
use App\Models\SrsReviewState;
use App\Models\User;
use App\Support\GrammarLabSrsDeck;

/**
 * Transparent rule-based next-topic policy (H2494).
 *
 * Order: unmet prerequisite → weakest attempted topic → overdue SRS card
 * → next difficulty band. Tie-break is topic_id. No adaptive model.
 *
 * @phpstan-type Recommendation array{topic_id: string, slug: string, title_ru: string, reason: string, rule: string}
 */
final class GrammarRecommender
{
    private const BANDS = [
        'intro' => 0,
        'core' => 1,
        'intermediate' => 1,
        'advanced' => 2,
    ];

    /**
     * @return Recommendation|null
     */
    public function next(User $user): ?array
    {
        $topics = GrammarTopic::query()
            ->where('status', 'published')
            ->orderBy('topic_id')
            ->get()
            ->keyBy('topic_id');
        if ($topics->isEmpty()) {
            return null;
        }

        $mastery = GrammarMastery::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('topic_id');

        $unmet = $this->unmetPrerequisite($topics, $mastery);
        if ($unmet !== null) {
            return $unmet;
        }

        $weak = $this->weakest($topics, $mastery);
        if ($weak !== null) {
            return $weak;
        }

        $overdue = $this->overdue($user, $topics);
        if ($overdue !== null) {
            return $overdue;
        }

        return $this->nextBand($topics, $mastery);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, GrammarTopic>  $topics
     * @param  \Illuminate\Support\Collection<string, GrammarMastery>  $mastery
     * @return Recommendation|null
     */
    private function unmetPrerequisite($topics, $mastery): ?array
    {
        foreach ($topics as $topic) {
            $prereqs = is_array($topic->prerequisites) ? $topic->prerequisites : [];
            foreach ($prereqs as $prereqId) {
                if (! is_string($prereqId) || $prereqId === '') {
                    continue;
                }
                $row = $mastery->get($prereqId);
                if ($row instanceof GrammarMastery && $row->isMastered()) {
                    continue;
                }
                $prereq = $topics->get($prereqId);
                if (! $prereq instanceof GrammarTopic) {
                    continue;
                }

                return $this->pack($prereq, 'Сначала освоите: '.$prereq->title_ru, 'prerequisite');
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, GrammarTopic>  $topics
     * @param  \Illuminate\Support\Collection<string, GrammarMastery>  $mastery
     * @return Recommendation|null
     */
    private function weakest($topics, $mastery): ?array
    {
        $best = null;
        $bestRate = 1.1;
        foreach ($mastery as $row) {
            if (! $row instanceof GrammarMastery || $row->isMastered()) {
                continue;
            }
            if ((int) $row->attempt_count < 1 || (int) $row->correct_count >= (int) $row->attempt_count) {
                continue;
            }
            $topic = $topics->get($row->topic_id);
            if (! $topic instanceof GrammarTopic) {
                continue;
            }
            $rate = (int) $row->correct_count / max(1, (int) $row->attempt_count);
            if ($rate < $bestRate || ($rate === $bestRate && ($best === null || $topic->topic_id < $best['topic_id']))) {
                $bestRate = $rate;
                $best = $this->pack(
                    $topic,
                    'Слабое место: '.$row->correct_count.'/'.$row->attempt_count.' верно',
                    'weakness'
                );
            }
        }

        return $best;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, GrammarTopic>  $topics
     * @return Recommendation|null
     */
    private function overdue(User $user, $topics): ?array
    {
        $deck = GrammarLabSrsDeck::deckFor($user);
        $due = SrsReviewState::query()
            ->where('user_id', $user->id)
            ->where('due', '<=', now())
            ->whereIn('card_id', SrsCard::query()->where('deck_id', $deck->id)->select('id'))
            ->orderBy('due')
            ->orderBy('id')
            ->first();
        if ($due === null) {
            return null;
        }
        $card = SrsCard::query()->find($due->card_id);
        $topicId = is_array($card?->fields) ? (string) ($card->fields['topic_id'] ?? '') : '';
        $topic = $topics->get($topicId);
        if (! $topic instanceof GrammarTopic) {
            return null;
        }

        return $this->pack($topic, 'Карточка просрочена — пора повторить', 'overdue');
    }

    /**
     * @param  \Illuminate\Support\Collection<string, GrammarTopic>  $topics
     * @param  \Illuminate\Support\Collection<string, GrammarMastery>  $mastery
     * @return Recommendation|null
     */
    private function nextBand($topics, $mastery): ?array
    {
        $maxMastered = -1;
        foreach ($mastery as $row) {
            if ($row instanceof GrammarMastery && $row->isMastered()) {
                $topic = $topics->get($row->topic_id);
                if ($topic instanceof GrammarTopic) {
                    $maxMastered = max($maxMastered, $this->band($topic->difficulty));
                }
            }
        }
        $target = min(2, $maxMastered + 1);

        $candidates = $topics->filter(function (GrammarTopic $topic) use ($mastery, $target) {
            $row = $mastery->get($topic->topic_id);
            if ($row instanceof GrammarMastery && $row->isMastered()) {
                return false;
            }

            return $this->band($topic->difficulty) === $target;
        });
        if ($candidates->isEmpty()) {
            $candidates = $topics->filter(function (GrammarTopic $topic) use ($mastery) {
                $row = $mastery->get($topic->topic_id);

                return ! ($row instanceof GrammarMastery && $row->isMastered());
            });
        }
        $topic = $candidates->sortBy('topic_id')->first();
        if (! $topic instanceof GrammarTopic) {
            return null;
        }

        $label = $topic->difficulty ?: 'intro';

        return $this->pack($topic, 'Следующая ступень: '.$label, 'next_band');
    }

    private function band(?string $difficulty): int
    {
        $key = strtolower((string) $difficulty);

        return self::BANDS[$key] ?? 1;
    }

    /**
     * @return Recommendation
     */
    private function pack(GrammarTopic $topic, string $reason, string $rule): array
    {
        return [
            'topic_id' => $topic->topic_id,
            'slug' => $topic->slug,
            'title_ru' => $topic->title_ru,
            'reason' => $reason,
            'rule' => $rule,
        ];
    }
}
