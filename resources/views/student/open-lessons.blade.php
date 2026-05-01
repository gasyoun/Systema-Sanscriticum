@extends('layouts.student')

@section('title', 'Открытые уроки')
@section('header', 'Открытые уроки')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-8 mt-6">
        <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">
            Открытые уроки и вебинары
        </h2>
        <p class="text-gray-500 text-lg max-w-3xl">
            Эти уроки доступны всем студентам с доступом в кабинет — без оплаты курсов.
        </p>
    </div>

    @if($lessons->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center shadow-sm">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center">
                <i class="fas fa-lock-open text-2xl"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-1">Пока нет открытых уроков</h3>
            <p class="text-sm text-gray-500">Когда появятся свободные материалы или вебинары — они отобразятся здесь.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            @foreach($lessons as $lesson)
                <a href="{{ route('student.lesson', ['slug' => $lesson->course->slug, 'lessonId' => $lesson->id]) }}"
                   class="group bg-white rounded-2xl border border-gray-200 p-5 hover:border-[#E85C24]/40 hover:shadow-[0_8px_30px_rgba(232,92,36,0.08)] hover:-translate-y-0.5 transition-all duration-200 flex flex-col">

                    <div class="flex items-start justify-between gap-3 mb-3">
                        <span class="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <i class="fas fa-lock-open text-[9px]"></i>
                            Открытый
                        </span>

                        @if($lesson->lesson_date)
                            <span class="text-xs text-gray-400 font-medium whitespace-nowrap">
                                {{ $lesson->lesson_date->translatedFormat('d MMM Y') }}
                            </span>
                        @endif
                    </div>

                    <h3 class="text-lg font-extrabold text-[#1A1A1A] leading-tight mb-2 group-hover:text-[#E85C24] transition-colors">
                        {{ $lesson->title }}
                    </h3>

                    @if($lesson->topic)
                        <p class="text-sm text-gray-500 leading-relaxed mb-4 line-clamp-3">
                            {{ $lesson->topic }}
                        </p>
                    @endif

                    <div class="mt-auto pt-3 border-t border-gray-100 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-xs text-gray-500 min-w-0">
                            <i class="fas fa-book-open text-gray-400 shrink-0"></i>
                            <span class="truncate">{{ $lesson->course->title ?? 'Без курса' }}</span>
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-bold text-[#E85C24] shrink-0">
                            Смотреть
                            <i class="fas fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
