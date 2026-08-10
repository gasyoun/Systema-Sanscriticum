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
        $tabs = [
            'sa' => 'Санскрит',
            'hi' => 'Хинди',
            'all' => 'Все',
        ];
    @endphp
    <div class="mb-6 flex flex-wrap gap-2" role="tablist" aria-label="Язык колод">
        @foreach($tabs as $code => $label)
            @php $active = $lang === $code; @endphp
            <a href="{{ url('/dvaram/koloda').($code === 'sa' ? '' : '?lang='.$code) }}"
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
