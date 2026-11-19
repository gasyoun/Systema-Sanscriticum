<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final class UpcomingIntake
{
    /** @return array{title:string,meta_title:string,waitlist_slug:string,starts_at:CarbonImmutable,timezone:string,weekly_label:string,upcoming:bool}|null */
    public static function for(string $courseSlug): ?array
    {
        $intakes = config('upcoming_intakes', []);
        $entry = $intakes[$courseSlug] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $start = CarbonImmutable::parse($entry['starts_at'], $entry['timezone']);

        return array_merge($entry, [
            'starts_at' => $start,
            'upcoming' => $start->isFuture(),
        ]);
    }
}
