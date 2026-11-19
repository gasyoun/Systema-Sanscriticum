@extends('layouts.shop')

@php
    // SEO-META-1: three language hubs shared one title/description. Give each its
    // own; the /koloda/yazyk/sanskrit alias canonicalises to the default hub.
    $langSubject = match ($lang ?? 'sa') {
        'hi' => 'хинди',
        'all' => 'санскриту и хинди',
        default => 'санскриту',
    };
    $langH1 = match ($lang ?? 'sa') {
        'hi' => 'Карточки по хинди',
        'all' => 'Карточки по санскриту и хинди',
        default => 'Карточки по санскриту',
    };
    $canonicalPath = in_array($langWord, [null, '', 'sanskrit'], true)
        ? '/koloda'
        : '/koloda/yazyk/'.$langWord;
@endphp

@section('title', 'Колоды карточек по '.$langSubject)
{{-- H5184 N14: ключевые слова в description («колоды», «санскрит», «хинди»). --}}
@section('meta_description', 'Открытая галерея колод карточек по '.$langSubject.': интервальное повторение, пробный режим без регистрации — прогресс сохранится после входа.')

@push('head')
    <link rel="canonical" href="{{ url($canonicalPath) }}">
    <meta name="robots" content="index, follow">

    @include('partials.schema-breadcrumbs', ['crumbs' => array_values(array_filter([
        ['name' => 'Главная', 'url' => url('/')],
        ['name' => 'Колоды', 'url' => url('/koloda')],
        in_array($langWord, [null, '', 'sanskrit'], true) ? null : ['name' => $langH1],
    ]))])
@endpush

@section('content')
<div class="container mx-auto px-4 py-10 max-w-3xl">
    <div class="mb-8">
        <h1 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight">{{ $langH1 }}</h1>
        <p class="text-slate-400 mt-2">
            Попробуйте колоду без регистрации — прогресс сохранится после входа.
        </p>
    </div>

    @php
        $lang = $lang ?? 'sa';
        $filters = $filters ?? ['topic' => null, 'size' => null, 'difficulty' => null];
        $taxQuery = array_filter([
            'topic' => $filters['topic'],
            'size' => $filters['size'],
            'difficulty' => $filters['difficulty'],
        ]);
        $taxQs = $taxQuery !== [] ? '?'.http_build_query($taxQuery) : '';
        $tabs = [
            'sa' => 'Санскрит',
            'hi' => 'Хинди',
            'all' => 'Все',
        ];
        $wordForLang = ['sa' => null, 'hi' => 'hindi', 'all' => 'vse'];
    @endphp
    <div class="mb-6 flex flex-wrap gap-2" role="tablist" aria-label="Язык колод">
        @foreach($tabs as $code => $label)
            @php $active = $lang === $code; $tabWord = $wordForLang[$code]; @endphp
            <a href="{{ ($tabWord ? route('srs.index.lang', ['word' => $tabWord]) : route('srs.index')).$taxQs }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-bold transition-colors
                      {{ $active
                          ? 'bg-brand text-white'
                          : 'bg-[#111622] text-slate-300 border border-[#1F2636] hover:border-brand/50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- H7966 (трек А) — discovery-фильтры таксономии: тема / размер / сложность. --}}
    <form method="GET" action="{{ url($canonicalPath) }}" class="mb-6 flex flex-wrap items-end gap-3"
          data-testid="srs-taxonomy-filters">
        @php
            $taxonomySpecs = [
                'topic' => ['label' => 'Тема', 'options' => $topicOptions ?? []],
                'size' => ['label' => 'Размер', 'options' => $sizeOptions ?? []],
                'difficulty' => ['label' => 'Сложность', 'options' => $difficultyOptions ?? []],
            ];
        @endphp
        @foreach ($taxonomySpecs as $name => $spec)
            <label class="flex flex-col gap-1 text-xs font-bold text-slate-400">
                {{ $spec['label'] }}
                <select name="{{ $name }}"
                        class="rounded-xl bg-[#111622] border border-[#1F2636] text-slate-200 text-sm px-3 py-2 focus:border-brand">
                    <option value="">— любая —</option>
                    @foreach ($spec['options'] as $value => $optionLabel)
                        <option value="{{ $value }}" @selected(($filters[$name] ?? null) === $value)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <button type="submit"
                class="px-4 py-2 rounded-xl bg-brand text-white text-sm font-bold hover:opacity-90 transition">
            Применить
        </button>
        @if (array_filter($filters))
            <a href="{{ url($canonicalPath) }}" class="px-3 py-2 rounded-xl text-sm font-bold text-slate-400 hover:text-white transition">
                Сбросить
            </a>
        @endif
    </form>

    @if($decks->isEmpty())
        <div class="rounded-2xl border border-[#1F2636] bg-[#111622] p-10 text-center text-slate-400">
            Колоды скоро появятся.
        </div>
    @else
        <ul class="space-y-3">
            @foreach($decks as $deck)
                @php
                    $segment = \App\Livewire\SrsReview::deckPathSegment($deck);
                    $langLabel = match ($deck->language) {
                        'hi' => 'Хинди',
                        'sa' => 'Санскрит',
                        default => $deck->language,
                    };
                    $taglineSubject = match ($deck->language) {
                        'hi' => 'хинди',
                        'sa' => 'санскрит',
                        default => 'слова',
                    };
                @endphp
                <li>
                    <a href="{{ url('/koloda/'.$segment) }}"
                       class="flex items-center justify-between gap-4 p-5 rounded-2xl border border-[#1F2636] bg-[#111622] hover:border-brand/50 transition-all group">
                        <div>
                            <div class="font-extrabold text-white group-hover:text-brand transition-colors">
                                {{ $deck->name }}
                            </div>
                            <div class="text-sm text-slate-400 mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-[#0A0D14] font-medium text-slate-300">{{ $langLabel }}</span>
                                <span class="ml-2">{{ $deck->cards_count }} карт.</span>
                                <span class="ml-2 text-slate-500">· учите {{ $taglineSubject }}</span>
                                @if ($deck->topic || $deck->difficulty || $deck->size_tier)
                                    <span class="ml-2 inline-flex flex-wrap gap-1 align-middle">
                                        @if ($deck->topic)
                                            <span class="px-2 py-0.5 rounded-md bg-brand/15 text-brand font-medium">{{ \App\Models\SrsDeck::TOPICS[$deck->topic] ?? $deck->topic }}</span>
                                        @endif
                                        @if ($deck->difficulty)
                                            <span class="px-2 py-0.5 rounded-md bg-[#0A0D14] text-slate-300 font-medium">{{ \App\Models\SrsDeck::DIFFICULTIES[$deck->difficulty] ?? $deck->difficulty }}</span>
                                        @endif
                                        @if ($deck->size_tier)
                                            <span class="px-2 py-0.5 rounded-md bg-[#0A0D14] text-slate-300 font-medium">{{ \App\Models\SrsDeck::SIZE_TIERS[$deck->size_tier] ?? $deck->size_tier }}</span>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span class="text-sm font-bold text-brand whitespace-nowrap">Попробовать →</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- H5184 N06 — разбор ошибок: CTA под галереей колод --}}
    <div class="mt-8">
        @include('srs.partials.error-analysis-cta')
    </div>

    <p class="mt-8 text-center text-sm text-slate-500">
        Уже учитесь?
        <a href="{{ url('/login') }}" class="text-brand font-bold hover:underline">Войти в кабинет</a>
    </p>
</div>
@endsection
