@php
    // $rows — строки из UserResource::buildBlocksPreviewForUsers() (студент × курс).
    $willSet = collect($rows)->filter(fn ($r) => $r['will_set_entry'] || $r['will_set_exit'])->count();
    $dash = fn ($v) => filled($v) ? '№'.$v : '—';
@endphp

<div class="space-y-3 text-sm">
    @if (empty($rows))
        <p class="text-gray-500 dark:text-gray-400">У выделенных студентов нет курсов с примечаниями — разбирать нечего.</p>
    @else
        <p class="text-gray-500 dark:text-gray-400">
            Распознаём блоки входа/выхода из примечаний по всем курсам выделенных студентов. Проставим
            <span class="font-semibold text-gray-700 dark:text-gray-200">только в пустые</span>
            колонки — заполненные вручную не трогаем.
            @if ($willSet)
                Будет изменено записей: <span class="font-semibold text-success-600 dark:text-success-400">{{ $willSet }}</span>.
            @else
                <span class="text-warning-600 dark:text-warning-400">Проставлять нечего</span> — ничего не распознано или всё уже заполнено.
            @endif
        </p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Студент</th>
                        <th class="px-3 py-2">Курс</th>
                        <th class="px-3 py-2">Примечание</th>
                        <th class="px-3 py-2 text-center" colspan="3">Блок входа</th>
                        <th class="px-3 py-2 text-center" colspan="3">Блок выхода</th>
                    </tr>
                    <tr class="text-[10px]">
                        <th class="px-3 pb-1"></th>
                        <th class="px-3 pb-1"></th>
                        <th class="px-3 pb-1"></th>
                        <th class="px-3 pb-1 text-center">было</th>
                        <th class="px-3 pb-1 text-center">распознано</th>
                        <th class="px-3 pb-1 text-center">станет</th>
                        <th class="px-3 pb-1 text-center">было</th>
                        <th class="px-3 pb-1 text-center">распознано</th>
                        <th class="px-3 pb-1 text-center">станет</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-200">{{ $row['student'] }}</td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $row['title'] }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400 max-w-xs">
                                <span class="line-clamp-2">{{ $row['note'] }}</span>
                            </td>

                            {{-- Блок входа --}}
                            <td class="px-3 py-2 text-center text-gray-400">{{ $dash($row['current_entry']) }}</td>
                            <td class="px-3 py-2 text-center text-gray-500 dark:text-gray-400">{{ $dash($row['parsed_entry']) }}</td>
                            <td class="px-3 py-2 text-center">
                                @if ($row['will_set_entry'])
                                    <span class="font-semibold text-success-600 dark:text-success-400">№{{ $row['parsed_entry'] }}</span>
                                @elseif (filled($row['current_entry']))
                                    <span class="text-gray-400" title="не трогаем">№{{ $row['current_entry'] }}</span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            {{-- Блок выхода --}}
                            <td class="px-3 py-2 text-center text-gray-400">{{ $dash($row['current_exit']) }}</td>
                            <td class="px-3 py-2 text-center text-gray-500 dark:text-gray-400">{{ $dash($row['parsed_exit']) }}</td>
                            <td class="px-3 py-2 text-center">
                                @if ($row['will_set_exit'])
                                    <span class="font-semibold text-success-600 dark:text-success-400">№{{ $row['parsed_exit'] }}</span>
                                @elseif (filled($row['current_exit']))
                                    <span class="text-gray-400" title="не трогаем">№{{ $row['current_exit'] }}</span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400">
            Зелёным — что проставится. Серым — уже заполнено вручную, оставляем как есть.
            Нужно поправить число — откройте карточку студента и используйте кнопку на конкретной строке.
        </p>
    @endif
</div>
