@php
    $data = \App\Filament\Resources\UserResource::attendanceCanvas($getRecord());
    $rows = $data['rows'];
@endphp

<div class="space-y-4 text-sm">
    @forelse($rows as $row)
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $row['course_title'] }}</span>
                <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                    канва: урок {{ $row['student_cursor'] }} / {{ $row['total'] }}
                </span>
            </div>

            {{-- Позиция студента против группы; две шкалы раздельны (H4435). --}}
            <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
                <div class="h-2 rounded-full bg-primary-500" style="width: {{ $row['total'] > 0 ? min(100, (int) round($row['student_cursor'] / $row['total'] * 100)) : 0 }}%"></div>
            </div>

            <p class="text-gray-600 dark:text-gray-400">
                Группа дошла до урока {{ $row['group_cursor'] }}.
                @if ($row['last_canvas'])
                    Последний факт: {{ $row['last_canvas'] }}.
                @endif
                @if ($row['lag'] > 0)
                    <span class="text-danger-600">Отставание: {{ $row['lag'] }} урок(ов) от группы.</span>
                @elseif ($row['lag'] < 0)
                    <span class="text-success-600">Опережение: {{ -$row['lag'] }} урок(ов).</span>
                @else
                    <span>Вровень с группой.</span>
                @endif
            </p>

            <details class="text-gray-600 dark:text-gray-400">
                <summary class="cursor-pointer">Последние занятия группы</summary>
                <ul class="mt-1 space-y-1">
                    @foreach ($row['sessions'] as $s)
                        <li>{{ $s['date'] }} — {{ $s['title'] }}@if($s['canvas']) · {{ $s['canvas'] }}@endif</li>
                    @endforeach
                </ul>
            </details>
        </div>
    @empty
        <p class="text-gray-400">Студент не записан на грамматические курсы с канвой.</p>
    @endforelse
</div>