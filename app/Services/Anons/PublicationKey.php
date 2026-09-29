<?php

declare(strict_types=1);

namespace App\Services\Anons;

/**
 * H5049 R2: стабильный ключ публикации = campaign|creative|destinations|slot.
 * Один и тот же манифест → один и тот же ключ: повторный прогон резолвит
 * существующую строку (resume/report), дубль создать невозможно.
 */
final class PublicationKey
{
    /** @param list<array{platform: string, account: string}> $destinations */
    public static function derive(
        string $campaign,
        string $creative,
        array $destinations,
        string $slot,
    ): string {
        $parts = array_map(
            fn (array $d): string => strtolower($d['platform'].'@'.$d['account']),
            $destinations,
        );
        sort($parts);

        return hash('sha256', implode('|', [
            strtolower($campaign),
            strtolower($creative),
            implode(',', $parts),
            strtolower(str_replace(' ', 'T', $slot)),
        ]));
    }

    public static function fromManifest(PublicationManifest $manifest): string
    {
        return self::derive(
            (string) $manifest->data['campaign'],
            (string) $manifest->data['creative'],
            $manifest->effectiveDestinations(),
            (string) $manifest->data['slot'],
        );
    }
}
