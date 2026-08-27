<?php

declare(strict_types=1);

namespace MessageClassifier;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Тонкий PHP-лоадер правил message-intent-classifier.
 *
 * Зеркало engine_py/loader.py: та же схема, та же валидация переносимости
 * (без \w \b \d \s, без '/' и '#' внутри паттернов), тот же порядок правил
 * (priority asc, затем порядок загрузки файлов). Кросс-чек категорий против
 * taxonomy/v1. Расхождение лоадеров = провал parity на vectors/golden.json.
 */
final class Loader
{
    public const PLANES = ['topic', 'objection', 'intent', 'meta'];

    private const FORBIDDEN_TOKENS = ['/', '#', '\\w', '\\b', '\\d', '\\s', '\\u'];

    private const RULE_KEYS = ['plane', 'category', 'priority', 'patterns', 'negations', 'enabled', 'source'];

    private const TAXONOMY_KEYS = ['key', 'title', 'description'];

    /** @var array<string, list<array<string, mixed>>> plane => sorted rules */
    private array $rulesByPlane = [];

    /** @var array<string, array<string, true>> plane => set of category keys */
    private array $categoriesByPlane = [];

    public function __construct(string $packageRoot)
    {
        foreach (self::PLANES as $plane) {
            $this->rulesByPlane[$plane] = [];
        }
        $rules = $this->loadRules($packageRoot.'/rules/v1');
        $taxonomy = $this->loadTaxonomy($packageRoot.'/taxonomy/v1');
        foreach ($taxonomy as $plane => $categories) {
            $this->categoriesByPlane[$plane] = array_fill_keys(array_keys($categories), true);
        }
        foreach ($rules as $rule) {
            if (! isset($this->categoriesByPlane[$rule['plane']][$rule['category']])) {
                throw new RuntimeException(sprintf(
                    'rule %s/%s (priority %d) is absent from taxonomy/v1/%s.yaml',
                    $rule['plane'],
                    $rule['category'],
                    $rule['priority'],
                    $rule['plane']
                ));
            }
            $this->rulesByPlane[$rule['plane']][] = $rule;
        }
        foreach ($this->rulesByPlane as $plane => $planeRules) {
            usort($planeRules, static fn (array $a, array $b): int => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);
            $this->rulesByPlane[$plane] = $planeRules;
        }
    }

    /** mb_lower + ё->е + ASCII-whitespace fold. Mirror of engine_py.loader.normalize_text. */
    public static function normalizeText(?string $text): string
    {
        $lowered = mb_strtolower((string) $text, 'UTF-8');
        $folded = str_replace('ё', 'е', $lowered);
        $collapsed = preg_replace('/[\t\n\v\f\r ]+/u', ' ', $folded) ?? '';

        return trim($collapsed);
    }

