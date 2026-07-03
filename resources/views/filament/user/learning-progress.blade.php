@php
    $data = \App\Filament\Resources\UserResource::learningProgress($getRecord());
    $courses = $data['courses'];
    $hw = $data['homework'];
@endphp

<div class="space-y-4 text-sm">
    {{-- Прогресс по курсам --}}
    <div class="space-y-3">
        @forelse($courses as $c)
            @php
                $percent = $c['percent'];
                $barColor = $percent >= 100 ? 'bg-success-500' : ($percent > 0 ? 'bg-primary-500' : 'bg-gray-300 dark:bg-gray-600');
            @endphp
            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="font-medium text-gray-700 dark:text-gray-200 truncate pr-2">{{ $c['title'] }}</span>
                    <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $c['completed'] }} / {{ $c['total'] }} · {{ $percent }}%
                    </span>
                </div>
                <div class="h-2 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
                    <div class="h-2 rounded-full {{ $barColor }}" style="width: {{ $percent }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-gray-400">Студент не записан ни на один курс.</p>
        @endforelse
    </div>

    {{-- Сводка домашних работ --}}
    <div class="flex flex-wrap gap-2 pt-1">
        <span class="text-gray-500 dark:text-gray-400">Домашние работы:</span>
        <span class="inline-flex items-center gap-1 rounded-md bg-info-50 dark:bg-info-500/10 px-2 py-0.5 text-info-700 dark:text-info-300">
            На проверке: {{ $hw['submitted'] }}
        </span>
        <span class="inline-flex items-center gap-1 rounded-md bg-danger-50 dark:bg-danger-500/10 px-2 py-0.5 text-danger-700 dark:text-danger-300">
            На доработку: {{ $hw['needs_revision'] }}
        </span>
        <span class="inline-flex items-center gap-1 rounded-md bg-success-50 dark:bg-success-500/10 px-2 py-0.5 text-success-700 dark:text-success-300">
            Принято: {{ $hw['accepted'] }}
        </span>
        <span class="inline-flex items-center gap-1 rounded-md bg-gray-50 dark:bg-white/10 px-2 py-0.5 text-gray-700 dark:text-gray-300">
            Файлы: {{ $hw['size_label'] }}
        </span>
    </div>
</div>
