<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/** Observed campaign metadata only; never guesses a source for direct visits. */
final class AcquisitionAttribution
{
    private const FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id'];

    public static function scalar(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    public static function forLead(Request $request): array
    {
        // Match existing LeadController policy: tracked-link metadata takes priority
        // over raw first-visit metadata; this is not a global first-touch model.
        $stored = $request->session()->get((string) config('tracked_links.session_key'),
            $request->session()->get('attribution', []));
        $result = self::fields(is_array($stored) ? $stored : []);
        $referrer = self::scalar($request->session()->get('attribution.referrer'));
        if ($referrer !== null) {
            $result['referrer'] = $referrer;
        }

        return $result;
    }

    private static function fields(array $input): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            if (($value = self::scalar($input[$field] ?? null)) !== null) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    public static function externalReferrer(Request $request): ?string
    {
        $value = $request->headers->get('referer');
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $ownHosts = [strtolower($request->getHost()), strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))];
        foreach ($ownHosts as $ownHost) {
            $ownHost = preg_replace('/^www\./', '', $ownHost);
            if ($ownHost !== '' && ($host === $ownHost || str_ends_with($host, '.'.$ownHost))) {
                return null;
            }
        }
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Drop query, credentials and fragments: these can contain private tokens.
        return self::scalar($scheme.'://'.$host.(parse_url($value, PHP_URL_PATH) ?? ''));
    }
}
