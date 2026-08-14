@extends('layouts.shop')

@section('title', $page->title)

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10">

        <header class="text-center mb-14">
            <p class="text-sm font-semibold tracking-wide text-[#38BDF8] mb-4">Путь через каталог</p>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-6">
                Три направления, не стена из потоков
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed">
                В каталоге больше тридцати потоков. Здесь они собраны в путь:
                письмо и чтение, грамматика, затем тексты. Дата ближайшего старта
                и цена — те же, что на карточке курса в магазине.
            </p>
        </header>

        <section class="mb-16 lg:mb-20" data-analytics="pathway-tracks">
            <ol class="grid grid-cols-1 md:grid-cols-3 gap-5 list-none p-0 m-0">
                @foreach($tracks as $i => $track)
                    <li class="relative flex flex-col h-full rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 p-6 transition-all">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="w-8 h-8 shrink-0 rounded-full bg-brand/15 text-brand text-sm font-extrabold flex items-center justify-center">
                                {{ $i + 1 }}
                            </span>
                            <h2 class="text-lg font-bold text-white leading-tight">{{ $track['title'] }}</h2>
                        </div>

                        <p class="text-sm text-slate-400 leading-relaxed mb-4 grow">{{ $track['description'] }}</p>

                        @if($track['nextDateLabel'])
                            <p class="text-sm font-semibold text-[#38BDF8] mb-2">{{ $track['nextDateLabel'] }}</p>
                        @endif

                        @if($track['priceLabel'])
                            <p class="text-sm font-bold text-white mb-4">{{ $track['priceLabel'] }}</p>
                        @endif

                        <div class="mt-auto flex flex-col gap-2 pt-3 border-t border-[#1F2636]">
                            @if($track['freeUrl'])
                                <a href="{{ $track['freeUrl'] }}"
                                   class="text-sm font-bold text-[#38BDF8] hover:text-white transition-colors">
                                    Бесплатный первый шаг
                                </a>
                            @endif
                            <a href="{{ $track['url'] }}"
                               class="inline-flex justify-center items-center w-full py-3 px-4 bg-brand hover:bg-brand/85 text-white text-sm font-bold rounded-xl transition-all">
                                {{ $track['course'] ? 'К курсу' : 'Смотреть каталог' }}
                            </a>
                        </div>

                        @if($i < count($tracks) - 1)
                            <div class="hidden md:flex absolute top-1/2 -right-4 -translate-y-1/2 z-10 text-slate-600" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="mb-8 text-center" data-analytics="pathway-catalog">
            <p class="text-slate-400 mb-4">Нужен конкретный поток, а не направление?</p>
            <a href="{{ route('shop.index') }}"
               class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-brand/60 hover:bg-brand/5 text-white text-sm font-bold rounded-xl transition-all">
                Весь каталог курсов
            </a>
        </section>

    </div>
</div>
@endsection