    /** @return list<array<string, mixed>> */
    public function rulesFor(string $plane): array
    {
        return $this->rulesByPlane[$plane] ?? [];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function allRules(): array
    {
        return $this->rulesByPlane;
    }

    /** @return list<array<string, mixed>> */
    private function loadRules(string $dir): array
    {
        if (! is_dir($dir)) {
            throw new RuntimeException("rules dir not found: {$dir}");
        }
        $files = glob($dir.'/*.yaml') ?: [];
        sort($files);
        $collected = [];
        $order = 0;
        foreach ($files as $path) {
            try {
                $doc = Yaml::parseFile($path);
            } catch (ParseException $e) {
                throw new RuntimeException("{$path}: YAML parse error: ".$e->getMessage(), 0, $e);
            }
            if (! is_array($doc) || ! isset($doc['rules']) || ! is_array($doc['rules'])) {
                throw new RuntimeException("{$path}: expected top-level mapping with 'rules' list");
            }
            foreach ($doc['rules'] as $index => $entry) {
                $where = "{$path}#rules[{$index}]";
                if (! is_array($entry)) {
                    throw new RuntimeException("{$where}: rule must be a mapping");
                }
                foreach (array_keys($entry) as $key) {
                    if (! in_array($key, self::RULE_KEYS, true)) {
                        throw new RuntimeException("{$where}: unknown key '{$key}'");
                    }
                }
                $plane = $this->requireString($entry['plane'] ?? null, 'plane', $where);
                if (! in_array($plane, self::PLANES, true)) {
                    throw new RuntimeException("{$where}: plane '{$plane}' not in allowed planes");
                }
                $category = $this->requireString($entry['category'] ?? null, 'category', $where);
                $priority = $entry['priority'] ?? null;
                if (! is_int($priority)) {
                    throw new RuntimeException("{$where}: priority must be an integer");
                }
                $rawPatterns = $entry['patterns'] ?? null;
                if (! is_array($rawPatterns)) {
                    throw new RuntimeException("{$where}: patterns must be a list");
                }
                $enabled = $entry['enabled'] ?? true;
                if (! is_bool($enabled)) {
                    throw new RuntimeException("{$where}: enabled must be a boolean");
                }
                if ($enabled && count($rawPatterns) === 0) {
                    throw new RuntimeException("{$where}: enabled rule needs at least one pattern");
                }
                $patterns = [];
                foreach (array_values($rawPatterns) as $i => $pattern) {
                    $patterns[] = $this->compilePattern($pattern, "{$where}.patterns[{$i}]");
                }
                $rawNegations = $entry['negations'] ?? [];
                if (! is_array($rawNegations)) {
                    throw new RuntimeException("{$where}: negations must be a list");
                }
                $negations = [];
                foreach (array_values($rawNegations) as $i => $pattern) {
                    $negations[] = $this->compilePattern($pattern, "{$where}.negations[{$i}]");
                }
                $source = $entry['source'] ?? '';
                if ($source !== '' && ! is_string($source)) {
                    throw new RuntimeException("{$where}: source must be a string");
                }
                $collected[] = [
                    'plane' => $plane,
                    'category' => $category,
                    'priority' => $priority,
                    'patterns' => $patterns,
                    'negations' => $negations,
                    'enabled' => $enabled,
                    'order' => $order++,
                    'source' => is_string($source) ? $source : '',
                ];
            }
        }

        return $collected;
    }

    /** @return array<string, array{title: string, description: string}> */
    private function loadTaxonomy(string $dir): array
    {
        if (! is_dir($dir)) {
            throw new RuntimeException("taxonomy dir not found: {$dir}");
        }
        $result = [];
        $files = glob($dir.'/*.yaml') ?: [];
        sort($files);
        foreach ($files as $path) {
            $doc = Yaml::parseFile($path);
            if (! is_array($doc)) {
                throw new RuntimeException("{$path}: expected top-level mapping");
            }
            $plane = $this->requireString($doc['plane'] ?? null, 'plane', $path);
            $categories = $doc['categories'] ?? null;
            if (! in_array($plane, self::PLANES, true) || ! is_array($categories)) {
                throw new RuntimeException("{$path}: expected 'plane' and 'categories' list");
            }
            $bucket = [];
            foreach (array_values($categories) as $index => $entry) {
                $where = "{$path}#categories[{$index}]";
                if (! is_array($entry)) {
                    throw new RuntimeException("{$where}: category must be a mapping");
                }
                foreach (array_keys($entry) as $key) {
                    if (! in_array($key, self::TAXONOMY_KEYS, true)) {
                        throw new RuntimeException("{$where}: unknown key '{$key}'");
                    }
                }
                $key = $this->requireString($entry['key'] ?? null, 'key', $where);
                if (isset($bucket[$key])) {
                    throw new RuntimeException("{$where}: duplicate category key '{$key}'");
                }
                $bucket[$key] = [
                    'title' => $this->requireString($entry['title'] ?? null, 'title', $where),
                    'description' => trim(is_string($entry['description'] ?? null) ? $entry['description'] : ''),
                ];
            }
            $result[$plane] = $bucket;
        }
        foreach (self::PLANES as $plane) {
            if (! isset($result[$plane])) {
                throw new RuntimeException("taxonomy missing plane: {$plane}");
            }
        }

        return $result;
    }

    private function requireString(mixed $value, string $what, string $where): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$where}: {$what} must be a non-empty string");
        }

        return $value;
    }

    /**
     * Validate portability constraints and PCRE compilability; return raw pattern.
     */
    private function compilePattern(mixed $pattern, string $where): string
    {
        if (! is_string($pattern) || trim($pattern) === '') {
            throw new RuntimeException("{$where}: pattern must be a non-empty string");
        }
        foreach (self::FORBIDDEN_TOKENS as $bad) {
            if (str_contains($pattern, $bad)) {
                throw new RuntimeException(
                    "{$where}: forbidden token '{$bad}' in pattern (keep patterns portable across Python re and PCRE)"
                );
            }
        }
        // '/' запрещён внутри паттерна, поэтому разделитель безопасен.
        $ok = @preg_match('~^(?:'.$pattern.')$~u', '') !== false;
        if (! $ok) {
            throw new RuntimeException("{$where}: pattern does not compile under PCRE: {$pattern}");
        }

        return $pattern;
    }
}
