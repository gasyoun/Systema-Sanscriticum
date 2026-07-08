@extends('layouts.shop')

@section('title', 'Материалы — статьи, беседы и открытые уроки о санскрите')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 right-1/4 w-96 h-96 bg-[#E85C24]/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/3 left-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10"
         x-data="{ filter: 'all' }">

        {{-- HERO --}}
        <div class="text-center mb-12">
            <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-4">Бесплатно</p>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-6">
                Материалы
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed">
                Статьи, беседы и открытые уроки о санскрите — читайте и смотрите
                бесплатно, а когда захотите большего, курсы рядом.
            </p>
        </div>

        {{-- ФИЛЬТР ПО ТИПУ (клиентский, Alpine — контента немного) --}}
        @if($cards->isNotEmpty())
            <div class="flex flex-wrap justify-center gap-2 mb-10" data-analytics="materials-filter">
                @foreach([
                    'all' => ['label' => 'Все', 'count' => $cards->count()],
                    'text' => ['label' => 'Тексты', 'count' => $counts['text']],
                    'video' => ['label' => 'Видео', 'count' => $counts['video']],
                    'lesson' => ['label' => 'Уроки', 'count' => $counts['lesson']],
                ] as $key => $chip)
                    @if($chip['count'] > 0)
                        <button type="button"
                                @click="filter = '{{ $key }}'"
                                class="px-4 py-2 rounded-xl text-xs font-bold border transition-all cursor-pointer"
                                :class="filter === '{{ $key }}'
                                    ? 'bg-[#E85C24] border-[#E85C24] text-white shadow-[0_0_15px_rgba(232,92,36,0.3)]'
                                    : 'bg-[#111622] border-[#1F2636] text-slate-300 hover:border-[#E85C24]/50 hover:text-white'">
                            {{ $chip['label'] }}
                            <span class="ml-1 opacity-60">{{ $chip['count'] }}</span>
                        </button>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- СЕТКА КАРТОЧЕК --}}
        @if($cards->isNotEmpty())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-16 lg:mb-20" data-analytics="materials-grid">
                @foreach($cards as $card)
                    @php
                        $badge = match($card['type']) {
                            'text' => ['label' => 'Текст', 'icon' => 'fa-file-lines', 'classes' => 'bg-sky-500/15 border-sky-500/30 text-[#38BDF8]'],
                            'video' => ['label' => 'Видео', 'icon' => 'fa-play-circle', 'classes' => 'bg-indigo-500/15 border-indigo-500/30 text-indigo-400'],
                            'lesson' => ['label' => 'Урок', 'icon' => 'fa-graduation-cap', 'classes' => 'bg-emerald-500/15 border-emerald-500/30 text-emerald-400'],
                        };
                    @endphp

                    <div class="flex flex-col bg-[#111622] rounded-2xl border border-[#1F2636] hover:border-[#E85C24]/50 hover:shadow-[0_0_30px_rgba(232,92,36,0.05)] transition-all duration-300 group overflow-hidden"
                         x-show="filter === 'all' || filter === '{{ $card['type'] }}'"
                         data-analytics="materials-card">

                        {{-- Обложка --}}
                        <a href="{{ $card['url'] }}" @if($card['external']) target="_blank" rel="noopener noreferrer" @endif
                           class="relative block aspect-video bg-gradient-to-br from-slate-800 to-[#0A0D14] overflow-hidden">
                            @if($card['cover'])
                                <img src="{{ $card['cover'] }}"
                                     alt="{{ $card['title'] }}"
                                     loading="lazy"
                                     class="absolute inset-0 w-full h-full object-cover opacity-80 group-hover:scale-105 transition-transform duration-700">
                                <div class="absolute inset-0 bg-gradient-to-t from-[#111622] via-transparent to-transparent opacity-80"></div>
                            @else
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <i class="fas {{ $badge['icon'] }} text-4xl text-white/10"></i>
                                    <i class="fas fa-om absolute -right-4 -bottom-5 text-[6rem] text-white/5 pointer-events-none"></i>
                                </div>
                            @endif

                            {{-- Бейдж типа --}}
                            <span class="absolute top-3 left-3 inline-flex items-center gap-1.5 border {{ $badge['classes'] }} text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md tracking-wider backdrop-blur-sm">
                                <i class="fas {{ $badge['icon'] }} text-[9px]"></i>
                                {{ $badge['label'] }}
                            </span>

                            {{-- Чип длительности / времени чтения --}}
                            @if($card['meta'])
                                <span class="absolute bottom-3 right-3 px-2 py-0.5 text-xs font-semibold text-white bg-black/70 backdrop-blur-sm rounded-md tabular-nums">
                                    {{ $card['meta'] }}
                                </span>
                            @endif

                            @if($card['type'] === 'video')
                                <div class="absolute inset-0 flex items-center justify-center bg-transparent group-hover:bg-black/30 transition-colors">
                                    <span class="w-12 h-12 rounded-full bg-[#E85C24] flex items-center justify-center shadow-[0_0_25px_rgba(232,92,36,0.55)] transform transition-transform group-hover:scale-110">
                                        <svg class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </span>
                                </div>
                            @endif
                        </a>

                        {{-- Тело карточки --}}
                        <div class="p-5 flex flex-col flex-grow">
                            @if($card['subtitle'])
                                <p class="text-[#38BDF8] text-[10px] font-black uppercase tracking-widest mb-2 line-clamp-1">
                                    {{ $card['subtitle'] }}
                                </p>
                            @endif

                            <a href="{{ $card['url'] }}" @if($card['external']) target="_blank" rel="noopener noreferrer" @endif class="block">
                                <h2 class="text-lg font-bold text-white leading-tight line-clamp-2 group-hover:text-[#E85C24] transition-colors mb-2">
                                    {{ $card['title'] }}
                                </h2>
                            </a>

                            @if($card['excerpt'])
                                <p class="text-sm text-slate-400 leading-relaxed line-clamp-2 mb-4">{{ $card['excerpt'] }}</p>
                            @endif

                            {{-- Воронка: основное действие + funnel-CTA --}}
                            <div class="mt-auto pt-4 border-t border-[#1F2636]/60 flex items-center justify-between gap-3">
                                <a href="{{ $card['url'] }}" @if($card['external']) target="_blank" rel="noopener noreferrer" @endif
                                   class="text-xs font-bold text-white hover:text-[#E85C24] transition-colors">
                                    {{ $card['type'] === 'text' ? 'Читать' : 'Смотреть' }} →
                                </a>
                                <a href="{{ $card['cta']['url'] }}"
                                   class="text-[11px] font-bold text-slate-500 hover:text-[#38BDF8] transition-colors">
                                    {{ $card['cta']['label'] }}
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Пустой хаб: редактор ещё не опубликовал материалы — не тупик, ведём в каталог --}}
            <div class="text-center rounded-2xl bg-[#111622] border border-[#1F2636] px-6 py-16 mb-16 lg:mb-20">
                <i class="fas fa-book-open text-3xl text-slate-600 mb-4"></i>
                <p class="text-slate-400 mb-6">Раздел наполняется — бесплатные статьи и уроки появятся здесь совсем скоро.</p>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#E85C24] hover:bg-[#E85C24]/85 text-white text-sm font-bold rounded-xl transition-all">
                    Смотреть курсы <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        @endif

        {{-- ЛЕСЕНКА: бесплатное попробовали — вот следующие ступени --}}
        @include('shop.partials.ladder', $ladder)

    </div>
</div>
@endsection
