<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\LapseDetector;
use App\Services\Cabinet\RecordingsCatalog;
use App\Services\Cabinet\RecoveryStateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Phase 4 readiness probe for the hybrid cabinet flag flip (H1582 / R20).
 *
 * Does NOT flip the flag. Prints GO / HOLD with evidence for each gate.
 */
class CabinetHybridReadiness extends Command
{
    protected $signature = 'cabinet:hybrid-readiness
        {--baseline-start=2026-07-21 : Date Phase 0 telemetry hit prod (DEPLOY_QUEUE №25)}
        {--min-days=14 : R20 minimum baseline days before flip}';

    protected $description = 'Hybrid cabinet Phase 4 readiness (R20 gates) — never flips the flag';

    public function handle(): int
    {
        $minDays = max(1, (int) $this->option('min-days'));
        $start = Carbon::parse((string) $this->option('baseline-start'))->startOfDay();
        $daysLive = (int) $start->diffInDays(now()->startOfDay());

        $flagOn = (bool) config('features.cabinet_hybrid');
        $classes = [
            RecoveryStateResolver::class,
            LapseDetector::class,
            RecordingsCatalog::class,
            GrammarLadder::class,
        ];
        $classOk = true;
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                $classOk = false;
                $this->error("Missing class: {$class}");
            }
        }

        $routes = ['student.library', 'student.progress', 'student.access', 'student.dashboard'];
        $routeOk = true;
        foreach ($routes as $name) {
            if (! Route::has($name)) {
                $routeOk = false;
                $this->error("Missing route: {$name}");
            }
        }

        $homeViews = ActivityEvent::query()
            ->where('event_type', ActivityEvent::CABINET_HOME_VIEW)
            ->where('created_at', '>=', $start)
            ->count();

        $gateA = $daysLive >= $minDays;
        $gateB = $classOk && $routeOk;
        // C/D are human — report as manual.
        $overall = $gateA && $gateB && ! $flagOn;

        $this->newLine();
        $this->info('Cabinet hybrid readiness (Phase 4 / R20)');
        $this->table(
            ['Gate', 'Result', 'Evidence'],
            [
                [
                    'A baseline ≥'.$minDays.'d',
                    $gateA ? 'PASS' : 'HOLD',
                    "clock start {$start->toDateString()}, live {$daysLive}d, home.view events since start: {$homeViews}",
                ],
                [
                    'B code present',
                    $gateB ? 'PASS' : 'HOLD',
                    'Recovery/Lapse/Catalog/Ladder + routes library/progress/access',
                ],
                [
                    'C walkthrough',
                    'HUMAN',
                    'docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md §3',
                ],
                [
                    'D MG GO',
                    'HUMAN',
                    '@DECIDE before CABINET_HYBRID=true on prod',
                ],
                [
                    'Flag currently',
                    $flagOn ? 'ON (already flipped)' : 'OFF (safe default)',
                    'config(features.cabinet_hybrid)',
                ],
            ]
        );

        if ($flagOn) {
            $this->warn('Flag is already ON. Readiness is for pre-flip checks; verify this was intentional.');
        }

        if ($overall) {
            $this->info('MECHANICAL GO — still need human gates C+D before prod flip.');
            $this->line('Activate only via DEPLOY_QUEUE №52 after MG ruling.');

            return self::SUCCESS;
        }

        $this->warn('HOLD — do not flip CABINET_HYBRID on prod yet.');
        if (! $gateA) {
            $earliest = $start->copy()->addDays($minDays)->toDateString();
            $this->line("  Wait until ~{$earliest} for the {$minDays}-day baseline window (or re-baseline if clock was wrong).");
        }
        if (! $gateB) {
            $this->line('  Deploy main with Phases 1–3 before flip.');
        }

        return self::FAILURE;
    }
}
