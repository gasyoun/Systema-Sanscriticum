<?php

declare(strict_types=1);

namespace App\Console\Commands;

/**
 * Import the second Arzamas-style longread («Россия и санскритский словарь» —
 * Kossovich vs Böhtlingk, H1696) from the repo-controlled pack under
 * docs/materials/kossovich-arzamas/.
 *
 * A thin sibling of the PWG command (H1696 pipeline step 3): same upsert
 * semantics — SOURCE.md is rendered to body.html by the pack's build_body.py,
 * this command only imports the already-rendered payload, idempotently by
 * slug, and never unpublishes a published article.
 */
class ImportKossovichArzamasMaterial extends ImportPwgArzamasMaterial
{
    protected $signature = 'materials:import-kossovich-arzamas
        {--publish : set is_published=true (final run, after ACCEPT is green)}
        {--body= : override body.html path (tests only)}
        {--meta= : override meta.json path (tests only)}';

    protected $description = 'Upsert the Kossovich Arzamas longread article from docs/materials/kossovich-arzamas/';

    protected string $packDir = 'docs/materials/kossovich-arzamas';

    protected int $minH2 = 12;
}
