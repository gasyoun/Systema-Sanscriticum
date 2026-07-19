<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Планировочные шаблоны «Нескучных финансов»</x-slot>
        <x-slot name="description">
            Отчеты факта (ОПиУ, ДДС, баланс) считаются автоматически в «Финансовом штурвале».
            Планирование — сценарии, бюджет — ведется вручную в этих workbooks.
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach ($this->templates() as $tpl)
                <div class="flex flex-col justify-between rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div>
                        <div class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                            <x-filament::icon icon="heroicon-o-table-cells" class="h-5 w-5 text-primary-500" />
                            {{ $tpl['title'] }}
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $tpl['note'] }}</p>
                    </div>
                    <a
                        href="{{ route('finance.template', ['name' => $tpl['key']]) }}"
                        class="mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500"
                    >
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                        Скачать .xlsx
                    </a>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
