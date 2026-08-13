<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarTopic;

/**
 * Domain gates for a Grammar Lab exercise payload (H2494).
 *
 * Deterministic items must have a mechanically unique answer.
 * Interpretive items are never auto-publishable from this class.
 */
final class ExerciseValidator
{
    /**
     * @param  array<string, mixed>  $exercise
     * @return list<string>
     */
    public function errors(array $exercise, bool $requirePublishedTopic = true): array
    {
        $errors = [];
        $id = (string) ($exercise['id'] ?? '');
        $topicId = (string) ($exercise['topic_id'] ?? '');
        $risk = (string) ($exercise['risk_class'] ?? '');
        $answer = (string) ($exercise['answer'] ?? '');
        $normalized = (string) ($exercise['answer_normalized'] ?? '');
        $prompt = (string) ($exercise['prompt_ru'] ?? '');

        if ($id === '' || ! str_starts_with($id, 'ex:grammar-lab:')) {
            $errors[] = 'id';
        }
        if ($topicId === '' || ! str_starts_with($topicId, 'subject:grammar-lab:')) {
            $errors[] = 'topic_id';
        }
        if (! in_array($risk, ['deterministic', 'interpretive'], true)) {
            $errors[] = 'risk_class';
        }
        if (mb_strlen($prompt) < 8) {
            $errors[] = 'prompt_ru';
        }
        if (trim($answer) === '') {
            $errors[] = 'answer';
        }
        if (trim($normalized) === '') {
            $errors[] = 'answer_normalized';
        }
        if ($answer !== '' && $normalized !== '' && $this->normalize($answer) !== $this->normalize($normalized)) {
            $errors[] = 'answer_equivalence';
        }

        $choices = $this->stringList($exercise['choices'] ?? null);
        if ($choices !== []) {
            if (count($choices) < 2) {
                $errors[] = 'choices_min';
            }
            if ($answer !== '' && ! $this->listContains($choices, $answer)) {
                $errors[] = 'answer_not_in_choices';
            }
            if (count($choices) !== count(array_unique(array_map([$this, 'normalize'], $choices)))) {
                $errors[] = 'choices_not_unique';
            }
        }

        $distractors = $this->stringList($exercise['distractors'] ?? null);
        if ($distractors !== []) {
            $normDist = array_map([$this, 'normalize'], $distractors);
            if (count($normDist) !== count(array_unique($normDist))) {
                $errors[] = 'distractors_not_unique';
            }
            if ($answer !== '' && $this->listContains($distractors, $answer)) {
                $errors[] = 'distractor_equals_answer';
            }
        }

        $sources = $exercise['source_ids'] ?? [];
        if (! is_array($sources) || $sources === []) {
            $errors[] = 'source_ids';
        }

        if ($requirePublishedTopic && $topicId !== '') {
            $topic = GrammarTopic::query()
                ->where('topic_id', $topicId)
                ->where('status', 'published')
                ->first();
            if ($topic === null) {
                $errors[] = 'topic_not_published';
            } else {
                $linked = $topic->exercise_ids ?? [];
                if (is_array($linked) && $linked !== [] && $id !== '' && ! in_array($id, $linked, true)) {
                    $errors[] = 'topic_version_incompatible';
                }
            }
        }

        if ($risk === 'interpretive') {
            $approval = trim((string) ($exercise['approval_record'] ?? ''));
            if ($approval === '') {
                $errors[] = 'interpretive_needs_approval';
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $exercise
     */
    public function answersMatch(array $exercise, string $given): bool
    {
        $expected = (string) ($exercise['answer_normalized'] ?? $exercise['answer'] ?? '');

        return $this->normalize($given) === $this->normalize($expected);
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = str_replace('ё', 'е', $value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $items
     */
    private function listContains(array $items, string $needle): bool
    {
        $norm = $this->normalize($needle);
        foreach ($items as $item) {
            if ($this->normalize($item) === $norm) {
                return true;
            }
        }

        return false;
    }
}
