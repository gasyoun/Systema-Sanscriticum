<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Listeners\Backup\SplitUploadToYandex;
use Illuminate\Console\Command;

/**
 * Докатка незавершённых групп split-upload на off-site диск: обрыв связи
 * посреди группы или лаг консистентности Яндекс WebDAV (H3371 — часть
 * «missing» сразу после PUT и на месте час спустя) оставляли группу
 * неполной до СЛЕДУЮЩЕГО еженедельного прогона. Расписание (Kernel,
 * ежедневно 04:10, до backup:monitor 04:35) сжимает это окно до суток.
 */
class ResumeYandexParts extends Command
{
    protected $signature = 'backup:resume-yandex-parts';

    protected $description = 'Докатить незавершённые группы split-upload частей на off-site диск';

    public function handle(): int
    {
        (new SplitUploadToYandex)->resumeOffsite();

        $this->info('split-upload: докатка завершена');

        return self::SUCCESS;
    }
}
