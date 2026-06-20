<x-filament-panels::page>
    @php($yearOptions = $this->getYearOptions())

    @if (count($yearOptions))
        <div class="flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                Долги за год:
            </span>

            @foreach ($yearOptions as $year)
                @php($active = in_array($year, $selectedYears, true))
                <x-filament::button
                    wire:click="toggleYear({{ $year }})"
                    wire:loading.attr="disabled"
                    size="sm"
                    :color="$active ? 'primary' : 'gray'"
                    :outlined="! $active"
                >
                    {{ $year }}
                </x-filament::button>
            @endforeach

            <span class="ms-1 text-xs text-gray-500 dark:text-gray-400">
                @if (empty($selectedYears))
                    показаны все годы — выберите год(а), чтобы считать долги только за них
                @else
                    долги считаются только за выбранные годы (по дате блока)
                @endif
            </span>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
