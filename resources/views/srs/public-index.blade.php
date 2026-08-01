@extends('layouts.shop')

@section('title', 'Карточки — интервальные повторения')

@section('content')
<div class="container mx-auto px-4 py-10 max-w-3xl">
    <div class="mb-8">
        <h1 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight">Карточки</h1>
        <p class="text-slate-400 mt-2">
            Попробуйте колоду без регистрации — прогресс сохранится после входа.
        </p>
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
            <a href="{{ url('/koloda').($code === 'sa' ? '' : '?lang='.$code) }}"
               role="tab"
               aria-selected="{{ $active ? 'true' : 'false' }}"
               class="inline-flex items-center px-4 py-2 rounded-xl text-sm font-bold transition-colors
                      {{ $active
                          ? 'bg-[#E85C24] text-white'
                          : 'bg-[#111622] text-slate-300 border border-[#1F2636] hover:border-[#E85C24]/50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

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
                       class="flex items-center justify-between gap-4 p-5 rounded-2xl border border-[#1F2636] bg-[#111622] hover:border-[#E85C24]/50 transition-all group">
                        <div>
                            <div class="font-extrabold text-white group-hover:text-[#E85C24] transition-colors">
                                {{ $deck->name }}
                            </div>
                            <div class="text-sm text-slate-400 mt-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-[#0A0D14] font-medium text-slate-300">{{ $langLabel }}</span>
                                <span class="ml-2">{{ $deck->cards_count }} карт.</span>
                                <span class="ml-2 text-slate-500">· учите {{ $taglineSubject }}</span>
                            </div>
                        </div>
                        <span class="text-sm font-bold text-[#E85C24] whitespace-nowrap">Попробовать →</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-8 text-center text-sm text-slate-500">
        Уже учитесь?
        <a href="{{ url('/login') }}" class="text-[#E85C24] font-bold hover:underline">Войти в кабинет</a>
    </p>
</div>
@endsection
