@extends('layouts.shop')
@section('title', 'Пробный урок · ' . $course->title)

@section('content')
@php
    // Тот же парсер ссылок, что и в плеере кабинета (student/lesson.blade.php).
    $cleanYoutubeId = null;
    $rawYoutube = $lesson->youtube_url ?? null;
    if (! empty($rawYoutube)) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawYoutube, $m)) {
            $cleanYoutubeId = $m[1];
        } else {
            $cleanYoutubeId = $rawYoutube;
        }
    }

    $cleanRutubeId = null;
    $rawRutube = $lesson->rutube_url ?? null;
    if (! empty($rawRutube)) {
        if (preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9_-]+)/i', $rawRutube, $m)) {
            $cleanRutubeId = $m[1];
        } else {
            $cleanRutubeId = $rawRutube;
        }
    }
@endphp

<div class="min-h-screen bg-[#0A0D14] text-white font-sans relative overflow-hidden">
    <div class="absolute top-[-10%] right-[-10%] w-[600px] h-[600px] bg-[#E85C24]/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-16 lg:pt-28 lg:pb-24 relative z-10">

        {{-- Хлебная навигация назад к курсу --}}
        <a href="{{ route('shop.course.show', $course->slug) }}"
           class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-[#E85C24] transition-colors mb-6">
            <i class="fas fa-arrow-left text-xs"></i>
            {{ $course->title }}
        </a>

        <div class="flex items-center gap-3 mb-4">
            <span class="bg-[#38BDF8]/20 text-[#38BDF8] text-xs font-black uppercase px-3 py-1.5 rounded-full tracking-widest border border-[#38BDF8]/30">
                <i class="fas fa-play-circle mr-1"></i> Пробный урок
            </span>
        </div>

        <h1 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight mb-8 leading-tight">
            {{ $lesson->title }}
        </h1>

        {{-- Видеоплеер (тот же embed, что в кабинете) --}}
        <div class="w-full bg-[#111622] rounded-3xl overflow-hidden shadow-2xl border border-[#1F2636] relative">
            <div class="relative aspect-video w-full bg-black">
                @if($cleanYoutubeId)
                    <iframe src="https://www.youtube.com/embed/{{ $cleanYoutubeId }}?rel=0"
                            class="w-full h-full absolute inset-0" allowfullscreen
                            allow="autoplay; encrypted-media"></iframe>
                @elseif($cleanRutubeId)
                    <iframe src="https://rutube.ru/play/embed/{{ $cleanRutubeId }}"
                            class="w-full h-full absolute inset-0" allowfullscreen
                            allow="autoplay; encrypted-media"></iframe>
                @elseif($lesson->video_url)
                    <video src="{{ $lesson->video_url }}" controls
                           class="w-full h-full absolute inset-0 bg-black"></video>
                @else
                    <div class="absolute inset-0 flex items-center justify-center text-slate-600">
                        <div class="text-center">
                            <i class="fas fa-video-slash text-5xl mb-3 opacity-30"></i>
                            <p class="text-sm">Видео скоро появится.</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- CTA: понравилось — к тарифам --}}
        <div class="mt-10 rounded-2xl border border-[#E85C24]/30 bg-gradient-to-r from-[#E85C24]/10 to-transparent p-6 lg:p-8 flex flex-col md:flex-row md:items-center gap-5">
            <div class="flex-1">
                <h2 class="text-xl lg:text-2xl font-bold text-white mb-1">Понравился формат?</h2>
                <p class="text-sm text-slate-400 leading-relaxed">
                    Это лишь один урок из программы курса «{{ $course->title }}». Выберите вариант участия и получите доступ ко всем материалам.
                </p>
            </div>
            <a href="{{ route('shop.course.show', $course->slug) }}#tariffs"
               class="md:flex-shrink-0 inline-flex justify-center items-center px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-[#E85C24] hover:bg-[#d64e1c] transition-all shadow-[0_0_20px_rgba(232,92,36,0.3)]">
                Выбрать тариф
            </a>
        </div>
    </div>
</div>
@endsection
