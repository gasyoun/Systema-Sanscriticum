<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * H3521 — читатель LYW lesson-паков (schema lyw-pack-v1).
 *
 * Паки — статические бандлы, сгенерированные build-time в SanskritGrammar:
 * manifest.json · personalized_text.md · views/mindmap.mmd · quizzes.json.
 * Репозиторий только читает и валидирует; любая мутация схемы/нехватка
 * файла => null (контроллер логирует и отдаёт 404). Ответы квиза наружу
 * не отдаются — их вырезает контроллер.
 */
class LessonPackRepository
{
    /**
     * Загрузить пак занятия. $level/$interest уже провалидированы словарём
     * config('lyw'); комбинация с «base» сворачивается в базовый пак.
     *
     * @return array{manifest: array<string, mixed>, text: string, mindmap: string, dir: string}|null
     */
    public function load(int $zan, string $level, string $interest): ?array
    {
        $dir = $this->packDir($zan, $level, $interest);
        if ($dir === null) {
            return null;
        }

        $manifest = $this->readJson($dir.'/manifest.json');
        if ($manifest === null) {
            return null;
        }
        if (($manifest['schema'] ?? null) !== config('lyw.schema')) {
            Log::warning('lyw.pack.schema_mismatch', [
                'zan' => $zan,
                'level' => $level,
                'interest' => $interest,
                'got' => $manifest['schema'] ?? null,
            ]);

            return null;
        }
        if ((int) ($manifest['zan'] ?? 0) !== $zan) {
            Log::warning('lyw.pack.zan_mismatch', ['manifest_zan' => $manifest['zan'] ?? null]);

            return null;
        }

        $textPath = $dir.'/personalized_text.md';
        $mindmapPath = $dir.'/views/mindmap.mmd';
        if (! is_file($textPath) || ! is_file($mindmapPath)) {
            Log::warning('lyw.pack.incomplete', ['dir' => $dir]);

            return null;
        }

        // quizzes.json обязателен по схеме; сам контроллер ключи ответов не публикует.
        $quizzes = $this->readJson($dir.'/quizzes.json');
        if ($quizzes === null || ($quizzes['schema'] ?? null) !== 'lyw-quiz-v1') {
            Log::warning('lyw.pack.quizzes_invalid', ['dir' => $dir]);

            return null;
        }

        return [
            'manifest' => $manifest,
            'text' => (string) file_get_contents($textPath),
            'mindmap' => (string) file_get_contents($mindmapPath),
            'dir' => $dir,
        ];
    }

    /**
     * @return positive-int|null размер профиля для смоук-проверок деплоя
     */
    public function exists(int $zan, string $level, string $interest): bool
    {
        $dir = $this->packDir($zan, $level, $interest);

        return $dir !== null && is_file($dir.'/manifest.json');
    }

    private function packDir(int $zan, string $level, string $interest): ?string
    {
        $levels = (array) config('lyw.levels', []);
        $interests = (array) config('lyw.interests', []);
        if (! in_array($level, $levels, true) || ! in_array($interest, $interests, true)) {
            return null;
        }

        $base = (string) config('lyw.packs_path');
        $root = $base.'/zan'.$zan;
        if ($level === 'base' || $interest === 'base') {
            return is_dir($root.'/base') ? $root.'/base' : null;
        }

        return is_dir($root.'/'.$level.'/'.$interest) ? $root.'/'.$level.'/'.$interest : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('lyw.pack.json_broken', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
