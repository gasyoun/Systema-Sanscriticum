@php
    // Нормализуем входные данные — блок защищён от пустого/битого JSON
    $title = $data['title'] ?? 'Жизненные правила для санскритологов';
    $epigraph = $data['epigraph'] ?? null;
    $byline = $data['byline'] ?? null;
    $collapsedCount = max(1, (int) ($data['collapsed_count'] ?? 7));
    $items = collect($data['items'] ?? [])
        ->filter(fn ($i) => !empty($i['text']))
        ->values();
@endphp

@if($items->isNotEmpty())
<section class="py-10 lg:py-16 bg-white" x-data="{ expanded: false }">
    <div class="container mx-auto px-4">

        {{-- Заголовок секции --}}
        <div class="max-w-3xl mx-auto text-center mb-10 md:mb-14 flex flex-col items-center">
            <h2 class="text-2xl md:text-4xl font-extrabold text-[#101010] mb-4">
                {{ $title }}
            </h2>
            <div class="w-24 h-1.5 bg-[#E85C24] rounded-full"></div>

            @if($epigraph)
                <p class="mt-6 text-base md:text-lg text-gray-500 italic leading-relaxed max-w-2xl">
                    {{ $epigraph }}
                </p>
            @endif
        </div>

        {{-- Сплошной поток правил (без аккордеона — по образцу шумановских Lebensregeln) --}}
        <div class="max-w-3xl mx-auto relative">
            <ol class="space-y-6 md:space-y-7 overflow-hidden transition-all duration-500"
                :style="expanded ? '' : 'max-height: {{ $collapsedCount * 220 }}px'">
                @foreach($items as $index => $item)
                    <li class="flex gap-4 md:gap-5" @if($index >= $collapsedCount) x-show="expanded" x-cloak @endif>
                        <span class="flex-shrink-0 flex items-center justify-center w-9 h-9 md:w-10 md:h-10 rounded-full bg-orange-50 text-[#E85C24] font-extrabold text-sm md:text-base">
                            {{ $index + 1 }}
                        </span>
                        <p class="text-gray-700 text-base md:text-lg leading-relaxed pt-1">
                            {{ $item['text'] }}
                        </p>
                    </li>
                @endforeach
            </ol>

            {{-- Затухание над кнопкой, когда список свернут --}}
            <div class="absolute bottom-14 left-0 right-0 h-24 bg-gradient-to-t from-white to-transparent pointer-events-none"
                 x-show="!expanded" x-cloak></div>

            @if($items->count() > $collapsedCount)
                <div class="relative mt-6 text-center">
                    <button type="button"
                            @click="expanded = !expanded"
                            class="inline-flex items-center gap-2 px-6 py-3 rounded-full font-bold text-[#E85C24] border-2 border-[#E85C24] hover:bg-[#E85C24] hover:text-white transition-colors">
                        <span x-show="!expanded" x-cloak>Показать все {{ $items->count() }} правил</span>
                        <span x-show="expanded" x-cloak>Свернуть</span>
                    </button>
                </div>
            @endif

            @if($byline)
                <p class="mt-10 text-right text-gray-400 italic text-sm md:text-base">
                    — {{ $byline }}
                </p>
            @endif
        </div>
    </div>
</section>

<style>
    [x-cloak] { display: none !important; }
</style>
@endif
