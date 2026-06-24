{{-- Ответ преподавателя по ДЗ: работы, которые вернули на доработку. --}}
@if (! empty($homeworkAlerts) && $homeworkAlerts->isNotEmpty())
    <div class="mb-6 rounded-2xl border border-red-100 bg-red-50/60 shadow-sm overflow-hidden">
        <div class="px-5 sm:px-6 py-4">
            <h3 class="text-base font-extrabold text-red-800 flex items-center gap-2">
                <i class="fas fa-rotate-left text-red-500"></i>
                Преподаватель вернул работу на доработку
                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-xs font-bold">
                    {{ $homeworkAlerts->count() }}
                </span>
            </h3>
            <ul class="mt-3 space-y-2">
                @foreach ($homeworkAlerts as $alert)
                    <li>
                        <a href="{{ route('student.lesson', [$alert->course->slug, $alert->lesson_id]) }}"
                           class="flex items-center justify-between gap-3 rounded-xl bg-white border border-red-100 px-4 py-2.5 hover:border-red-300 transition-colors group">
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-gray-800 truncate">{{ $alert->lesson->title }}</span>
                                <span class="block text-xs text-gray-500 truncate">{{ $alert->course->title }}</span>
                            </span>
                            <span class="shrink-0 inline-flex items-center gap-1 text-red-600 text-xs font-bold">
                                Открыть <i class="fas fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
