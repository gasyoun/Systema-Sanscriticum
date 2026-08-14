<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\User;

/**
 * H2446 — curated Hindi study-chat practice (flag default OFF).
 *
 * Reads a committed JSON store. Never calls Telegram / Madeline. Access
 * is the same as the H2441 playlist (owns ≥1 Hindi lesson). Items with a
 * lesson_id are shown only when that lesson is unlocked. A privacy gate
 * drops any row that still carries chat PII.
 */
final class HindiTgCuratedPractice
{
    public const MAX_ITEMS = 50;

    public const STORE = 'database/data/hindi_tg_curated/items.json';

    /** @var list<string> */
    public const ALLOWED_TYPES = ['translate', 'cloze', 'vocab_pick', 'qa'];

    /** @var list<string> */
    public const FORBIDDEN_KEYS = [
        'telegram_user_id',
        'telegram_id',
        'from_id',
        'peer_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'phone_number',
        'raw_text',
        'raw',
        'message_id',
        'chat_id',
        'user_id',
        'from',
        'sender',
    ];

    public function __construct(
        private readonly HindiProgrammePlaylist $playlist,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('features.hindi_tg_curated_practice', false);
    }

    public function storePath(): string
    {
        return base_path(self::STORE);
    }

    public function userCanAccess(User $user): bool
    {
        return $this->playlist->itemsFor($user)->isNotEmpty();
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     programme_unit: string,
     *     lesson_id: int|null,
     *     course_slug: string,
     *     curator: string,
     *     source: string,
     *     source_note: string
     * }>
     */
    public function itemsFor(User $user, ?string $path = null): array
    {
        $out = [];
        foreach ($this->scan($path)['accepted'] as $item) {
            $lessonId = $item['lesson_id'];
            if ($lessonId !== null) {
                $lesson = Lesson::query()->find($lessonId);
                if (! $lesson instanceof Lesson || ! $this->playlist->canAccessLesson($user, $lesson)) {
                    continue;
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     programme_unit: string,
     *     lesson_id: int|null,
     *     course_slug: string,
     *     curator: string,
     *     source: string,
     *     source_note: string
     * }|null
     */
    public function findItem(User $user, string $itemId, ?string $path = null): ?array
    {
        foreach ($this->itemsFor($user, $path) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     flag: bool,
     *     path: string,
     *     raw_count: int,
     *     accepted_count: int,
     *     rejected: list<array{id: string, reason: string}>,
     *     items: list<array{id: string, type: string, prompt: string, programme_unit: string, answer: string}>
     * }
     */
    public function probe(?string $path = null): array
    {
        $scan = $this->scan($path);

        return [
            'flag' => $this->enabled(),
            'path' => $path ?? $this->storePath(),
            'raw_count' => $scan['raw_count'],
            'accepted_count' => count($scan['accepted']),
            'rejected' => $scan['rejected'],
            'items' => array_map(static fn (array $item): array => [
                'id' => $item['id'],
                'type' => $item['type'],
                'prompt' => $item['prompt'],
                'programme_unit' => $item['programme_unit'],
                'answer' => '***',
            ], $scan['accepted']),
        ];
    }

    /**
     * @return array{
     *     raw_count: int,
     *     accepted: list<array<string, mixed>>,
     *     rejected: list<array{id: string, reason: string}>
     * }
     */
    public function scan(?string $path = null): array
    {
        $file = $path ?? $this->storePath();
        if (! is_file($file)) {
            return ['raw_count' => 0, 'accepted' => [], 'rejected' => []];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        $rawItems = is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])
            ? $decoded['items']
            : [];

        $accepted = [];
        $rejected = [];
        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                $rejected[] = ['id' => '', 'reason' => 'not_object'];

                continue;
            }
            $id = trim((string) ($raw['id'] ?? ''));
            $reason = $this->rejectReason($raw);
            if ($reason !== null) {
                $rejected[] = ['id' => $id, 'reason' => $reason];

                continue;
            }
            if (count($accepted) >= self::MAX_ITEMS) {
                $rejected[] = ['id' => $id, 'reason' => 'over_cap'];

                continue;
            }
            $accepted[] = $this->normalize($raw);
        }

        return [
            'raw_count' => count($rawItems),
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function rejectReason(array $raw): ?string
    {
        foreach (array_keys($raw) as $key) {
            $name = strtolower((string) $key);
            if (in_array($name, self::FORBIDDEN_KEYS, true)) {
                return 'forbidden_key:'.$name;
            }
            if (str_contains($name, 'telegram')) {
                return 'forbidden_key:'.$name;
            }
        }

        $id = trim((string) ($raw['id'] ?? ''));
        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        $prompt = trim((string) ($raw['prompt'] ?? ''));
        $answer = trim((string) ($raw['answer'] ?? ''));
        if ($id === '' || $prompt === '' || $answer === '') {
            return 'missing_fields';
        }
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return 'bad_type';
        }

        foreach (['prompt', 'answer', 'lemma', 'source_note', 'curator', 'programme_unit'] as $field) {
            $value = trim((string) ($raw[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $pii = $this->valueLooksLikePii($value);
            if ($pii !== null) {
                return $pii;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     programme_unit: string,
     *     lesson_id: int|null,
     *     course_slug: string,
     *     curator: string,
     *     source: string,
     *     source_note: string
     * }
     */
    private function normalize(array $raw): array
    {
        $type = strtolower(trim((string) ($raw['type'] ?? '')));
        if ($type === 'qa') {
            $type = 'translate';
        }

        $choices = $raw['choices'] ?? null;
        $normalizedChoices = null;
        if (is_array($choices)) {
            $normalizedChoices = [];
            foreach ($choices as $choice) {
                $text = trim((string) $choice);
                if ($text !== '') {
                    $normalizedChoices[] = $text;
                }
            }
            if ($normalizedChoices === []) {
                $normalizedChoices = null;
            }
        }

        $lessonId = $raw['lesson_id'] ?? null;
        $lessonId = is_numeric($lessonId) ? (int) $lessonId : null;
        if ($lessonId !== null && $lessonId <= 0) {
            $lessonId = null;
        }

        return [
            'id' => trim((string) $raw['id']),
            'type' => $type,
            'prompt' => trim((string) $raw['prompt']),
            'answer' => trim((string) $raw['answer']),
            'lemma' => trim((string) ($raw['lemma'] ?? '')),
            'choices' => $normalizedChoices,
            'programme_unit' => trim((string) ($raw['programme_unit'] ?? '')),
            'lesson_id' => $lessonId,
            'course_slug' => trim((string) ($raw['course_slug'] ?? '')),
            'curator' => trim((string) ($raw['curator'] ?? 'teacher')),
            'source' => trim((string) ($raw['source'] ?? 'teacher_curated')),
            'source_note' => trim((string) ($raw['source_note'] ?? '')),
        ];
    }

    private function valueLooksLikePii(string $value): ?string
    {
        if (preg_match('/(?:^|[\s])@[A-Za-z][A-Za-z0-9_]{4,}\b/u', $value) === 1) {
            return 'username_mention';
        }
        if (preg_match('~(?:t\.me|telegram\.me)/~i', $value) === 1) {
            return 'telegram_url';
        }
        if (preg_match('/(?:\+|tel:)\d[\d\s\-]{8,}\d/', $value) === 1) {
            return 'phone';
        }

        return null;
    }
}
