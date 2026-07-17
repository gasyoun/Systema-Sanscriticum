<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Присутствие посетителя витрины «прямо сейчас» (H1197, Jivo-паритет Pillar 2).
 *
 * Эфемерная строка на посетителя: обновляется beacon'ом виджета
 * (PublicPresenceController) и выметается PruneStaleVisitorPresencesJob. Куратор
 * читает live-строки в Filament-странице «Посетители онлайн» и может написать
 * первым. IP наружу куратору НЕ показываем — только город/страну (locationLabel).
 *
 * @property string $guest_token
 * @property int|null $user_id
 * @property string|null $visitor_ip
 * @property string|null $visitor_city
 * @property string|null $visitor_region
 * @property string|null $visitor_country
 * @property Carbon|null $visitor_geo_resolved_at
 * @property string|null $current_url
 * @property string|null $referrer
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class SupportVisitorPresence extends Model
{
    protected $fillable = [
        'guest_token',
        'user_id',
        'visitor_ip',
        'visitor_city',
        'visitor_region',
        'visitor_country',
        'visitor_geo_resolved_at',
        'current_url',
        'referrer',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'visitor_geo_resolved_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Онлайн-посетители: последний beacon не позднее online_window_seconds назад.
     * Окно берётся из config('support_presence'), чтобы куратор не видел «призраков»
     * тех, кто уже ушёл, но чью строку ещё не вымело.
     */
    public function scopeOnline(Builder $query): Builder
    {
        $seconds = (int) config('support_presence.online_window_seconds', 75);

        return $query->where('last_seen_at', '>=', now()->subSeconds($seconds));
    }

    /** Город/страна для куратора; null, если гео не разрешено (реюз паттерна S1). */
    public function locationLabel(): ?string
    {
        $parts = array_filter([$this->visitor_city, $this->visitor_country]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** Отображаемое имя посетителя для списка куратора. */
    public function displayName(): string
    {
        if ($this->user_id && $this->user) {
            return $this->user->name ?: ('Студент #'.$this->user_id);
        }

        return 'Гость';
    }

    /** Сколько секунд посетитель на сайте (last_seen - first_seen), >= 0. */
    public function secondsOnSite(): int
    {
        if (! $this->first_seen_at || ! $this->last_seen_at) {
            return 0;
        }

        return max(0, $this->last_seen_at->getTimestamp() - $this->first_seen_at->getTimestamp());
    }

    /** Человекочитаемое «на сайте N мин / N сек» для панели куратора. */
    public function timeOnSiteLabel(): string
    {
        $seconds = $this->secondsOnSite();

        if ($seconds < 60) {
            return $seconds.' сек';
        }

        return intdiv($seconds, 60).' мин';
    }
}
