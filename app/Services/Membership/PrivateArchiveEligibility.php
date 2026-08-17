<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Models\Payment;
use App\Models\PrivateArchiveAccessEvent;
use App\Models\PrivateArchiveControl;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PrivateArchiveEligibility
{
    /** @return array<string,mixed>|null */
    public function archive(string $key): ?array
    {
        $archive = config("membership.private_archives.archives.{$key}");

        return is_array($archive) ? $archive : null;
    }

    public function enabled(string $key): bool
    {
        $archive = $this->archive($key);
        if (! (bool) config('features.membership_private_archives', false)
            || ! is_array($archive)
            || ! (bool) ($archive['enabled'] ?? false)) {
            return false;
        }

        return ! PrivateArchiveControl::query()
            ->whereKey($key)
            ->whereNotNull('disabled_at')
            ->exists();
    }

    public function eligible(User $user, string $key): bool
    {
        $archive = $this->archive($key);
        $slugs = is_array($archive) ? array_values(array_filter((array) ($archive['eligibility_course_slugs'] ?? []))) : [];
        if ($slugs === []) {
            return false;
        }

        return Payment::query()
            ->real()
            ->paid()
            ->where('user_id', $user->id)
            ->whereHas('course', fn (Builder $query) => $query->whereIn('slug', $slugs))
            ->exists();
    }

    /** @return Collection<int,Tariff> */
    public function offerTariffs(string $key): Collection
    {
        $slug = (string) ($this->archive($key)['offer_course_slug'] ?? '');
        if ($slug === '') {
            return collect();
        }

        return Tariff::query()
            ->where('is_active', true)
            ->whereHas('course', fn (Builder $query) => $query->where('slug', $slug))
            ->with('course')
            ->orderBy('price')
            ->get();
    }

    public function audit(?User $user, string $key, string $action, string $reason, Request $request): void
    {
        PrivateArchiveAccessEvent::create([
            'user_id' => $user?->id,
            'archive_key' => $key,
            'action' => $action,
            'reason' => $reason,
            'request_hash' => hash('sha256', implode('|', [
                $request->session()->getId(),
                $request->ip() ?? '',
                now()->toDateString(),
            ])),
            'created_at' => now(),
        ]);
    }

    /** @return list<string> */
    public static function privateOfferSlugs(): array
    {
        return collect((array) config('membership.private_archives.archives', []))
            ->pluck('offer_course_slug')
            ->filter(fn ($slug): bool => is_string($slug) && $slug !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function scopePublic(Builder $query): Builder
    {
        if (! (bool) config('features.membership_private_archives', false)) {
            return $query;
        }

        $slugs = self::privateOfferSlugs();

        return $slugs === [] ? $query : $query->whereNotIn('slug', $slugs);
    }
}
