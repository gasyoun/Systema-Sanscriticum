<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Файлы, прикреплённые к урокам</x-slot>
        <x-slot name="description">Еженедельные раздатки (в том числе хинди) живут здесь, а не в библиотеке ссылок.</x-slot>

        @php $lessons = $this->lessons(); @endphp

        @if ($lessons->isEmpty())
            <p class="text-sm text-gray-500">К урокам пока не прикреплено ни одного файла.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Курс</th>
                            <th class="py-2 pr-4">Урок</th>
                            <th class="py-2 pr-4">Дата</th>
                            <th class="py-2">Файлы</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lessons as $lesson)
                            <tr class="border-b border-gray-100 dark:border-white/5 align-top">
                                <td class="py-2 pr-4">{{ $lesson->course?->title ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <a href="{{ $this->lessonEditUrl($lesson) }}" class="text-primary-600 hover:underline">
                                        {{ $lesson->title }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4 tabular-nums whitespace-nowrap">
                                    {{ $lesson->lesson_date ? \Illuminate\Support\Carbon::parse($lesson->lesson_date)->format('d.m.Y') : '—' }}
                                </td>
                                <td class="py-2">
                                    <ul class="space-y-1">
                                        @foreach ($lesson->attachmentPaths() as $path)
                                            <li>
                                                <a href="{{ $lesson->attachmentPublicUrl($path) }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline">
                                                    {{ basename($path) }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
