<?php

declare(strict_types=1);

namespace App\Scratch;

// H4641 acceptance (a): PLANTED unqualified inline FQCN — Semgrep must fail CI on this file.
// Reverted in the next commit; never merged.

final class ScratchH4641
{
    public function countSchedules(): int
    {
        return App\Models\Schedule::where("id", 1)->count();
    }
}
