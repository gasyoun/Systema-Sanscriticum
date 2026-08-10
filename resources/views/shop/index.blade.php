@extends('layouts.shop')

@section('title', 'Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-12 relative z-10">
        <div class="text-center mb-10">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Общество ревнителей санскрита
            </h1>
            {{-- Подзаголовок — ориентация, не лозунг (H1868): что здесь есть и
                 куда идти новичку, вместо «выберите курс» перед стеной карточек. --}}
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-6">
                Курсы санскрита и хинди — от первых букв с нуля до чтения текстов в оригинале.
                Если вы здесь впервые, ниже — короткие ответы на вопросы, с которых обычно начинают.
            </p>

            <div class="flex flex-wrap justify-center gap-3">
                {{-- Вход для новичка (H323, beginner on-ramp) --}}
                <a href="{{ route('shop.start') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 hover:bg-[#38BDF8]/5 text-[#38BDF8] text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-compass"></i>
                    Не знаете, с чего начать?
                </a>
                {{-- Лесенка цен (H1293): общесайтовые цены видны с первого экрана --}}
                <a href="#ceny"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-brand/60 hover:bg-brand/5 text-brand text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-ruble-sign"></i>
                    Как устроены цены
                </a>
            </div>
        </div>

        {{-- Полоса доверия (H323, social proof) --}}
        @include('shop.partials.trust-strip')

        {{-- Первые вопросы новичка (H1868, custdev-обоснованная ориентация) --}}
        @include('shop.partials.first-questions')

        @livewire('shop.course-catalog')

        {{-- Лесенка цен с позиционированием (H1293) --}}
        @include('shop.partials.price-ladder-narrative', ['ladder' => $ladder])

        {{-- Общесайтовые отзывы (H323, social proof) --}}
        @include('shop.partials.featured-testimonials', ['testimonials' => $featuredTestimonials])

        {{-- Лид-форма (самогейтится по фича-флагу newsletter_subscribe) --}}
        <div class="mt-16 flex justify-center">
            <x-newsletter-subscribe
                title="Пока просто присматриваетесь?"
                blurb="Оставьте email — пришлем бесплатные материалы для первого шага и заведем личный кабинет." />
        </div>
    </div>

    @include('partials.deposit-modal', ['deposit' => $deposit])
</div>
@endsection
