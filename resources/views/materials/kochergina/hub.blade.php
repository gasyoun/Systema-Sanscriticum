@extends('layouts.articles')

@section('title', 'Материалы к курсу Кочергиной — наборы по этапам')
@section('meta_description', 'Открытые наборы материалов к курсу санскрита Кочергиной: конспект Зализняка, карточки, «Макшика», лигатуры, словари и таблицы — по этапам обучения.')
@section('robots', 'index, follow')

@section('content')
<div class="container mx-auto px-4 max-w-3xl py-10 md:py-14 font-nunito">

    <nav class="mb-6 text-sm text-gray-500">
        <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
        <span class="mx-2 text-gray-300">/</span>
        <span class="text-gray-700">Материалы курса</span>
    </nav>

    <header class="mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900 leading-tight mb-3">Материалы к курсу Кочергиной</h1>
        <p class="text-gray-600 leading-relaxed">
            Постоянные наборы дополнительных материалов по этапам курса санскрита. Каждый набор выдаётся,
            когда ученик дорос до его темы — не по номеру занятия. Все материалы открыты: без входа,
            без оплаты, с прямыми ссылками. Порядок работы и признак готовности идти дальше — на странице
            каждого набора.
        </p>
    </header>

    <div class="grid gap-5 mb-10">
        @foreach($bundles as $bundle)
            <a href="{{ route('materials.kochergina.bundle', ['slug' => $bundle['slug']]) }}"
               class="block bg-white rounded-2xl shadow-sm border border-gray-100 hover:border-brand/40 hover:shadow-md transition-all p-6">
                <h2 class="text-xl font-extrabold text-gray-900 mb-2">{{ $bundle['title'] }}</h2>
                <p class="text-sm text-gray-600 leading-relaxed mb-4">{{ $bundle['tagline'] }}</p>
                <p class="text-xs text-violet-800 font-bold">{{ $bundle['issuance'] }}</p>
            </a>
        @endforeach
    </div>

    <section class="mb-10">
        <h2 class="text-lg font-extrabold text-gray-900 mb-4">Принципы наборов</h2>
        <ul class="space-y-3 text-sm text-gray-700 leading-relaxed">
            <li class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-brand/10 text-brand flex items-center justify-center shrink-0 text-xs font-bold"><i class="fas fa-check"></i></span>
                Один материал — одна постоянная ссылка: между наборами материалы переиспользуются, дубликатов нет.
            </li>
            <li class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-brand/10 text-brand flex items-center justify-center shrink-0 text-xs font-bold"><i class="fas fa-check"></i></span>
                У каждого файла указаны версия и формат; заблокированные пункты объясняют, чего не хватает, вместо мёртвых ссылок.
            </li>
            <li class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-brand/10 text-brand flex items-center justify-center shrink-0 text-xs font-bold"><i class="fas fa-check"></i></span>
                Материалы дополняют учебник, но не заменяют занятия: сначала занятие, затем набор к его теме.
            </li>
        </ul>
    </section>

</div>
@endsection
