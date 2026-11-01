<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Content\PostFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рубрика календаря контента (28-10-2026): расписание — дни недели и время
 * (MSK), очередь — её слоты. Пост рубрики без даты получает ближайшее
 * свободное время рубрики (RubricScheduler); публикует его обычный
 * content:publish-due.
 *
 * schedule: [{"days": [1, 3, 5], "time": "18:00"}, …], дни ISO (1 = пн).
 */
class ContentCalendarRubric extends Model
{
    public const DAY_LABELS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    protected $fillable = [
        'name',
        'prefix',
        'schedule',
        'channels',
        'with_story',
        'is_active',
        'last_empty_alert_for',
    ];

    protected $casts = [
        'schedule' => 'array',
        'channels' => 'array',
        'with_story' => 'boolean',
        'is_active' => 'boolean',
        'last_empty_alert_for' => 'datetime',
    ];

    protected $attributes = [
        'with_story' => true,
        'is_active' => true,
    ];

    public function slots(): HasMany
    {
        return $this->hasMany(ContentCalendarSlot::class, 'rubric_id');
    }

    /**
     * Площадки постов рубрики: канал школы = его TG и VK; со сторис — ещё
     * сторис VK канала и сторис Telegram Business. Только включённые
     * площадки; без отмеченных каналов — null (площадки поста не трогаем).
     *
     * @return list<string>|null
     */
    public function destinationKeys(): ?array
    {
        $channels = array_values(array_intersect((array) $this->channels, array_keys((array) config('content_calendar.channels', []))));
        if ($channels === []) {
            return null;
        }

        $keys = [];
        foreach ($channels as $channel) {
            $keys[] = "tg_{$channel}";
            $keys[] = "vk_{$channel}";
            if ($this->with_story) {
                $keys[] = "vk_story_{$channel}";
            }
        }
        if ($this->with_story) {
            $keys[] = 'tg_story_business';
        }

        return array_values(array_intersect(array_keys(ContentCalendarSlot::enabledDestinations()), $keys));
    }

    /**
     * Площадки рубрики → её ещё не вышедшие посты (ни одной публикации).
     * Площадки слота задаются при выборе рубрики в форме; без этого смена
     * каналов рубрики не доходила бы до уже стоящих в ней постов.
     *
     * @return int сколько постов получили новые площадки
     */
    public function syncSlotDestinations(): int
    {
        $keys = $this->destinationKeys();
        $changed = 0;
        $this->slots()
            ->whereIn('status', [
                ContentCalendarSlot::STATUS_SCHEDULED,
                ContentCalendarSlot::STATUS_DRAFT,
                ContentCalendarSlot::STATUS_EMPTY,
                ContentCalendarSlot::STATUS_KEPT,
            ])
            ->whereDoesntHave('publications')
            ->get()
            ->each(function (ContentCalendarSlot $slot) use ($keys, &$changed): void {
                if ($slot->destinations !== $keys) {
                    $slot->forceFill(['destinations' => $keys])->save();
                    $changed++;
                }
            });

        return $changed;
    }

    /** «Оповещение, ОРС» */
    public function channelsLabel(): string
    {
        $names = (array) config('content_calendar.channels', []);

        return implode(', ', array_map(static fn (string $c): string => $names[$c] ?? $c, array_values(array_intersect(array_keys($names), (array) $this->channels))));
    }

    /**
     * Нормализованное расписание: только валидные дни и время ЧЧ:ММ.
     *
     * @return list<array{days: list<int>, time: string}>
     */
    public function scheduleEntries(): array
    {
        $entries = [];
        foreach ((array) $this->schedule as $entry) {
            $days = array_values(array_unique(array_filter(
                array_map('intval', (array) ($entry['days'] ?? [])),
                static fn (int $d): bool => $d >= 1 && $d <= 7,
            )));
            sort($days);
            $time = substr((string) ($entry['time'] ?? ''), 0, 5);
            if ($days !== [] && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
                $entries[] = ['days' => $days, 'time' => $time];
            }
        }

        return $entries;
    }

    /** «пн, ср, пт 18:00; сб 12:00» */
    public function scheduleLabel(): string
    {
        return implode('; ', array_map(
            static fn (array $e): string => implode(', ', array_map(static fn (int $d): string => self::DAY_LABELS[$d], $e['days'])).' '.$e['time'],
            $this->scheduleEntries(),
        ));
    }

    /**
     * Префикс рубрики в начало первого абзаца, если текст с него ещё не
     * начинается. Префикс — простой текст.
     */
    public function applyPrefix(?string $html): ?string
    {
        $prefix = (string) $this->prefix;
        if (trim($prefix) === '' || blank($html) || $this->hasPrefix($html)) {
            return $html;
        }

        $escaped = e($prefix);
        if (preg_match('/^\s*<(p|h[1-6])(\s[^>]*)?>/i', (string) $html, $m) === 1) {
            return substr((string) $html, 0, strlen($m[0])).$escaped.substr((string) $html, strlen($m[0]));
        }

        return $escaped.$html;
    }

    /** Сколько символов (UTF-16, как считает Bot API) добавит префикс к этому тексту. */
    public function prefixLength(?string $html): int
    {
        if (trim((string) $this->prefix) === '' || $this->hasPrefix($html)) {
            return 0;
        }

        return intdiv(strlen((string) mb_convert_encoding((string) $this->prefix, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function hasPrefix(?string $html): bool
    {
        return str_starts_with(ltrim(PostFormatter::toPlain($html)), trim((string) $this->prefix));
    }
}
