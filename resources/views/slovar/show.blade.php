@extends('layouts.slovar')

@php
    $canonical = route('slovar.show', $slug);
    $headword = $primary->headword();
    $metaDesc = \Illuminate\Support\Str::limit(trim(strip_tags((string) $primary->translation)), 160);
@endphp

@section('title', $headword.' — значение и перевод на русский | Словарь санскрита')
@section('meta_description', $headword.' ('.($primary->cyrillic ?: $primary->iast).'): '.$metaDesc)
@section('og_title', $headword.' — санскритско-русский словарь')
@section('canonical', $canonical)
{{-- Wave 0: всё noindex,follow. Как только index_enabled и слово проходит curated-гейт — index. --}}
@section('robots', $indexable ? 'index, follow' : 'noindex, follow')

@push('head')
    @include('partials.schema-defined-term', ['primary' => $primary, 'sameAs' => $sameAs, 'canonical' => $canonical, 'enrichment' => $enrichment ?? null])

    @include('partials.schema-breadcrumbs', ['crumbs' => [
        ['name' => 'Главная', 'url' => url('/')],
        ['name' => 'Словарь', 'url' => route('slovar.index')],
        ['name' => $headword],
    ]])
@endpush

@section('content')
    {{-- ═════ Заголовочное слово ═════ --}}
    <header class="text-center mb-12">
        @if($primary->devanagari)
            <div class="deva text-6xl md:text-7xl text-white mb-3 leading-none">{{ $primary->devanagari }}</div>
        @endif
        <h1 class="text-3xl md:text-4xl font-extrabold text-brand tracking-tight">{{ $headword }}</h1>
        <div class="mt-2 flex flex-wrap justify-center gap-x-4 gap-y-1 text-gray-400 text-sm">
            @if($primary->iast)<span>IAST: <span lang="sa-Latn">{{ $primary->iast }}</span></span>@endif
            @if($primary->cyrillic)<span>кириллица: {{ $primary->cyrillic }}</span>@endif
        </div>
    </header>

    {{-- ═════ Корпусные значения (H344, за фича-флагом slovar_enrichment) ═════ --}}
    @if(!empty($enrichment))
        <div class="max-w-3xl mx-auto mb-8">
            <section class="bg-[#12321f]/40 border border-emerald-800/50 rounded-2xl p-6">
                <div class="flex items-baseline justify-between gap-4 mb-3">
                    <h2 class="text-sm font-black uppercase tracking-widest text-emerald-500">Корпусные значения</h2>
                    @if($enrichment['pos_label'])
                        <span class="text-xs text-emerald-400/80 shrink-0">{{ $enrichment['pos_label'] }}</span>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($enrichment['glosses'] as $gloss)
                        <span class="px-3 py-1.5 rounded-lg bg-emerald-900/40 text-emerald-100 text-sm">{{ $gloss }}</span>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500">
                    По данным корпуса санскрита (DCS){{ $enrichment['freq'] ? ', встречаемость: '.number_format($enrichment['freq'], 0, '.', ' ') : '' }}@if($enrichment['slp1']) · SLP1: <span lang="sa-Latn">{{ $enrichment['slp1'] }}</span>@endif.
                </p>
            </section>
        </div>
    @endif

    {{-- ═════ Толкования по словарям (каноническая сборка, решение D3) ═════ --}}
    <div class="space-y-4 max-w-3xl mx-auto">
        @foreach($entries->sortByDesc(fn ($w) => mb_strlen((string) $w->translation)) as $entry)
            <article class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
                <div class="flex items-baseline justify-between gap-4 mb-2">
                    <h2 class="text-sm font-black uppercase tracking-widest text-gray-500">
                        {{ $entry->dictionary?->name ?? 'Словарь' }}
                    </h2>
                    @if($entry->page)
                        <span class="text-xs text-gray-600 shrink-0">{{ $entry->page }}</span>
                    @endif
                </div>
                <div class="text-gray-200 leading-relaxed">{{ $entry->translation }}</div>
            </article>
        @endforeach
    </div>

    {{-- ═════ Кёльнские словари (CDSL link-out, H3762 wave 1) ═════ --}}
    @include('partials.slovar-cdsl-links', ['cdslLinks' => $cdslLinks ?? []])

    {{-- ═════ Внешние соответствия (sameAs, решение D4 — только при наличии) ═════ --}}
    @if($sameAs->isNotEmpty())
        <div class="max-w-3xl mx-auto mt-6 text-sm text-gray-400">
            <span class="font-bold text-gray-500 uppercase tracking-widest text-xs">Соответствия:</span>
            @foreach($sameAs as $link)
                <a href="{{ $link }}" rel="nofollow noopener" target="_blank" class="text-[#2AABEE] hover:underline ml-2">Wikidata</a>
            @endforeach
        </div>
    @endif

    {{-- ═════ Смежные слова (внутренняя перелинковка) ═════ --}}
    @if($related->isNotEmpty())
        <section class="max-w-3xl mx-auto mt-14">
            <h2 class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Смежные слова</h2>
            <div class="flex flex-wrap gap-2">
                @foreach($related as $rel)
                    <a href="{{ route('slovar.show', $rel->slug) }}"
                       class="px-3 py-1.5 rounded-lg bg-gray-800 text-gray-300 text-sm hover:bg-gray-700 hover:text-white transition-colors">
                        {{ $rel->iast ?: $rel->devanagari ?: $rel->cyrillic }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ═════ Рост-идея #6: «разобрать это слово на курсе» — тихий CTA без дедлайнов ═════ --}}
    <section class="max-w-3xl mx-auto mt-10">
        <a href="{{ route('shop.index') }}"
           class="block bg-[#161b28] border border-gray-700/60 rounded-2xl p-6 hover:border-brand transition-colors group">
            <div class="font-bold text-white">Хотите читать такие слова сами?</div>
            <div class="text-sm text-gray-400 mt-1">
                На курсах Общества ревнителей санскрита санскрит разбирают с нуля — от алфавита до текстов.
                <span class="text-brand group-hover:underline">Посмотреть курсы</span>
            </div>
        </a>
    </section>

    <div class="max-w-3xl mx-auto mt-14 pt-8 border-t border-gray-800">
        <a href="{{ route('slovar.index') }}" class="text-sm font-bold text-gray-400 hover:text-brand transition-colors">
            <i class="fas fa-arrow-left mr-2"></i> Ко всем словам
        </a>
    </div>
@endsection
