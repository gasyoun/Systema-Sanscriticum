@extends('layouts.student')

@section('title', 'Карточки')
@section('header', 'Карточки')

@section('content')
<div class="font-nunito max-w-3xl mx-auto px-4 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-extrabold text-[#101010] tracking-tight">Колоды</h1>
        <p class="text-gray-500 mt-1">Выберите колоду — у каждой свой адрес, чтобы делиться и возвращаться.</p>
    </div>

    <div class="mb-6 flex flex-wrap gap-3">
        <a href="{{ url('/dvaram/koloda/decks') }}"
           class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors">
            Мои колоды
        </a>
        <a href="{{ url('/dvaram/koloda/stats') }}"
           class="inline-flex items-center justify-center px-4 py-3 rounded-xl bg-gray-50 text-gray-700 font-bold hover:bg-gray-100 transition-colors">
            Статистика
        </a>
    </div>

    @php
        $lang = $lang ?? 'sa';
        $filters = $filters ?? ['topic' => null, 'size' => null, 'difficulty' => null];
        $tabs = [
            'sa' => 'Санскрит',
            'hi' => 'Хинди',
            'all' => 'Все',
        ];
    @endphp
    <div class="mb-6 flex flex-wrap gap-2" role="tablist" aria-label="Язык колод">
        @foreach($tabs as $code => $label)
            @php
                $active = $lang === $code;
                $tabQuery = array_filter(array_merge($filters, ['lang' => $code === 'sa' ? null : $code]));
                $tabQs = $tabQuery !== [] ? '?'.http_build_query($tabQuery) : '';
            @endphp
            <a href="{{ url('/dvaram/koloda').$tabQs }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-bold transition-colors
                      {{ $active
                          ? 'bg-brand text-white'
                          : 'bg-gray-50 text-gray-700 border border-gray-100 hover:border-brand/40' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- H7966 (трек А) — discovery-фильтры таксономии в кабинетном хабе. --}}
    <form method="GET" action="{{ url('/dvaram/koloda') }}" class="mb-6 flex flex-wrap items-end gap-3"
          data-testid="srs-taxonomy-filters">
        <input type="hidden" name="lang" value="{{ $lang }}">
        @php
            $taxonomySpecs = [
                'topic' => ['label' => 'Тема', 'options' => $topicOptions ?? []],
                'size' => ['label' => 'Размер', 'options' => $sizeOptions ?? []],
                'difficulty' => ['label' => 'Сложность', 'options' => $difficultyOptions ?? []],
            ];
        @endphp
        @foreach ($taxonomySpecs as $name => $spec)
            <label class="flex flex-col gap-1 text-xs font-bold text-gray-500">
                {{ $spec['label'] }}
                <select name="{{ $name }}"
                        class="rounded-xl bg-white border border-gray-200 text-gray-700 text-sm px-3 py-2 focus:border-brand">
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
            <a href="{{ url('/dvaram/koloda').'?lang='.urlencode($lang) }}" class="px-3 py-2 rounded-xl text-sm font-bold text-gray-400 hover:text-gray-700 transition">
                Сбросить
            </a>
        @endif
    </form>

    @if($decks->isEmpty())
        <div class="text-center py-16 bg-white rounded-[2rem] border border-dashed border-gray-200">
            <p class="text-gray-500">Пока нет доступных колод.</p>
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
                @endphp
                <li>
                    <a href="{{ url('/dvaram/koloda/'.$segment) }}"
                       class="flex items-center justify-between gap-4 p-5 bg-white rounded-2xl border border-gray-100 shadow-sm hover:border-brand/40 hover:shadow-md transition-all group">
                        <div>
                            <div class="font-extrabold text-gray-900 group-hover:text-brand transition-colors">
                                {{ $deck->name }}
                            </div>
                            <div class="text-sm text-gray-500 mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-50 font-medium">{{ $langLabel }}</span>
                                <span class="ml-2">{{ $deck->cards_count }} карт.</span>
                                @if ($deck->topic || $deck->difficulty || $deck->size_tier)
                                    <span class="ml-2 inline-flex flex-wrap gap-1 align-middle">
                                        @if ($deck->topic)
                                            <span class="px-2 py-0.5 rounded-md bg-brand/10 text-brand font-medium">{{ \App\Models\SrsDeck::TOPICS[$deck->topic] ?? $deck->topic }}</span>
                                        @endif
                                        @if ($deck->difficulty)
                                            <span class="px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 font-medium">{{ \App\Models\SrsDeck::DIFFICULTIES[$deck->difficulty] ?? $deck->difficulty }}</span>
                                        @endif
                                        @if ($deck->size_tier)
                                            <span class="px-2 py-0.5 rounded-md bg-gray-100 text-gray-600 font-medium">{{ \App\Models\SrsDeck::SIZE_TIERS[$deck->size_tier] ?? $deck->size_tier }}</span>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-gray-300 group-hover:text-brand"></i>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
