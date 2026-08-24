<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Group;

/**
 * Блок «Статус курса» (status_block) в контенте лендинга — единый источник
 * для трёх потребителей: рендер блока на лендинге, гейт выдачи binding-токена
 * (H3339) и матчинг связанных гостей группе при рассылке статусов.
 *
 * Привязка к группе: явный group_id из данных блока, иначе — набирающаяся
 * группа семьи course_family с порогом min_size и датой старта (оболочки без
 * порога/даты не считаются запуском). Раньше эта логика жила прямо в blade.
 */
final class StatusBlock
{
    public const TYPE = 'status_block';

    /**
     * Данные первого status_block в контенте лендинга (или null).
     *
     * @param  array<int, mixed>|null  $content
     * @return array<string, mixed>|null
     */
    public static function data(?array $content): ?array
    {
        foreach ($content ?? [] as $block) {
            if (($block['type'] ?? null) === self::TYPE) {
                return is_array($block['data'] ?? null) ? $block['data'] : [];
            }
        }

        return null;
    }

    /**
     * Есть ли в контенте лендинга status_block — единственный гейт выдачи
     * binding-токена заявке (кнопки «Подключить уведомления»).
     */
    public static function inContent(?array $content): bool
    {
        return self::data($content) !== null;
    }

    /**
     * Группа, чьи статусы показывает этот блок: явный group_id, иначе семья
     * потоков. null — блок не привязан ни к чему конкретному (не рендерится,
     * токены не выдаются, гости этого лендинга ни к какой группе не относятся).
     */
    public static function resolveGroup(?array $data): ?Group
    {
        if ($data === null) {
            return null;
        }

        if (! empty($data['group_id'])) {
            return Group::find($data['group_id']);
        }

        $family = $data['course_family'] ?? null;
        if (! $family) {
            return null;
        }

        $launchable = fn ($query) => $query->whereNotNull('min_size')->whereNotNull('planned_start_date');
        $byFamily = fn ($query) => $query->whereHas('courses', fn ($c) => $c->where('courses.course_family', $family));

        return Group::where('status', 'forming')->where($launchable)->where($byFamily)->latest('id')->first()
            ?? Group::where('status', 'active')->where($byFamily)->latest('id')->first();
    }
}
