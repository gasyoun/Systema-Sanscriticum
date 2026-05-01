@extends('layouts.shop')

@section('title', 'Общество ревнителей санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-[#E85C24]/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-12 relative z-10">
        <div class="text-center mb-10">
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Общество ревнителей санскрита
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed mb-8">
                Платформа для глубокого изучения языка, философии и текстов. Выберите курс для начала обучения.
            </p>
        </div>

        @livewire('shop.course-catalog')
    </div>
</div>
@endsection