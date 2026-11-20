@extends('layouts.articles')

@section('title', $bundle['title'] . ' — материалы к курсу Кочергиной')
@section('meta_description', 'Открытый набор материалов этапа «' . $bundle['title'] . '» курса санскрита: что сделать сейчас, что держать справкой, что по желанию.')
@section('robots', 'index, follow')

@section('content')
<div class="container mx-auto px-4 max-w-3xl py-10 md:py-14 font-nunito">

    <nav class="mb-6 text-sm text-gray-500">
        <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
        <span class="mx-2 text-gray-300">/</span>
        <a href="{{ route('materials.kochergina.hub') }}" class="hover:text-brand transition-colors">Материалы курса</a>
        <span class="mx-2 text-gray-300">/</span>
        <span class="text-gray-700">{{ $bundle['title'] }}</span>
    </nav>

    <header class="mb-8">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 leading-tight mb-3">{{ $bundle['title'] }}</h1>
        <p class="text-gray-600 leading-relaxed mb-4">{{ $bundle['tagline'] }}</p>

        <div class="bg-violet-50 border border-violet-100 rounded-2xl p-4 md:p-5 text-sm leading-relaxed">
            <p class="mb-2"><span class="font-extrabold text-violet-900">Когда выдавается.</span>
                <span class="text-gray-700">{{ $bundle['issuance'] }}</span></p>
            <p class="mb-0"><span class="font-extrabold text-violet-900">Признак готовности идти дальше.</span>
                <span class="text-gray-700">{{ $bundle['readiness'] }}</span></p>
        </div>
    </header>

    <section class="mb-10">
        <h2 class="text-lg font-extrabold text-gray-900 mb-4">Порядок работы</h2>
        <ol class="space-y-3">
            @foreach($bundle['sequence'] as $i => $step)
                <li class="flex items-start gap-3">
                    <span class="w-7 h-7 rounded-full bg-brand/10 text-brand font-extrabold text-sm flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                    <p class="text-gray-700 leading-relaxed pt-0.5">{{ $step }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    @if($bundle['slug'] === 'pervaya-shloka')
        @php($makshika = \App\Support\KocherginaMaterialBundles::makshika())
        <section class="mb-10">
            <h2 class="text-lg font-extrabold text-gray-900 mb-4">«Макшика» — точный текст</h2>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 md:p-8 text-center">
                <p class="text-2xl md:text-3xl leading-relaxed font-medium text-gray-900 mb-4" style="font-family: 'Noto Sans Devanagari', serif;">{!! nl2br(e($makshika['devanagari'])) !!}</p>
                <p class="text-sm italic text-gray-500 mb-6">{!! nl2br(e($makshika['iast'])) !!}</p>
                <p class="text-gray-700 leading-relaxed">{{ $makshika['ru'] }}</p>
                <p class="text-xs text-gray-400 mt-4">{{ $makshika['source_note'] }}</p>
            </div>
        </section>
    @endif

    @foreach(['now', 'reference', 'optional'] as $sectionKey)
        @php($section = $bundle['sections'][$sectionKey])
        <section class="mb-10">
            <h2 class="text-lg font-extrabold text-gray-900 mb-4">{{ $section['title'] }}</h2>
            <div class="grid gap-4">
                @foreach($section['items'] as $item)
                    @include('materials.kochergina.partials.material-card', ['item' => $item])
                @endforeach
            </div>
        </section>
    @endforeach

    @if(!empty($bundle['blocked_note']))
        <section class="mb-10">
            <div class="text-sm text-amber-800 leading-relaxed bg-amber-50 border border-amber-100 rounded-2xl p-4">
                <span class="font-bold">Блокировка этапа.</span> {{ $bundle['blocked_note'] }}
            </div>
        </section>
    @endif

    <footer class="pt-6 border-t border-gray-100 text-sm text-gray-500 leading-relaxed">
        <p class="mb-2">Все материалы на этой странице открыты — без входа в кабинет. Наборы выдаются по теме и готовности, а не по номеру занятия.</p>
        <p><a href="{{ route('materials.kochergina.hub') }}" class="font-bold text-brand hover:underline">Все наборы материалов курса</a></p>
    </footer>

</div>
@endsection
