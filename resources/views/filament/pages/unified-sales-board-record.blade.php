{{--
    Карточка сводной доски продаж (F9, H1658).

    Отличия от filament-kanban::kanban-record:
      * id составной («lead-12» / «deal-12») — числовые ключи заявок и сделок
        пересекаются, а drag-drop опознаёт карточку именно по id элемента;
      * бейдж «Заявка»/«Сделка» — иначе две сущности в одной колонке
        неразличимы;
      * нет wire:click — модалка правки на этой доске выключена, и общей формы
        для двух разных сущностей всё равно нет.
--}}
@php
    $isLead = $record instanceof \App\Models\Lead;
@endphp

<div
    id="{{ static::cardId($record) }}"
    class="record bg-white dark:bg-gray-700 rounded-lg px-4 py-2 cursor-grab font-medium text-gray-600 dark:text-gray-200"
>
    <span
        @class([
            'inline-block mb-1 px-2 py-0.5 rounded-md text-xs font-semibold',
            'bg-primary-100 text-primary-700 dark:bg-primary-800 dark:text-primary-100' => $isLead,
            'bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-100' => ! $isLead,
        ])
    >
        {{ static::kindLabel($record) }}
    </span>

    <div>{{ $record->{static::$recordTitleAttribute} }}</div>
</div>
