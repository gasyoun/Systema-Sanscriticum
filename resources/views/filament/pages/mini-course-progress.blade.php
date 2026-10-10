<x-filament-panels::page>
    @if (! $miniCourse)
        <p class="text-gray-500">Мини-курс не найден (config mini_courses.slug).</p>
    @else
        <p class="text-sm text-gray-500 mb-4">
            Курс: <span class="font-bold text-gray-700">{{ $miniCourse->title }}</span>
            · студентов: <span class="font-bold text-gray-700">{{ $progressRows->count() }}</span>
        </p>
        {{ $this->table }}
    @endif
</x-filament-panels::page>
