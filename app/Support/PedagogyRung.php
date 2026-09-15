<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mirrors docs/schema/ai_native_pedagogy_model.yaml `vocabularies.rung`
 * (H2585). First PHP-side use of the rung vocabulary (H4818/R2609-01) — the
 * YAML file states explicitly that no Systema code reads it yet; keep this
 * list in sync by hand with that file until a generator exists.
 */
enum PedagogyRung: string
{
    case A0 = 'A0';
    case A1 = 'A1';
    case A2 = 'A2';
    case B1 = 'B1';
    case B2 = 'B2';
    case C1 = 'C1';
    case C2 = 'C2';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
