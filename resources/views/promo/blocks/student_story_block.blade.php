{{--
    Блок «Истории учеников» — нарративный пруф (путь ученика: крючок → путь →
    результат-цифра), с честным анти-кейс-вариантом «кому курс НЕ зайдёт».
    Формат перенят у кейсов «Нескучных финансов» (аудит:
    Uprava/SYSTEMA_MARKETING_AUDIT_vs_noboring.md §5). Отдельный блок, а не мутация
    reviews_block — чтобы не ломать существующие лендинги с отзывами.
--}}
<section class="py-16 lg:py-24 bg-white relative font-nunito overflow-hidden" id="istorii">

    {{-- Фоновый декор --}}
    <div class="absolute top-1/3 right-0 w-[40rem] h-[40rem] bg-orange-50 rounded-full blur-[100px] opacity-60 -translate-y-1/2 translate-x-1/4 pointer-events-none z-0"></div>

    <div class="container mx-auto px-4 relative z-10">

        {{-- ЗАГОЛОВОК --}}
        <div class="max-w-3xl mx-auto text-center flex flex-col items-center mb-14 lg:mb-20">
            <h2 class="text-2xl md:text-3xl lg:text-4xl font-extrabold text-[#101010] mb-4 tracking-tight">
                {{ $data['title'] ?? 'Истории учеников' }}
            </h2>
            @if(!empty($data['subtitle']))
                <p class="text-gray-500 text-base md:text-lg mb-5 leading-relaxed">{{ $data['subtitle'] }}</p>
            @endif
            <div class="w-20 h-1.5 bg-brand rounded-full mx-auto"></div>
        </div>

        @if(!empty($data['stories']))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8 max-w-6xl mx-auto">

            @foreach($data['stories'] as $story)
                @php
                    // Аватар: Curator хранит ID → резолвим в URL; иначе — путь в Storage.
                    $rawAvatar = $story['avatar'] ?? null;
                    $avatarUrl = null;
                    if ($rawAvatar) {
                        $avatarUrl = is_numeric($rawAvatar)
                            ? \Awcodes\Curator\Models\Media::find($rawAvatar)?->url
                            : \Illuminate\Support\Facades\Storage::url($rawAvatar);
                    }
                @endphp

                {{-- КАРТОЧКА ИСТОРИИ --}}
                <article class="relative bg-white rounded-3xl p-7 md:p-9 border border-gray-100 shadow-[0_8px_30px_rgba(0,0,0,0.04)] hover:shadow-[0_20px_45px_rgba(232,92,36,0.10)] hover:border-brand/20 transition-all duration-500 flex flex-col overflow-hidden group">

                    {{-- Дата + трек (релевантность + свежесть, как у их кейсов) --}}
                    @if(!empty($story['date']) || !empty($story['track']))
                        <div class="flex flex-wrap items-center gap-2 mb-5">
                            @if(!empty($story['date']))
                                <span class="text-[11px] font-extrabold text-gray-400 uppercase tracking-widest tabular-nums">{{ $story['date'] }}</span>
                            @endif
                            @if(!empty($story['date']) && !empty($story['track']))
                                <span class="text-gray-300">·</span>
                            @endif
                            @if(!empty($story['track']))
                                <span class="inline-flex items-center px-3 py-1 rounded-full bg-orange-50 text-[#8A3D17] text-[11px] font-extrabold uppercase tracking-wide">{{ $story['track'] }}</span>
                            @endif
                        </div>
                    @endif

                    {{-- Лицо: аватар + имя + город (доверие к человеку) --}}
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-14 h-14 rounded-full border-2 border-white shadow-md bg-gray-100 overflow-hidden shrink-0">
                            @if($avatarUrl)
                                <img src="{{ $avatarUrl }}" alt="{{ $story['name'] ?? '' }}" loading="lazy" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-orange-100 to-orange-50 text-brand font-black text-xl">
                                    {{ mb_substr($story['name'] ?? '?', 0, 1) }}
                                </div>
                            @endif
                        </div>
                        <div>
                            @if(!empty($story['name']))
                                <h3 class="font-extrabold text-[#101010] text-lg leading-tight">{{ $story['name'] }}</h3>
                            @endif
                            @if(!empty($story['city']))
                                <p class="text-gray-400 text-sm mt-0.5">{{ $story['city'] }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- Крючок-проблема (узнавание своей боли) --}}
                    @if(!empty($story['hook']))
                        <p class="text-[#8A3D17] font-extrabold text-lg md:text-xl leading-snug tracking-tight mb-4">
                            «{{ $story['hook'] }}»
                        </p>
                    @endif

                    {{-- Путь: что было трудно и как помогли (демо метода) --}}
                    @if(!empty($story['path']))
                        <p class="text-gray-600 text-[15px] leading-relaxed mb-5 flex-grow">
                            {{ $story['path'] }}
                        </p>
                    @endif

                    {{-- Результат-цифра (конкретика вместо «улучшили») --}}
                    @if(!empty($story['result']))
                        <div class="flex items-start gap-3 rounded-2xl bg-gradient-to-br from-orange-50 to-orange-100/50 ring-1 ring-orange-100 p-4 mt-auto">
                            <svg class="w-6 h-6 text-brand shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7" /></svg>
                            <p class="text-[#101010] font-bold text-[15px] md:text-base leading-snug">{{ $story['result'] }}</p>
                        </div>
                    @endif

                    {{-- Честность: анти-кейс «кому курс НE зайдёт» (сильнее всего строит доверие) --}}
                    @if(!empty($story['not_for']))
                        <div class="flex items-start gap-2.5 mt-4 pt-4 border-t border-dashed border-gray-200">
                            <svg class="w-5 h-5 text-gray-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <p class="text-gray-500 text-sm leading-relaxed">
                                <span class="font-bold text-gray-600">Кому не зайдет:</span> {{ $story['not_for'] }}
                            </p>
                        </div>
                    @endif

                </article>
            @endforeach

        </div>
        @endif

    </div>
</section>
