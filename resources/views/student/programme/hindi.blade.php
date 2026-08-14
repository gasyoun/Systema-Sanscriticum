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
                @if(!empty($hindiTeacherBrief))
                    Все ваши потоки хинди — занятия видны вам как преподавателю, даже без студенческой оплаты.
                @else
                    Все занятия, к которым у вас уже есть доступ, по потокам хинди — в одном списке.
                @endif
            </p>
            @if(!empty($hindiTeacherBrief))
                <a href="{{ $hindiTeacherBrief }}"
                   target="_blank"
                   rel="noopener"
                   class="inline-flex items-center gap-2 mt-4 text-sm font-extrabold text-brand hover:underline"
                   data-testid="hindi-teacher-brief">
                    <i class="fas fa-external-link-alt text-xs"></i> Задача: что проверить
                </a>
            @endif
            <div class="mt-6 bg-gray-50 p-5 rounded-2xl border border-gray-100">
                <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Открыто</span>
                <span class="text-sm font-bold text-gray-800">{{ $count }} занятий</span>
            </div>
            @if(!empty($srsDeckEnabled) && config('srs.enabled'))
                <a href="{{ route('student.srs.deck', \App\Support\HindiMySrsDeck::DECK_SLUG) }}"
                   class="inline-flex items-center gap-2 mt-4 text-sm font-extrabold text-brand hover:underline"
                   data-testid="hindi-playlist-open-deck">
                    <i class="fas fa-clone text-xs"></i> Открыть колоду «Мой хинди»
                </a>
            @endif
            @if(!empty($tgPracticeEnabled))
                <a href="{{ route('student.programme.hindi.tg') }}"
                   class="inline-flex items-center gap-2 mt-4 {{ (!empty($srsDeckEnabled) && config('srs.enabled')) ? 'ml-4' : '' }} text-sm font-extrabold text-brand hover:underline"
                   data-testid="hindi-playlist-tg-practice">
                    <i class="fas fa-comments text-xs"></i> Практика из чата
                </a>
            @endif
        </div>
    </div>

    @include('student.partials.hindi-srs-flash')

    <div class="space-y-4">
        @forelse($items as $row)
            @php
                $lesson = $row['lesson'];
                $course = $row['course'];
                $isCompleted = auth()->user()->completedLessons->contains($lesson->id);
            @endphp
            <div class="group bg-white rounded-2xl border border-gray-100 hover:border-brand/30 hover:shadow-lg hover:-translate-y-1 transition-all duration-300 relative overflow-hidden"
               data-testid="hindi-playlist-item"
               data-course-id="{{ $course->id }}"
               data-lesson-id="{{ $lesson->id }}">
                @if($isCompleted)
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-green-500"></div>
                @endif
                <div class="p-5 md:p-6 flex items-start gap-4">
                    <a href="{{ $row['url'] }}" class="shrink-0 w-10 h-10 rounded-xl bg-orange-50 text-brand flex items-center justify-center font-extrabold text-sm">
                        <i class="fas fa-play"></i>
                    </a>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-widest bg-gray-100 text-gray-600"
                                  data-testid="hindi-playlist-shell">{{ $row['shell_label'] }}</span>
                            @if($isCompleted)
                                <span class="text-[10px] font-bold uppercase tracking-widest text-green-700">Пройдено</span>
                            @endif
                        </div>
                        <a href="{{ $row['url'] }}" class="block">
                            <h2 class="text-lg font-bold text-gray-900 group-hover:text-brand transition-colors leading-snug">
                                {{ $lesson->title }}
                            </h2>
                        </a>
                        <div class="flex flex-wrap items-center gap-3 mt-3">
                            @if(!empty($row['drills_url']))
                                <a href="{{ $row['drills_url'] }}"
                                   class="inline-flex items-center gap-1.5 text-sm font-extrabold text-brand hover:underline"
                                   data-testid="hindi-playlist-drills">
                                    <i class="fas fa-pen-fancy text-xs"></i> Упражнения
                                </a>
                            @endif
                            @if(!empty($row['has_srs_items']))
                                <form method="POST"
                                      action="{{ route('student.programme.hindi.srs') }}"
                                      class="inline"
                                      data-testid="hindi-playlist-srs-form">
                                    @csrf
                                    <input type="hidden" name="lesson_id" value="{{ $lesson->id }}">
                                    <button type="submit"
                                            class="inline-flex items-center gap-1.5 text-sm font-extrabold text-gray-600 hover:text-brand"
                                            data-testid="hindi-playlist-srs">
                                        + в колоду
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                    <a href="{{ $row['url'] }}" class="text-gray-300 group-hover:text-brand mt-2" aria-hidden="true">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-gray-100 p-8 text-gray-500" data-testid="hindi-playlist-empty">
                Пока нет открытых занятий хинди. Если вы оплатили поток, откройте его карточку в кабинете или напишите куратору.
            </div>
        @endforelse
    </div>
</div>
@endsection
