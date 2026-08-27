<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportTopicRule;
use Illuminate\Console\Command;

/**
 * H3529 (self-serve волна 1, шаг 5): синхронизация правил классификатора из
 * vendored пакета message-intent-classifier в рантайм-стор SupportTopicRule.
 *
 * Источник истины — pinned снапшот tools/message-intent-classifier/rules/v1/*.json
 * (прекомпилированные близнецы *.yaml, см. VENDOR.md; symfony/yaml в проде
 * отсутствует). Ключ идемпотентности upsert'а — plane + category + pattern_hash
 * (sha256 канонической формы patterns+negations).
 *
 * Контракт (ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md §Потоки данных):
 * - существующее правило с тем же ключом — обновляется;
 * - правило, исчезнувшее из пакета, ВЫКЛЮЧАЕТСЯ (is_enabled=false), не удаляется;
 * - легаси-строки (pattern_hash IS NULL) командой не трогаются никогда — они
 *   остаются живыми до отдельного решения о полном переходе на YAML-истину
 *   (помеченный дефолт H3529, зафиксирован в run-log волны);
 * - повторный запуск даёт пустой diff (created=updated=disabled=0).
 *
 * Деньги/доступ: команда пишет ТОЛЬКО support_topic_rules; автоответы,
 * шаблоны, платежи и webhook-код не затрагиваются (фенс H3529).
 */
class SupportRulesSync extends Command
{
    protected $signature = 'support:rules-sync {--dry-run : показать diff без записи}';

    protected $description = 'Upsert SupportTopicRule из vendored message-intent-classifier (ключ: plane+category+pattern-hash)';

    public function handle(): int
    {
        $rulesDir = base_path('tools/message-intent-classifier/rules/v1');

        if (! is_dir($rulesDir)) {
            $this->error("vendored rules dir not found: {$rulesDir}");

            return self::FAILURE;
        }

        $pin = trim((string) @file_get_contents(base_path('tools/message-intent-classifier/PINNED_SHA')));
        $this->line('support:rules-sync — source tools/message-intent-classifier/rules/v1'.($pin !== '' ? " (pinned {$pin})" : ''));

        /** @var list<array{plane:string,category:string,priority:int,patterns:list<string>,negations:list<string>,enabled:bool}> $desired */
        $desired = [];

        foreach (glob($rulesDir.'/*.json') ?: [] as $path) {
            $doc = json_decode((string) file_get_contents($path), true);

            if (! is_array($doc) || ! isset($doc['rules']) || ! is_array($doc['rules'])) {
                $this->error("{$path}: expected {version, rules[]} document");

                return self::FAILURE;
            }

            foreach ($doc['rules'] as $entry) {
                $desired[] = [
                    'plane' => (string) ($entry['plane'] ?? 'topic'),
                    'category' => (string) $entry['category'],
                    'priority' => (int) $entry['priority'],
                    'patterns' => array_values((array) ($entry['patterns'] ?? [])),
                    'negations' => array_values((array) ($entry['negations'] ?? [])),
                    'enabled' => (bool) ($entry['enabled'] ?? true),
                ];
            }
        }

        $existing = SupportTopicRule::query()
            ->whereNotNull('pattern_hash')
            ->get()
            ->keyBy(fn (SupportTopicRule $rule): string => $this->keyOf(
                $rule->plane ?? 'topic',
                (string) $rule->category,
                (string) $rule->pattern_hash,
            ));

        $desiredKeys = [];
        $creates = [];
        $updates = [];
        $unchanged = 0;

        foreach ($desired as $rule) {
            $hash = $this->patternHash($rule['patterns'], $rule['negations']);
            $key = $this->keyOf($rule['plane'], $rule['category'], $hash);
            $desiredKeys[$key] = true;

            $current = $existing->get($key);

            if ($current === null) {
                $creates[] = $rule + ['pattern_hash' => $hash];

                continue;
            }

            $dirty = $current->keywords !== $rule['patterns']
                || (array) $current->negations !== $rule['negations']
                || (int) $current->priority !== $rule['priority']
                || (bool) $current->is_enabled !== $rule['enabled'];

            if ($dirty) {
                $updates[] = [
                    'model' => $current,
                    'attributes' => [
                        'keywords' => $rule['patterns'],
                        'negations' => $rule['negations'],
                        'priority' => $rule['priority'],
                        'is_enabled' => $rule['enabled'],
                    ],
                ];
            } else {
                $unchanged++;
            }
        }

        // Исчезнувшие из пакета синхронизированные правила выключаем; уже
        // выключенные считаем unchanged — иначе второй прогон не будет пустым.
        $disables = SupportTopicRule::query()
            ->whereNotNull('pattern_hash')
            ->where('is_enabled', true)
            ->get()
            ->reject(fn (SupportTopicRule $rule): bool => isset($desiredKeys[$this->keyOf(
                $rule->plane ?? 'topic',
                (string) $rule->category,
                (string) $rule->pattern_hash,
            )]));

        $legacySkipped = SupportTopicRule::query()->whereNull('pattern_hash')->count();

        foreach ($creates as $rule) {
            $this->line(sprintf(
                '  create %s/%s %s (%d patterns)',
                $rule['plane'],
                $rule['category'],
                substr($rule['pattern_hash'], 0, 8),
                count($rule['patterns']),
            ));
        }

        foreach ($updates as $update) {
            /** @var SupportTopicRule $model */
            $model = $update['model'];
            $this->line(sprintf(
                '  update %s/%s %s',
                $model->plane ?? 'topic',
                $model->category,
                substr((string) $model->pattern_hash, 0, 8),
            ));
        }

        foreach ($disables as $vanished) {
            /** @var SupportTopicRule $vanished */
            $this->line(sprintf(
                '  disable %s/%s %s (vanished from package)',
                $vanished->plane ?? 'topic',
                $vanished->category,
                substr((string) $vanished->pattern_hash, 0, 8),
            ));
        }

        if (! $this->option('dry-run')) {
            foreach ($creates as $rule) {
                SupportTopicRule::create([
                    'plane' => $rule['plane'],
                    'category' => $rule['category'],
                    'pattern_hash' => $rule['pattern_hash'],
                    'keywords' => $rule['patterns'],
                    'negations' => $rule['negations'],
                    'priority' => $rule['priority'],
                    'is_enabled' => $rule['enabled'],
                ]);
            }

            foreach ($updates as $update) {
                $update['model']->fill($update['attributes'])->save();
            }

            SupportTopicRule::query()
                ->whereKey($disables->modelKeys())
                ->update(['is_enabled' => false]);
        }

        $dryRunSuffix = $this->option('dry-run') ? ' [DRY RUN]' : '';

        $this->info(sprintf(
            'summary: created=%d updated=%d disabled=%d unchanged=%d legacy-skipped=%d%s',
            count($creates),
            count($updates),
            $disables->count(),
            $unchanged,
            $legacySkipped,
            $dryRunSuffix,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $negations
     */
    private function patternHash(array $patterns, array $negations): string
    {
        $canonical = json_encode(
            ['negations' => $negations, 'patterns' => $patterns],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        assert(is_string($canonical));

        return hash('sha256', $canonical);
    }

    private function keyOf(string $plane, string $category, string $hash): string
    {
        return "{$plane}|{$category}|{$hash}";
    }
}
