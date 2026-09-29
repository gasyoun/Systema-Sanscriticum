<x-filament-panels::page>
    @unless ($this->isEnabled())
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-600 dark:bg-warning-950 dark:text-warning-200">
            Плашки выключены (<code>LESSON_BANNERS=false</code>): шаблоны заводить и смотреть превью можно,
            ночной прогон и API для n8n не работают.
        </div>
    @endunless

    <x-filament::section heading="Шаблоны" description="Активный шаблон на курс (или на группу). Превью — пробная дата завтра и №12.">
        @php($templates = $this->templates())

        @if ($templates->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Шаблонов еще нет — «Новый шаблон» вверху справа.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pe-4">Курс</th>
                            <th class="py-2 pe-4">Группа</th>
                            <th class="py-2 pe-4">Версия</th>
                            <th class="py-2 pe-4">Размер</th>
                            <th class="py-2 pe-4">Шрифты</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($templates as $template)
                            <tr>
                                <td class="py-2 pe-4">{{ $template->course?->title ?? '—' }}</td>
                                <td class="py-2 pe-4">{{ $template->group?->name ?? 'весь курс' }}</td>
                                <td class="py-2 pe-4">v{{ $template->version }}</td>
                                <td class="py-2 pe-4">{{ $template->width }}×{{ $template->height }}</td>
                                @php($missingFonts = \App\Services\Banners\LessonBannerTemplateStore::missingFonts($template))
                                <td class="py-2 pe-4">
                                    {{ collect(\App\Models\LessonBannerTemplate::FIELDS)->map(fn ($f) => $template->field($f)['font'] ?? '—')->unique()->implode(', ') }}
                                    @if ($missingFonts)
                                        <div class="text-xs text-danger-600 dark:text-danger-400">не загружены: {{ implode(', ', $missingFonts) }} — рисуется запасным</div>
                                    @endif
                                </td>
                                <td class="py-2 text-end">
                                    <x-filament::button size="sm" color="gray" wire:click="preview({{ $template->id }})">
                                        Превью
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($previewDataUri)
            <div class="mt-4">
                <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ $previewCaption }}</p>
                <img src="{{ $previewDataUri }}" alt="Превью плашки" class="max-w-full rounded-lg border border-gray-200 dark:border-white/10" style="max-height: 360px">
            </div>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
