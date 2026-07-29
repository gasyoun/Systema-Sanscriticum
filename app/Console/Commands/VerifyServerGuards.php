<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ServerGuards\GuardFinding;
use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\ServerGuardsAuditor;
use App\Support\ServerGuards\ShellSystemInspector;
use Illuminate\Console\Command;
use Throwable;

/**
 * Громкая проверка, что ресурсные предохранители прода на месте (H1914).
 *
 * Ничего не применяет — это делает scripts/server_guards_apply.sh. Возвращает
 * non-zero и печатает, ЧТО ИМЕННО пропало: пересборка LXC или восстановление из
 * бэкапа сносят предохранители молча, и до этой команды заметить это было нечем.
 */
class VerifyServerGuards extends Command
{
    protected $signature = 'guards:verify
        {--json : Выдать находки машиночитаемо}
        {--force : Проверять даже там, где проверка отключена (не Linux/CI)}';

    protected $description = 'Проверить ресурсные предохранители прода (cron-обёртка, сторожа, потолки памяти, earlyoom, логи)';

    public function handle(): int
    {
        if (! config('server_guards.verify_enabled') && ! $this->option('force')) {
            $this->comment('guards:verify выключен для этого окружения (SERVER_GUARDS_VERIFY). --force, чтобы всё равно проверить.');

            return self::SUCCESS;
        }

        try {
            $spec = GuardSpec::fromFile((string) config('server_guards.spec_path'));
            $auditor = new ServerGuardsAuditor(
                $spec,
                new ShellSystemInspector,
                (string) config('server_guards.template_root'),
            );
            $findings = $auditor->audit();
        } catch (Throwable $e) {
            // Нечитаемый spec/манифест — это тоже пропажа предохранителя: проверять
            // стало нечем. Молчать здесь нельзя.
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('guards:verify не смог проверить: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        $blocking = ServerGuardsAuditor::hasBlocking($findings);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $blocking,
                'findings' => array_map(static fn (GuardFinding $f): array => $f->toArray(), $findings),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $blocking ? self::FAILURE : self::SUCCESS;
        }

        $bySeverity = [GuardFinding::CRITICAL => [], GuardFinding::WARNING => [], GuardFinding::INFO => []];
        foreach ($findings as $finding) {
            $bySeverity[$finding->severity][] = $finding;
        }

        if (! $blocking) {
            $this->info('Предохранители на месте: все проверки пройдены.');
        }

        foreach ([GuardFinding::CRITICAL => 'ПРОПАЛ ПРЕДОХРАНИТЕЛЬ', GuardFinding::WARNING => 'расхождение'] as $severity => $title) {
            if ($bySeverity[$severity] === []) {
                continue;
            }
            $severity === GuardFinding::CRITICAL
                ? $this->error($title.' ('.count($bySeverity[$severity]).'):')
                : $this->warn($title.' ('.count($bySeverity[$severity]).'):');
            foreach ($bySeverity[$severity] as $finding) {
                $this->line('  • '.$finding->guard.': '.$finding->message);
            }
        }

        foreach ($bySeverity[GuardFinding::INFO] as $finding) {
            $this->comment('  ℹ '.$finding->guard.': '.$finding->message);
        }

        if ($blocking) {
            $this->newLine();
            $this->line('Вернуть на место: <options=bold>sudo bash scripts/server_guards_apply.sh</>');
            $this->line('Разбор и смысл каждого предохранителя: docs/server-resource-guards.md');
        }

        return $blocking ? self::FAILURE : self::SUCCESS;
    }
}
