<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipFunnelEvent;
use App\Services\Membership\MembershipFunnelAnalytics;
use App\Services\Membership\MembershipPublicFeed;
use App\Services\Membership\PrivateArchiveEligibility;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MembershipCommerceController extends Controller
{
    public function __construct(
        private readonly MembershipPublicFeed $feed,
        private readonly MembershipFunnelAnalytics $analytics,
        private readonly PrivateArchiveEligibility $archives,
    ) {}

    public function feed(): JsonResponse
    {
        abort_unless((bool) config('features.membership_public_feed', false), 404);

        return response()->json($this->feed->build())
            ->header('Cache-Control', 'public, max-age=300')
            ->header('X-Robots-Tag', 'noindex');
    }

    public function samskrte(Request $request): View
    {
        return $this->storefront($request, 'samskrte', 'membership.storefront-editorial');
    }

    public function samskrtam(Request $request): View
    {
        return $this->storefront($request, 'samskrtam', 'membership.storefront-catalogue');
    }

    public function privateArchive(Request $request, string $archive): View
    {
        $user = $request->user();
        $definition = $this->archives->archive($archive);

        if (! is_array($definition) || ! $this->archives->enabled($archive)) {
            if (is_array($definition)) {
                $this->archives->audit($user, $archive, 'denied', 'disabled', $request);
            }
            abort(404);
        }

        if (! $user || ! $this->archives->eligible($user, $archive)) {
            $this->archives->audit($user, $archive, 'denied', 'not_eligible', $request);
            abort(404);
        }

        $tariffs = $this->archives->offerTariffs($archive);
        $this->archives->audit($user, $archive, 'viewed', 'eligible_payment', $request);
        $this->analytics->record(
            MembershipFunnelEvent::OFFER_VIEW,
            'private_archive',
            $user,
            archiveKey: $archive,
            feature: 'private_archive_offer',
            request: $request,
        );
        $this->analytics->record(
            MembershipFunnelEvent::FEATURE_USE,
            'private_archive',
            $user,
            archiveKey: $archive,
            feature: 'private_archive_view',
            request: $request,
        );

        return view('membership.private-archive', [
            'archiveKey' => $archive,
            'archive' => $definition,
            'tariffs' => $tariffs,
        ]);
    }

    private function storefront(Request $request, string $source, string $view): View
    {
        abort_unless((bool) config('features.membership_public_feed', false), 404);
        $feed = $this->feed->build();

        $this->analytics->record(
            MembershipFunnelEvent::OFFER_VIEW,
            $source,
            $request->user(),
            feature: 'autumn_storefront',
            request: $request,
            metadata: ['feed_version' => $feed['version']],
        );

        return view($view, ['feed' => $feed, 'sourceSite' => $source]);
    }
}
