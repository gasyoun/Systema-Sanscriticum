<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MarketingSetting;
use App\Models\Tariff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Живой каталог курсов для базы знаний ИИ-куратора: цены, блоки, расписание —
 * прямо из админки/БД. Заменяет хардкод-таблицу, которая раньше лежала в
 * VkBotController и устаревала. Результат кэшируется (меняется редко, а бот
 * дёргает его на каждое сообщение).
 */
class CourseCatalogProvider
{
    private const CACHE_KEY = 'bot.kb.catalog';

    private const CACHE_TTL = 600; // 10 минут

    /**
     * Markdown-блок «Каталог курсов» для системного промпта. Кэшируется.
     */
    public function markdown(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->build());
    }

    /**
     * Сбросить кэш (вызывать при массовом изменении цен/курсов, если нужно
     * мгновенно). На обычном TTL и так обновится сам.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function build(): string
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with([
                'teacher:id,name',
                'blocks',
                'tariffs' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderBy('title')
            ->get();

        if ($courses->isEmpty()) {
            return "# Каталог курсов\n\nСейчас нет активных курсов в продаже.";
        }

        $lines = [];
        $lines[] = '# Каталог курсов (актуальные данные из системы Академии)';
        $lines[] = '';

        if ($loyalty = $this->loyaltyNote()) {
            $lines[] = $loyalty;
            $lines[] = '';
        }

        foreach ($courses as $course) {
            $teacher = $course->teacher?->name;
            $heading = $course->title.($teacher ? ' — '.$teacher : '');
            $lines[] = '## '.$heading;

            $lines[] = '- Формат: '.($course->isLive() ? 'живые занятия (онлайн)' : 'видеозаписи');

            if ($blocksLine = $this->blocksLine($course->blocks)) {
                $lines[] = '- '.$blocksLine;
            }

            $lines[] = '- '.$this->priceLine($course->tariffs);

            if ($url = $this->courseUrl($course)) {
                $lines[] = '- Страница курса и оплата: '.$url;
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function loyaltyNote(): ?string
    {
        $m = MarketingSetting::cached();
        if (! $m || ! $m->is_loyalty_active) {
            return null;
        }

        $parts = [];
        if ($m->wholesale_small_threshold > 0 && $m->wholesale_small_discount > 0) {
            $parts[] = "от {$m->wholesale_small_threshold} курсов — {$m->wholesale_small_discount}%";
        }
        if ($m->wholesale_large_threshold > 0 && $m->wholesale_large_discount > 0) {
            $parts[] = "от {$m->wholesale_large_threshold} курсов — {$m->wholesale_large_discount}%";
        }

        if (empty($parts)) {
            return null;
        }

        return '**Скидка за лояльность** (за оплаченные курсы в течение года): '
            .implode(', ', $parts).'. Скидки не суммируются с промокодами.';
    }

    /**
     * @param  iterable<CourseBlock>  $blocks
     */
    private function blocksLine(iterable $blocks): ?string
    {
        $blocks = collect($blocks);
        $count = $blocks->count();
        if ($count === 0) {
            return null;
        }

        $starts = $blocks->pluck('starts_at')->filter();
        $ends = $blocks->pluck('ends_at')->filter();

        $range = '';
        if ($starts->isNotEmpty()) {
            $from = $starts->min();
            $to = $ends->isNotEmpty() ? $ends->max() : null;
            $fromStr = $from instanceof Carbon ? $from->format('d.m.Y') : null;
            $toStr = $to instanceof Carbon ? $to->format('d.m.Y') : null;
            if ($fromStr && $toStr) {
                $range = " (даты: {$fromStr} – {$toStr})";
            } elseif ($fromStr) {
                $range = " (старт: {$fromStr})";
            }
        }

        return "Блоков: {$count}{$range}";
    }

    /**
     * @param  iterable<Tariff>  $tariffs
     */
    private function priceLine(iterable $tariffs): string
    {
        $tariffs = collect($tariffs);

        $full = $tariffs->firstWhere('type', 'full');

        // Целые блоки (без половин), по номеру.
        $blockTariffs = $tariffs
            ->filter(fn (Tariff $t) => $t->type === 'block' && $t->block_half === null && $t->block_number !== null)
            ->sortBy('block_number');

        $parts = [];
        if ($full) {
            $parts[] = 'весь курс: '.$this->rub($full->price);
        }
        if ($blockTariffs->isNotEmpty()) {
            $uniquePrices = $blockTariffs->pluck('price')->map(fn ($p) => (float) $p)->unique();

            if ($uniquePrices->count() === 1) {
                // Все блоки по одной цене — не печатаем сотню одинаковых строк.
                $parts[] = 'поблочно: '.$this->rub($uniquePrices->first()).' за блок';
            } else {
                // Цены разнятся — перечисляем, но с потолком, чтобы не раздувать промпт.
                $cap = 12;
                $listed = $blockTariffs->take($cap)
                    ->map(fn (Tariff $t) => '№'.$t->block_number.' — '.$this->rub($t->price))
                    ->implode('; ');
                if ($blockTariffs->count() > $cap) {
                    $listed .= '; …';
                }
                $parts[] = 'поблочно: '.$listed;
            }
        }

        if (empty($parts)) {
            return 'Цена: уточняется (напишите «позови куратора»).';
        }

        return 'Цена — '.implode('. ', $parts).'.';
    }

    private function rub($price): string
    {
        return number_format((float) $price, 0, '.', ' ').' ₽';
    }

    /**
     * Абсолютная ссылка на публичную страницу курса (там же — выбор блока и
     * оплата). Базу берём из app.url, а не из request: каталог кэшируется, а
     * строится в контексте входящего webhook'а — host у запроса чужой.
     */
    private function courseUrl(Course $course): ?string
    {
        if (empty($course->slug)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').route('shop.course.show', $course->slug, false);
    }
}
