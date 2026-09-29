<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\PaypalReceiptEvidenceImporter;
use Illuminate\Console\Command;

final class ImportPaypalReceiptEvidence extends Command
{
    protected $signature = 'money:import-paypal-receipts {csv} {mapping} {--apply}';

    protected $description = 'Validate and import immutable PayPal receipt evidence without changing payments or payouts';

    public function handle(PaypalReceiptEvidenceImporter $importer): int
    {
        try {
            $result = $importer->import((string) $this->argument('csv'), (string) $this->argument('mapping'), (bool) $this->option('apply'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($result as $key => $value) {
            $this->line("{$key}: {$value}");
        }

        return $result['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
