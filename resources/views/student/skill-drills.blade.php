@extends('layouts.student')

@section('title', 'Тренажёры')
@section('header', 'Тренажёры')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-8 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">
            Короткие тренажёры
        </h2>
        <p class="text-gray-500 text-lg max-w-3xl">
            Быстрая практика по конкретному навыку — не карточки на повторение
            (для этого есть <a href="{{ config('srs.enabled') ? route('student.srs') : '#' }}" class="text-brand font-bold hover:underline">Карточки</a>),
            а короткие раунды: соотнести, отсортировать, вставить пропуск.
        </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach($drills as $drill)
            <a href="{{ $drill['url'] }}" target="_blank" rel="noopener"
               class="group bg-white rounded-2xl border border-gray-200 p-5 hover:border-brand/40 hover:shadow-[0_8px_30px_rgba(232,92,36,0.08)] hover:-translate-y-0.5 transition-all duration-200 flex flex-col">

                <span class="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-md bg-orange-50 text-brand border border-orange-200 mb-3 self-start">
                    {{ $drill['family'] }}
                </span>

                <h3 class="text-lg font-extrabold text-[#1A1A1A] leading-tight mb-2 group-hover:text-brand transition-colors">
                    {{ $drill['label'] }}
                </h3>

                <div class="mt-auto pt-3 border-t border-gray-100 flex items-center justify-end">
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-brand shrink-0">
                        Играть
                        <i class="fas fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
                    </span>
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection
