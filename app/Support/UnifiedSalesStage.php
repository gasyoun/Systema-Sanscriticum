<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DealStage;
use App\Models\LeadStage;
use Illuminate\Support\Collection;

/**
 * ОБЩИЙ СЛОВАРЬ СТАДИЙ для сводной доски продаж (F9, H1658) — только слой
 * представления.
 *
 * Что это НЕ: физическое сведение `lead_stages` и `deal_stages` в одну таблицу.
 * Их формы несовместимы как нарисованы (строковый `key` ↔ числовой `id`) —
 * развилка F3 спеки §7, решённая в пользу отдельных таблиц. Рулинг 26-07-2026
 * по F9 аддитивен: обе доски остаются, общий слой строится ТРЕТЬИМ
 * представлением. Поэтому ни одна колонка отсюда никогда не пишется ни в
 * `lead_stages`, ни в `deal_stages` — это соответствие живёт только здесь.
 *
 * Почему класс, а не массив в config/: (1) у словаря есть поведение, а не
 * только данные — сопоставление в обе стороны и подбор целевой стадии по
 * колонке, и его надо покрывать тестами; (2) `config:cache` на проде заморозил
 * бы массив, а часть соответствия ВЫВОДИТСЯ из живых строк `deal_stages`
 * (`is_won`/`is_lost`/`position`) и обязана считаться в рантайме; (3) в доме
 * уже есть ровно такие value-классы рядом — RoleGate, Roles, DunningStage.
 *
 * Асимметрия сторон намеренная:
 *  - СДЕЛКИ выводятся структурно, из данных: `is_won` → «Выиграно»,
 *    `is_lost` → «Проиграно», первая по `position` → «Новые», остальные →
 *    «В работе». Хардкод ключей не нужен, и админ, добавивший свою стадию
 *    воронки, получает её в «В работе», а не теряет карточки с доски.
 *  - ЗАЯВКИ так вывести нельзя: `lead_stages` несёт только `is_final`, оно не
 *    различает «Конверсию» и «Отказ». Поэтому для них — явная таблица ключей
 *    (тех же, что уже захардкожены в Lead::markConverted), с тем же
 *    запасным вариантом «незнакомая стадия → В работе».
 */
final class UnifiedSalesStage
{
    public const NEW = 'new';

    public const IN_WORK = 'in_work';

    public const WON = 'won';

    public const LOST = 'lost';

    /**
     * Незнакомая стадия попадает сюда, а не исчезает с доски: тихо спрятать
     * карточку хуже, чем показать её в рабочей колонке.
     */
    public const FALLBACK = self::IN_WORK;

    /** @var array<string, string> lead_stages.key => общая колонка */
    private const LEAD_STAGE_COLUMN = [
        'new' => self::NEW,
        'in_work' => self::IN_WORK,
        'qualified' => self::IN_WORK,
        'converted' => self::WON,
        'rejected' => self::LOST,
    ];

    /**
     * Колонки сводной доски слева направо.
     *
     * @return array<string, string> ключ колонки => заголовок
     */
    public static function columns(): array
    {
        return [
            self::NEW => 'Новые',
            self::IN_WORK => 'В работе',
            self::WON => 'Выиграно',
            self::LOST => 'Проиграно',
        ];
    }

    public static function isColumn(string $column): bool
    {
        return array_key_exists($column, self::columns());
    }

    /**
     * Соответствие стадий заявок колонкам, в порядке sort_order.
     *
     * @return array<string, string> lead_stages.key => колонка
     */
    public static function leadStageColumns(): array
    {
        $keys = LeadStage::query()->ordered()->pluck('key')->all();
        $first = $keys[0] ?? null;

        $map = [];
        foreach ($keys as $key) {
            $map[$key] = self::LEAD_STAGE_COLUMN[$key]
                ?? ($key === $first ? self::NEW : self::FALLBACK);
        }

        return $map;
    }

    /**
     * Соответствие стадий сделок колонкам, в порядке position.
     *
     * @return array<int, string> deal_stages.id => колонка
     */
    public static function dealStageColumns(): array
    {
        $stages = self::orderedDealStages();
        $firstId = $stages->first()?->id;

        return $stages
            ->mapWithKeys(fn (DealStage $stage): array => [$stage->id => match (true) {
                $stage->is_won => self::WON,
                $stage->is_lost => self::LOST,
                $stage->id === $firstId => self::NEW,
                default => self::IN_WORK,
            }])
            ->all();
    }

    /** @param  array<string, string>  $map результат leadStageColumns() */
    public static function forLeadIn(array $map, ?string $status): string
    {
        return $map[(string) $status] ?? self::FALLBACK;
    }

    /** @param  array<int, string>  $map результат dealStageColumns() */
    public static function forDealIn(array $map, ?int $stageId): string
    {
        return $map[(int) $stageId] ?? self::FALLBACK;
    }

    public static function forLead(?string $status): string
    {
        return self::forLeadIn(self::leadStageColumns(), $status);
    }

    public static function forDeal(?int $stageId): string
    {
        return self::forDealIn(self::dealStageColumns(), $stageId);
    }

    /**
     * Ключ стадии заявки, который надо записать при переносе в колонку —
     * первая по sort_order стадия этой колонки. Возвращает null, если воронка
     * заявок такой колонки не содержит (перенос тогда отклоняется, а не
     * угадывается).
     */
    public static function leadStatusFor(string $column): ?string
    {
        foreach (self::leadStageColumns() as $key => $mapped) {
            if ($mapped === $column) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Стадия сделки, на которую переводит перенос в колонку — первая по
     * position стадия этой колонки. Выбор идёт по тому же соответствию, что и
     * раскладка карточек, поэтому обратный проход гарантированно попадает в ту
     * же колонку (инвариант закреплён тестом).
     */
    public static function dealStageFor(string $column): ?DealStage
    {
        $map = self::dealStageColumns();

        return self::orderedDealStages()
            ->first(fn (DealStage $stage): bool => ($map[$stage->id] ?? null) === $column);
    }

    /** @return Collection<int, DealStage> */
    private static function orderedDealStages(): Collection
    {
        return DealStage::query()->ordered()->get();
    }
}
