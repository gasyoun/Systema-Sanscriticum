<?php

declare(strict_types=1);

namespace App\Support\Lyw;

/**
 * H7069 — fail-loud проверка дерева LYW-паков.
 *
 * Паки закоммичены в resources/data/lesson_packs (default config('lyw.packs_path')),
 * поэтому пересборка/восстановление storage больше не могут оставить функцию
 * включённой без контента. Эта проверка — вторая половина: если конфиг говорит
 * `lyw.enabled=true`, а у включённой группы нет manifest.json (или он битый),
 * деплой и `guards:verify` обязаны упасть, а не отдавать студентам 404.
 *
 * Класс не зависит от Laravel: `problems()` можно гонять на scratch-дереве
 * без artisan/vendor. `problemsFromConfig()` — тонкая обёртка для команд.
 */
final class LessonPackTreeGuard
{
    /**
     * @param  array<string, bool|int|string>  $enabledGroups
     * @param  list<string>  $levels
     * @param  list<string>  $interests
     * @return list<string> человекочитаемые проблемы; пустой список = всё на месте
     */
    public static function problems(
        string $packsPath,
        int $zan,
        bool $enabled,
        array $enabledGroups,
        array $levels,
        array $interests,
        string $schema = 'lyw-pack-v1',
    ): array {
        if (! $enabled) {
            return [];
        }

        $problems = [];
        $expected = self::expectedDirs($zan, $enabledGroups, $levels, $interests, $problems);
        $root = rtrim($packsPath, '/\\');

        foreach ($expected as $group => $relativeDir) {
            $dir = $root.'/'.$relativeDir;
            $manifestPath = $dir.'/manifest.json';

            if (! is_file($manifestPath)) {
                $problems[] = "group {$group}: manifest.json отсутствует: {$manifestPath}";

                continue;
            }

            $manifest = self::readJson($manifestPath, $group, $problems);
            if ($manifest === null) {
                continue;
            }

            if (($manifest['schema'] ?? null) !== $schema) {
                $problems[] = "group {$group}: schema=".var_export($manifest['schema'] ?? null, true).", ожидался {$schema}";
            }
            if ((int) ($manifest['zan'] ?? 0) !== $zan) {
                $problems[] = "group {$group}: zan=".var_export($manifest['zan'] ?? null, true).", ожидался {$zan}";
            }

            foreach (['personalized_text.md', 'views/mindmap.mmd', 'quizzes.json'] as $required) {
                if (! is_file($dir.'/'.$required)) {
                    $problems[] = "group {$group}: нет обязательного файла {$dir}/{$required}";
                }
            }

            $quizzesPath = $dir.'/quizzes.json';
            if (is_file($quizzesPath)) {
                $quizzes = self::readJson($quizzesPath, $group, $problems);
                if ($quizzes !== null && ($quizzes['schema'] ?? null) !== 'lyw-quiz-v1') {
                    $problems[] = "group {$group}: quizzes schema=".var_export($quizzes['schema'] ?? null, true).", ожидался lyw-quiz-v1";
                }
            }
        }

        return $problems;
    }

    /**
     * Проверка текущего конфига приложения (для artisan guards:verify и deploy).
     *
     * @return list<string>
     */
    public static function problemsFromConfig(): array
    {
        return self::problems(
            (string) config('lyw.packs_path'),
            (int) config('lyw.default_zan', 1),
            (bool) config('lyw.enabled', false),
            (array) config('lyw.enabled_groups', []),
            (array) config('lyw.levels', []),
            (array) config('lyw.interests', []),
            (string) config('lyw.schema', 'lyw-pack-v1'),
        );
    }

    /**
     * Каталог пака относительно packs_path для каждого включённого ключа.
     *
     * Правило то же, что у LessonPackRepository::packDir(): base сворачивает
     * пару в общий zan<N>/base, остальные — zan<N>/<level>/<interest>.
     *
     * @param  array<string, bool|int|string>  $enabledGroups
     * @param  list<string>  $levels
     * @param  list<string>  $interests
     * @param  list<string>  $problems
     * @return array<string, string>
     */
    private static function expectedDirs(int $zan, array $enabledGroups, array $levels, array $interests, array &$problems): array
    {
        $expected = [];

        foreach ($enabledGroups as $group => $flag) {
            if (! (bool) $flag) {
                continue;
            }

            $group = (string) $group;
            if ($group === 'base') {
                $expected[$group] = 'zan'.$zan.'/base';

                continue;
            }

            $parts = explode('-', $group, 2);
            if (count($parts) !== 2
                || ! in_array($parts[0], $levels, true)
                || ! in_array($parts[1], $interests, true)) {
                $problems[] = "enabled group '{$group}' не раскладывается в <level>-<interest> по config('lyw.levels'/'lyw.interests')";

                continue;
            }

            $expected[$group] = 'zan'.$zan.'/'.$parts[0].'/'.$parts[1];
        }

        return $expected;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, mixed>|null
     */
    private static function readJson(string $path, string $group, array &$problems): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $problems[] = "group {$group}: битый JSON {$path}: ".$e->getMessage();

            return null;
        }

        if (! is_array($decoded)) {
            $problems[] = "group {$group}: JSON {$path} не объект";

            return null;
        }

        return $decoded;
    }
}
