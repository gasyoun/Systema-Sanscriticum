<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GrammarLab\GrammarLabPilot;
use Illuminate\Console\Command;

/**
 * H2495 — list eligible current students. Does not enroll, charge, or grant.
 */
class GrammarLabPilotEligibility extends Command
{
    protected $signature = 'grammar-lab:pilot-eligibility {--json : Machine-readable counts only}';

    protected $description = 'Count Grammar Lab pilot-eligible students (no enroll, no PII in default output)';

    public function handle(GrammarLabPilot $pilot): int
    {
        $n = $pilot->eligibleUsers()->count();
        $slugs = $pilot->eligibilityCourseSlugs();
        $payload = [
            'n_eligible' => $n,
            'eligibility_course_slugs' => $slugs,
            'pilot_flag' => $pilot->flagOn() ? 'ON' : 'OFF',
            'note' => 'Does not enroll. Human authorization required before a 5–10 cohort is invited.',
        ];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('eligible='.$n.' slugs='.($slugs === [] ? '(none configured)' : implode(',', $slugs)));
            $this->line($payload['note']);
        }

        return self::SUCCESS;
    }
}
