<x-filament-panels::page>
    @php $unlinked = $this->unlinkedWaitlistItems; @endphp
    @if($unlinked->isNotEmpty())
        {{-- Позиции ждуна без курса: баннеры живут на курсе, поэтому в
             матрицу они не попадают, пока курс не привязан. --}}
        <x-filament::section icon="heroicon-o-clock" icon-color="warning" collapsible>
            <x-slot name="heading">В ждуне без привязанного курса: {{ $unlinked->count() }}</x-slot>
            <x-slot name="description">Баннеры загружаются на курс. Привяжите курс в «Списке ожидания» — и он появится в матрице.</x-slot>
            <ul class="text-sm space-y-1">
                @foreach($unlinked as $item)
                    <li>
                        <a href="{{ \App\Filament\Resources\CourseWaitlistItemResource::getUrl('edit', ['record' => $item]) }}"
                           class="text-primary-600 hover:underline">{{ $item->course_title }}</a>
                        @if(filled($item->teacher_name))<span class="text-gray-500"> · {{ $item->teacher_name }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
