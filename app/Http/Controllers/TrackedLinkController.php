<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AnonsDestinationRun;
use App\Models\AnonsLinkClick;
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

        // H5049 R6: клик по короткой ссылке РАНЬШЕ нигде не фиксировался
        // (redirect-only) — счётчик без PII: слаг + UTM-кортеж + время.
        $slug = AnonsLinkClick::slugFrom('/ga/'.$link);
        if ($slug !== null) {
            $publicationKey = $this->resolvePublicationKey($slug);
            AnonsLinkClick::query()->create([
                'link' => $slug,
                'publication_key' => $publicationKey,
                'utm' => $definition['utm'],
                'clicked_at' => now(),
            ]);
        }

        $sessionKey = (string) config('tracked_links.session_key');
        if (! $request->session()->has($sessionKey)) {
            $request->session()->put($sessionKey, $definition['utm']);
        }

        return redirect()->to($definition['destination']);
    }

    /**
     * H5049: связать клик с publication_key подсистемы anons, если короткая
     * ссылка из манифеста уже публиковалась (анонсные слаги).
     */
    private function resolvePublicationKey(string $slug): ?string
    {
        return AnonsDestinationRun::query()
            ->join('anons_publications', 'anons_publications.id', '=', 'anons_destination_runs.anons_publication_id')
            ->where('anons_destination_runs.short_link', 'https://samskrte.ru/ga/'.$slug)
            ->value('anons_publications.publication_key') ?? null;
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
