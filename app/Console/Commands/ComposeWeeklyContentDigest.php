<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\EmailBlastComposer;
use Illuminate\Console\Command;

/**
 * Composes (or refreshes) this week's `email_blast` ContentCandidate draft
 * from the free clips accepted in the last 7 days (H1548, Wave 2, D14).
 * Draft-only — staff must Accept it in Filament before
 * SendContentOneShotMailJob ever fires (gated by content_email_oneshot).
 */
class ComposeWeeklyContentDigest extends Command
{
    protected $signature = 'content:compose-weekly-digest';

    protected $description = 'Собрать/обновить черновик недельного email_blast-дайджеста бесплатных клипов.';

    public function handle(EmailBlastComposer $composer): int
    {
        $candidate = $composer->composeWeekly();

        if ($candidate === null) {
            $this->info('За последние 7 дней нет принятых бесплатных клипов — дайджест не создан.');

            return self::SUCCESS;
        }

        $this->info("Черновик готов: #{$candidate->id} «{$candidate->title}» — примите его в Filament, чтобы поставить отправку в очередь.");

        return self::SUCCESS;
    }
}
