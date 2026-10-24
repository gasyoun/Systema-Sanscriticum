<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use App\Services\LessonPackRepository;

/**
 * H7069 — паки уроков LYW: контент, который живёт не в git и потому исчезает
 * молча.
 *
 * Зачем отдельная стража. `storage/` не в репозитории: паки зан1 залиты на
 * .92 руками 18-10-2026, и первое же восстановление storage, пересборка
 * сервера или второй бокс оставляют конфиг с `LYW_ENABLED=true` без
 * содержимого. Тогда фича не падает — она отдаёт 404 на каждый урок, то есть
 * ровно тот класс «тихой деградации», ради которого существует guards:verify
 * (docs/server-resource-guards.md, H1914).
 *
 * Что утверждает проверка. Для КАЖДОЙ включённой группы (`lyw.enabled_groups`
 * = true) при поднятом мастер-выключателе: пак существует, читается тем же
 * кодом, что отдаёт его студенту (`LessonPackRepository::load()` — манифест,
 * схема, zan, quizzes, текст, mindmap), и группа вообще существует в словарях
 * level×interest. Отсутствие пака у ВЫКЛЮЧЕННОЙ группы — не тревога: это
 * отклонённый голосованием пак, его 404 — решение, а не авария.
 *
 * Путь и групповой ключ не изобретаются здесь: путь даёт
 * `LessonPackRepository::expectedDir()`, ключ — `lyw.enabled_groups`, ровно тот
 * же, что читает `LywProfiles`. Второго словаря быть не должно.
 */
final class LywPacksGuard
{
    public const GUARD = 'lyw-packs';

    private const SYNC_HINT = 'вернуть паки: bash scripts/server_guards/sbin/lesson-packs-sync.sh';

    public function __construct(private readonly LessonPackRepository $packs) {}

    /**
     * @return list<GuardFinding>
     */
    public function findings(): array
    {
        if (! (bool) config('lyw.enabled', false)) {
            return [];
        }

        $zan = (int) config('lyw.default_zan', 1);
        $out = [];

        foreach ((array) config('lyw.enabled_groups', []) as $key => $enabled) {
            if (! $enabled) {
                continue;
            }

            $key = (string) $key;
            $pair = $this->pair($key);

            if ($pair === null) {
                $out[] = GuardFinding::critical(self::GUARD, sprintf(
                    'в lyw.enabled_groups включена неизвестная группа «%s»: в словарях level×interest такой пары нет — опечатка в конфиге, паки она не включает',
                    $key,
                ));

                continue;
            }

            [$level, $interest] = $pair;
            $dir = $this->packs->expectedDir($zan, $level, $interest);

            if ($dir === null || ! is_file($dir.'/manifest.json')) {
                $out[] = GuardFinding::critical(self::GUARD, sprintf(
                    'группа «%s» включена, а пака нет (%s/manifest.json): LYW отдаст 404 на каждом уроке — %s',
                    $key,
                    (string) $dir,
                    self::SYNC_HINT,
                ));

                continue;
            }

            if ($this->packs->load($zan, $level, $interest) === null) {
                $out[] = GuardFinding::critical(self::GUARD, sprintf(
                    'пак группы «%s» лежит, но не читается (манифест/схема/zan/quizzes/текст/mindmap): LYW отдаст 404 — %s',
                    $key,
                    $dir,
                ));
            }
        }

        return $out;
    }

    /**
     * Ключ группы → пара level×interest. Свёртка та же, что в конфиге:
     * `base` — общий пак, всё остальное — `<level>-<interest>`.
     *
     * @return array{0: string, 1: string}|null
     */
    private function pair(string $key): ?array
    {
        if ($key === 'base') {
            return ['base', 'base'];
        }

        $parts = explode('-', $key, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $levels = (array) config('lyw.levels', []);
        $interests = (array) config('lyw.interests', []);

        if (! in_array($parts[0], $levels, true) || ! in_array($parts[1], $interests, true)) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
