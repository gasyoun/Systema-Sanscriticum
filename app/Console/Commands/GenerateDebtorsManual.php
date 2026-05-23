<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateDebtorsManual extends Command
{
    protected $signature = 'docs:debtors-manual
                            {--out= : Куда сохранить PDF (по умолчанию storage/app/manuals/debtors-manual.pdf)}';

    protected $description = 'Сгенерировать PDF-инструкцию по сервису «Должники» для менеджеров и админов';

    public function handle(): int
    {
        $out = (string) ($this->option('out') ?: storage_path('app/manuals/debtors-manual.pdf'));

        File::ensureDirectoryExists(dirname($out));

        $pdf = Pdf::loadView('pdf.debtors-manual', [
            'generatedAt' => now()->format('d.m.Y'),
        ])->setPaper('a4');

        File::put($out, $pdf->output());

        $this->info('PDF сохранён: '.$out);

        return self::SUCCESS;
    }
}
