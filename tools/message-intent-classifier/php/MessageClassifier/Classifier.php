<?php

declare(strict_types=1);

namespace MessageClassifier;

/**
 * Классификатор с семантикой engine_py/classifier.py:
 *
 * 1. normalizeText (mb_lower + ё->е + ASCII-whitespace fold);
 * 2. внутри плоскости правила отсортированы (priority asc, порядок загрузки);
 * 3. правило срабатывает, если любой pattern матчится И ни одна negation;
 * 4. победитель несёт ['category' => ..., 'reason' => 'keyword:<pattern>'];
 * 5. никто не сработал -> null для плоскости.
 */
final class Classifier
{
    public function __construct(private readonly Loader $loader) {}

    /** @return array<string, array{category: string, reason: string}|null> */
    public function classifyAll(?string $text): array
    {
        $normalized = Loader::normalizeText($text);
        $result = [];
        foreach (Loader::PLANES as $plane) {
            $result[$plane] = $this->classifyPlane($plane, $normalized);
        }

        return $result;
    }

    /** @return array{category: string, reason: string}|null */
    public function classifyPlane(string $plane, string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        foreach ($this->loader->rulesFor($plane) as $rule) {
            if (! $rule['enabled']) {
                continue;
            }
            foreach ($rule['negations'] as $negation) {
                if (self::match((string) $negation, $text)) {
                    continue 2;
                }
            }
            foreach ($rule['patterns'] as $pattern) {
                if (self::match((string) $pattern, $text)) {
                    return [
                        'category' => (string) $rule['category'],
                        'reason' => 'keyword:'.$pattern,
                    ];
                }
            }
        }

        return null;
    }

    private static function match(string $pattern, string $subject): bool
    {
        // '/' и '#' запрещены в паттернах лоадером — разделитель безопасен.
        return preg_match('~'.$pattern.'~u', $subject) === 1;
    }
}
