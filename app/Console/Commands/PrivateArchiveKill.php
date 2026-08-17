<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PrivateArchiveControl;
use Illuminate\Console\Command;

final class PrivateArchiveKill extends Command
{
    protected $signature = 'membership:archive-kill
        {archive : yoga_sutras|soboleva_ayurveda|druzhinin_ayurveda}
        {--restore : re-enable the named archive}
        {--reason= : operator reason stored in the audit control row}';

    protected $description = 'Separately disable or restore one authenticated private archive offer';

    public function handle(): int
    {
        $key = (string) $this->argument('archive');
        if (! is_array(config("membership.private_archives.archives.{$key}"))) {
            $this->error("Unknown private archive: {$key}");

            return self::FAILURE;
        }

        $restore = (bool) $this->option('restore');
        PrivateArchiveControl::query()->updateOrCreate(
            ['archive_key' => $key],
            [
                'disabled_at' => $restore ? null : now(),
                'reason' => filled($this->option('reason')) ? (string) $this->option('reason') : null,
            ],
        );

        $this->info($restore ? "RESTORED {$key}" : "KILLED {$key}");

        return self::SUCCESS;
    }
}
