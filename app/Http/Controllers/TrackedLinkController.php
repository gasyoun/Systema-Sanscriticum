<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TrackedLinkController extends Controller
{
    /** Store campaign attribution without exposing it in the destination URL. */
    public function __invoke(Request $request, string $link): RedirectResponse
    {
        $definition = config("tracked_links.links.{$link}");

        if (! is_array($definition)) {
            $definition = $this->storyDefinition($link);
        }

        abort_unless(is_array($definition), 404);

        $sessionKey = (string) config('tracked_links.session_key');
        if (! $request->session()->has($sessionKey)) {
            $request->session()->put($sessionKey, $definition['utm']);
        }

        return redirect()->to($definition['destination']);
    }

    /** Resolve a unique short link for one Story publication. */
    private function storyDefinition(string $link): ?array
    {
        if (preg_match('/^(?<campaign>[a-z0-9]+)-(?<account>[a-z0-9]+)-st-(?<creative>[a-z0-9-]+)-(?<date>\d{8})-(?<sequence>\d{2})$/', $link, $parts) !== 1) {
            return null;
        }

        $campaign = config("tracked_links.story_campaigns.{$parts['campaign']}");
        $source = config("tracked_links.story_accounts.{$parts['account']}");
        if (! is_array($campaign) || ! is_string($source)) {
            return null;
        }

        return [
            'destination' => $campaign['destination'],
            'utm' => [
                'utm_source' => $source,
                'utm_medium' => 'story',
                'utm_campaign' => $campaign['utm_campaign'],
                'utm_content' => implode('_', [
                    $parts['creative'],
                    'story',
                    $parts['date'],
                    $parts['sequence'],
                ]),
                'utm_term' => $campaign['utm_term'],
            ],
        ];
    }
}
