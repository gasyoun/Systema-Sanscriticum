<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Lyw\LessonPackTreeGuard;
use Illuminate\Console\Command;

/**
 * H7069 — громкая проверка контента Learn Your Way.
 *
 * `lyw.enabled=true` при пустом packs_path — это не «фича без контента», а
 * сломанный деплой: пять одобренных групп получат 404 на каждом уроке. Команда
 * возвращает non-zero, если у любой включённой группы нет manifest.json или
 * пак неполный/битый. Вызывается из deploy.sh и из guards:verify.
 */
class VerifyLywPacks extends Command
{
    protected $signature = 'lyw:verify-packs
        {--json : Выдать результат машиночитаемо}
        {--packs-path= : Проверить другой корень паков (dry-run на scratch-дереве)}';

    protected $description = 'Проверить, что у всех включённых групп LYW есть manifest.json и полный пак';

    public function handle(): int
    {
        $enabled = (bool) config('lyw.enabled', false);
        $packsPath = (string) ($this->option('packs-path') ?: config('lyw.packs_path'));

        $problems = LessonPackTreeGuard::problems(
            $packsPath,
            (int) config('lyw.default_zan', 1),
            $enabled,
            (array) config('lyw.enabled_groups', []),
            (array) config('lyw.levels', []),
            (array) config('lyw.interests', []),
            (string) config('lyw.schema', 'lyw-pack-v1'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $problems === [],
                'enabled' => $enabled,
                'packs_path' => $packsPath,
                'problems' => $problems,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        if (! $enabled) {
            $this->comment('LYW выключен (lyw.enabled=false) — проверка паков не требуется.');

            return self::SUCCESS;
        }

        if ($problems === []) {
            $groups = array_keys(array_filter(
                (array) config('lyw.enabled_groups', []),
                static fn ($flag): bool => (bool) $flag,
            ));
            $this->info('LYW packs: '.count($groups).' включённых групп, все манифесты на месте в '.$packsPath.'.');

            return self::SUCCESS;
        }

        $this->error('LYW packs: включённых групп без валидного пака — '.count($problems).':');
        foreach ($problems as $problem) {
            $this->line('  • '.$problem);
        }
        $this->newLine();
        $this->line('Источник паков: resources/data/lesson_packs (tracked). Пересобрать в SanskritGrammar: KocherginaUchebnik_1998/LessonPacks/zan1.');

        return self::FAILURE;
    }
}
