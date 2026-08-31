<?php

declare(strict_types=1);

namespace App\Support\Deploy;

use Illuminate\Support\Carbon;

/**
 * H3803 — читает, насколько прод отстал от origin/main и когда он в последний
 * раз вообще успешно разговаривал с GitHub.
 *
 * Отдельный класс, а не метод пробы, ровно чтобы тесты подменяли его
 * подклассом: пробу нужно проверять на всех ветках, а не только там, где под
 * рукой git-чекаут с настроенным remote.
 *
 * Всё локально: ни одна из проверок НЕ ходит в сеть. Проба крутится каждые
 * 15 минут, и `git fetch` оттуда означал бы сетевой вызов на пути health-чека,
 * который сам обязан быть быстрым и не зависеть от доступности GitHub.
 */
class DeployDriftInspector
{
    public function __construct(private readonly string $repoPath) {}

    /**
     * Может ли git здесь ответить про origin/main.
     *
     * Намеренно НЕ проверяет `is_dir('.git')`: в связанном worktree `.git` —
     * файл, а не каталог, и такая проверка тихо выключала бы инспектора там,
     * где он вполне работоспособен. Единственный честный признак — git
     * отвечает или нет.
     */
    public function isUsable(): bool
    {
        return $this->commitsBehind() !== null;
    }

    /**
     * Когда `git fetch` в последний раз ОТРАБОТАЛ.
     *
     * Главная проверка из двух, и именно её не хватало 31-08-2026: протухший
     * креденшал в URL `origin` уронил fetch, из-за чего ref `origin/main`
     * замёрз вместе с HEAD — «отставание» осталось нулевым, и сравнение
     * HEAD с origin/main показало бы полное здоровье. Устаревающий FETCH_HEAD
     * — единственный локальный след того, что связи с GitHub больше нет.
     */
    public function lastFetchAt(): ?Carbon
    {
        $path = $this->repoPath.'/.git/FETCH_HEAD';
        if (! is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime === false ? null : Carbon::createFromTimestamp($mtime);
    }

    /** На сколько коммитов HEAD отстал от origin/main; null — посчитать нечем. */
    public function commitsBehind(): ?int
    {
        $out = $this->git(['rev-list', '--count', 'HEAD..origin/main']);
        if ($out === null || ! preg_match('/^\d+$/', trim($out))) {
            return null;
        }

        return (int) trim($out);
    }

    /** Время самого свежего коммита origin/main — возраст отставания. */
    public function originHeadCommittedAt(): ?Carbon
    {
        $out = $this->git(['log', '-1', '--format=%ct', 'origin/main']);
        if ($out === null || ! preg_match('/^\d+$/', trim($out))) {
            return null;
        }

        return Carbon::createFromTimestamp((int) trim($out));
    }

    /** @param list<string> $args */
    protected function git(array $args): ?string
    {
        $cmd = array_merge(['git', '-C', $this->repoPath], $args);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            return null;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0 ? $stdout : null;
    }
}
