@extends('layouts.student')

@section('title', 'Мой хинди')
@section('header', 'Мой хинди')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito" data-testid="hindi-programme-playlist">

    <div class="relative bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 md:p-10 mb-10 overflow-hidden">
        <div class="absolute top-0 right-0 -mt-10 -mr-10 w-40 h-40 bg-orange-50 rounded-full blur-3xl opacity-50 pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center px-4 py-1.5 rounded-full text-xs font-extrabold uppercase tracking-widest bg-orange-50 text-brand mb-5">
                <i class="fas fa-list-ol mr-2"></i> Программа
            </div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] mb-3 tracking-tight leading-tight">
                Мой хинди
            </h1>
            <p class="text-gray-500 text-base md:text-lg leading-relaxed max-w-3xl">
                Все занятия, к которым у вас уже есть доступ, по потокам хинди — в одном списке.
            </p>
            <div class="mt-6 bg-gray-50 p-5 rounded-2xl border border-gray-100">
                <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Открыто</span>
                <span class="text-sm font-bold text-gray-800">{{ $count }} занятий</span>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($items as $row)
            @php
                $lesson = $row['lesson'];
                $course = $row['course'];
                $isCompleted = auth()->user()->completedLessons->contains($lesson->id);
            @endphp
            <a href="{{ $row['url'] }}"
               class="group block bg-white rounded-2xl border border-gray-100 hover:border-brand/30 hover:shadow-lg hover:-translate-y-1 transition-all duration-300 relative overflow-hidden"
               data-testid="hindi-playlist-item"
               data-course-id="{{ $course->id }}"
               data-lesson-id="{{ $lesson->id }}">
                @if($isCompleted)
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-green-500"></div>
                @endif
                <div class="p-5 md:p-6 flex items-start gap-4">
                    <div class="shrink-0 w-10 h-10 rounded-xl bg-orange-50 text-brand flex items-center justify-center font-extrabold text-sm">
                        <i class="fas fa-play"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-widest bg-gray-100 text-gray-600"
                                  data-testid="hindi-playlist-shell">{{ $row['shell_label'] }}</span>
                            @if($isCompleted)
                                <span class="text-[10px] font-bold uppercase tracking-widest text-green-700">Пройдено</span>
                            @endif
                        </div>
                        <h2 class="text-lg font-bold text-gray-900 group-hover:text-brand transition-colors leading-snug">
                            {{ $lesson->title }}
                        </h2>
                    </div>
                    <i class="fas fa-chevron-right text-gray-300 group-hover:text-brand mt-2"></i>
                </div>
            </a>
        @empty
            <div class="bg-white rounded-2xl border border-gray-100 p-8 text-gray-500" data-testid="hindi-playlist-empty">
                Пока нет открытых занятий хинди. Если вы оплатили поток, откройте его карточку в кабинете или напишите куратору.
            </div>
        @endforelse
    </div>
</div>
@endsection
