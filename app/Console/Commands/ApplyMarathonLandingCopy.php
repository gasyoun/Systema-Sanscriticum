<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandingPage;
use App\Support\MarathonLandingCopy;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * H1067 publish step 1 — upsert Filament LandingPage row from ruled copy.
 * Public /online/konsultaciya reads variant from config; this keeps the
 * LandingPage slug row (magnet/SEO) in sync. Default variant A.
 */
final class ApplyMarathonLandingCopy extends Command
{
    protected $signature = 'marathon:apply-landing-copy
                            {variant? : a (default) or b}
                            {--january : Upsert the January deva-cohort landing instead (H445 Phase 5)}
                            {--dry-run : Print payload without writing}';

    protected $description = 'Upsert marathon LandingPage from H1067 copy (A first, then B), or --january for the deva cohort';

    public function handle(): int
    {
        if ($this->option('january')) {
            return $this->handleJanuary();
        }

        $variant = strtolower((string) ($this->argument('variant') ?: MarathonLandingCopy::variantKey()));

        try {
            $payload = MarathonLandingCopy::forLandingPage($variant);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $slug = $payload['slug'];
        $this->info("Variant [{$variant}] → LandingPage slug [{$slug}]");
        $this->line('Hero: '.$payload['subtitle']);

        if ($this->option('dry-run')) {
            $this->warn('Dry-run — no DB write.');
            $this->line(json_encode([
                'title' => $payload['title'],
                'slug' => $slug,
                'button_text' => $payload['button_text'],
                'content_blocks' => count($payload['content'] ?? []),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $page = LandingPage::query()->updateOrCreate(
            ['slug' => $slug],
            $payload
        );

        $this->info("LandingPage #{$page->id} upserted (variant {$variant}).");
        $this->comment('Public hero is driven by MARATHON_LANDING_COPY_VARIANT + config:clear after deploy.');

        return self::SUCCESS;
    }

    /** H445 Phase 5 — January deva-cohort landing (single ruled copy, no A/B). */
    private function handleJanuary(): int
    {
        try {
            $payload = MarathonLandingCopy::forJanuaryLandingPage();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $slug = $payload['slug'];
        $this->info("January (deva cohort) → LandingPage slug [{$slug}]");
        $this->line('Hero: '.$payload['subtitle']);

        if ($this->option('dry-run')) {
            $this->warn('Dry-run — no DB write.');
            $this->line(json_encode([
                'title' => $payload['title'],
                'slug' => $slug,
                'button_text' => $payload['button_text'],
                'content_blocks' => count($payload['content'] ?? []),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $page = LandingPage::query()->updateOrCreate(
            ['slug' => $slug],
            $payload
        );

        $this->info("LandingPage #{$page->id} upserted (January).");
        $this->comment('Launch date: '.(string) config('marathon.january_launch_date').'. Day-3 Schedule row still needs a human closer to launch (H445 §0).');

        return self::SUCCESS;
    }
}
