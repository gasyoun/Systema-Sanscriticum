@extends('layouts.student')

@section('title', $lesson->title)
@section('header', '')

@section('content')

@php
    // --- УМНЫЙ ПАРСЕР ССЫЛОК YOUTUBE ---
    $cleanYoutubeId = null;
    $rawYoutube = $youtubeId ?? $lesson->youtube_url ?? null;
    if (!empty($rawYoutube)) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawYoutube, $match)) {
            $cleanYoutubeId = $match[1];
        } else {
            $cleanYoutubeId = $rawYoutube;
        }
    }

    // --- УМНЫЙ ПАРСЕР ССЫЛОК RUTUBE ---
    $cleanRutubeId = null;
    $rawRutube = $rutubeId ?? $lesson->rutube_url ?? null;
    if (!empty($rawRutube)) {
        if (preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9_-]+)/i', $rawRutube, $match)) {
            $cleanRutubeId = $match[1];
        } else {
            $cleanRutubeId = $rawRutube;
        }
    }

    // --- ПАРСЕР ТАЙМКОДОВ ---
    function formatTimecodes($text) {
        if (!$text) return '';
        $pattern = '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/';
        $replacement = '<button @click.prevent="seekTo(\'$1\')" class="inline-flex items-center gap-1.5 px-2 py-0.5 mx-1 rounded-md bg-[#E85C24]/10 text-[#E85C24] border border-[#E85C24]/30 hover:bg-[#E85C24] hover:text-white font-mono text-sm font-bold transition-all shadow-sm group"><i class="fas fa-play text-[10px] opacity-60 group-hover:opacity-100 group-hover:text-white transition-colors"></i>$1</button>';
        return preg_replace($pattern, $replacement, $text);
    }
    
    $hasTranscript = !empty($transcriptSentences);
@endphp

{{-- ========================================== --}}
{{-- ГЛАВНЫЙ КОНТЕЙНЕР (Умный Flexbox)          --}}
{{-- ========================================== --}}
<div class="w-full flex flex-col xl:flex-row gap-6 2xl:gap-8 items-start relative max-w-full"
     x-data="{ 
         player: '{{ $cleanRutubeId ? 'rutube' : ($cleanYoutubeId ? 'youtube' : 'none') }}',
         currentTime: 0, 
         autoScroll: true, 
         searchQuery: '',
         
         init() {
             window.addEventListener('message', (event) => {
                 try {
                     let data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                     if (data.type === 'player:currentTime') { this.currentTime = data.data.time; }
                     if (data.event === 'infoDelivery' && data.info && data.info.currentTime !== undefined) { this.currentTime = data.info.currentTime; }
                 } catch (e) {}
             });

             setInterval(() => {
                 if (this.player === 'youtube') {
                     let yt = document.getElementById('youtube-player');
                     if(yt && yt.contentWindow) { yt.contentWindow.postMessage(JSON.stringify({event: 'listening', id: 1}), '*'); }
                 } else if (this.player === 'rutube') {
                     let rt = document.getElementById('rutube-player');
                     if(rt && rt.contentWindow) { rt.contentWindow.postMessage(JSON.stringify({type: 'player:getCurrentTime', data: {}}), '*'); }
                 }
             }, 500);

             setInterval(() => {
                 if (this.autoScroll && this.searchQuery === '') {
                     let container = this.$refs.scrollContainer;
                     let activeEl = container?.querySelector('.is-active-sentence');
                     if (activeEl && container) {
                         let targetScroll = activeEl.offsetTop - (container.clientHeight / 2) + (activeEl.clientHeight / 2);
                         if (Math.abs(container.scrollTop - targetScroll) > 40) {
                             container.scrollTo({ top: targetScroll, behavior: 'smooth' });
                         }
                     }
                 }
             }, 800);
         },

         matches(text) {
             if (this.searchQuery === '') return true;
             return text.includes(this.searchQuery.toLowerCase());
         },

         highlight(text) {
             if (this.searchQuery === '') return text;
             let safeQuery = this.searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
             let regex = new RegExp(`(${safeQuery})`, 'gi');
             return text.replace(regex, '<mark class=\'bg-yellow-300 text-yellow-900 rounded px-1 shadow-sm font-bold\'>$1</mark>');
         },

         seekTo(sec) {
             if (typeof sec === 'string') {
                 let parts = sec.split(':').reverse();
                 let tempSec = 0;
                 if (parts[0]) tempSec += parseInt(parts[0], 10);
                 if (parts[1]) tempSec += parseInt(parts[1], 10) * 60;
                 if (parts[2]) tempSec += parseInt(parts[2], 10) * 3600;
                 sec = tempSec;
             }

             this.autoScroll = false;
             setTimeout(() => { if (this.searchQuery === '') this.autoScroll = true; }, 3000);

             let activePlayerId = this.player === 'youtube' ? 'youtube-player' : (this.player === 'rutube' ? 'rutube-player' : null);
             if (!activePlayerId) return;

             let iframe = document.getElementById(activePlayerId);
             if (!iframe) return;

             if (this.player === 'youtube') {
                 iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'seekTo', args: [sec, true] }), '*');
                 iframe.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*');
             } else if (this.player === 'rutube') {
                 iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:setCurrentTime', data: { time: sec } }), '*');
                 iframe.contentWindow.postMessage(JSON.stringify({ type: 'player:play', data: {} }), '*');
             }
             window.scrollTo({ top: 0, behavior: 'smooth' });
         }
     }">

    {{-- ========================================== --}}
    {{-- ЛЕВАЯ КОЛОНКА (Транскрипт)                 --}}
    {{-- ========================================== --}}
    @if($hasTranscript)
    <div class="w-full xl:w-[400px] 2xl:w-[480px] shrink-0 xl:sticky xl:top-6 xl:h-[calc(100vh-48px)] flex flex-col order-2 xl:order-1">
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden flex flex-col h-[600px] xl:h-full">
            <div class="bg-gray-50 p-5 flex flex-col gap-4 border-b border-gray-100 shrink-0">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-[#E85C24]/10 flex items-center justify-center text-[#E85C24]">
                            <i class="fas fa-align-left text-lg"></i>
                        </div>
                        <div>
                            <h3 class="font-extrabold text-gray-900">Транскрипт</h3>
                            <p class="text-[11px] text-gray-500 font-bold">Читайте и кликайте</p>
                        </div>
                    </div>
                    <button @click="autoScroll = !autoScroll" 
                            class="w-10 h-10 rounded-xl border flex items-center justify-center transition-colors"
                            :class="autoScroll ? 'bg-indigo-50 text-indigo-600 border-indigo-200' : 'bg-white text-gray-400 border-gray-200 hover:bg-gray-50'"
                            title="Автопрокрутка">
                        <i class="fas fa-location-arrow" :class="{'animate-pulse': autoScroll}"></i>
                    </button>
                </div>
                
                <div class="relative w-full">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" x-model="searchQuery" placeholder="Поиск по тексту..." 
                           @input="autoScroll = (searchQuery === '')" 
                           class="w-full pl-10 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-[#E85C24] outline-none transition-all placeholder-gray-400">
                </div>
            </div>

            <div x-ref="scrollContainer" class="flex-1 overflow-y-auto p-4 pb-10 space-y-1 custom-scrollbar relative bg-white" @wheel="autoScroll = false" @touchstart="autoScroll = false">
                @foreach($transcriptSentences as $sentence)
                    <button @click.prevent="seekTo({{ $sentence['start'] }})"
                            x-show="matches({{ json_encode($sentence['safe_text']) }})"
                            :class="(currentTime >= {{ $sentence['start'] }} && currentTime <= {{ $sentence['end'] }}) 
                                ? 'is-active-sentence bg-[#F4F1EA] border-l-4 border-[#E85C24] text-[#1A1A1A] font-bold z-10 relative' 
                                : 'hover:bg-gray-50 border-l-4 border-transparent text-gray-600 font-medium'"
                            class="block w-full text-left p-3 rounded-r-xl transition-all duration-200 group">
                        
                        <span class="inline-flex items-center gap-1.5 bg-white border border-gray-200 text-gray-400 text-[10px] font-mono font-extrabold px-2 py-0.5 rounded mr-2 shadow-sm group-hover:border-[#E85C24] group-hover:text-[#E85C24] transition-colors"
                              :class="(currentTime >= {{ $sentence['start'] }} && currentTime <= {{ $sentence['end'] }}) ? '!bg-[#E85C24] !border-[#E85C24] !text-white' : ''">
                            {{ $sentence['formatted_time'] }}
                        </span>
                        
                        <span class="text-[15px] leading-relaxed transition-colors" 
                              x-html="highlight({{ json_encode($sentence['text'], JSON_UNESCAPED_UNICODE) }})">
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- ========================================== --}}
    {{-- ЦЕНТРАЛЬНАЯ КОЛОНКА (Видео, Растягивается) --}}
    {{-- ========================================== --}}
    <div class="flex-1 min-w-0 flex flex-col gap-6 2xl:gap-8 order-1 xl:order-2 w-full">
        
        {{-- ВИДЕОПЛЕЕР (Липкий сверху) --}}
        <div class="w-full bg-[#19191C] rounded-[24px] overflow-hidden shadow-2xl border border-gray-200/50 sticky top-6 z-40">
            <div class="relative aspect-video w-full bg-black">
                @if($cleanYoutubeId)
                    <iframe x-show="player === 'youtube'" 
                            id="youtube-player" 
                            src="https://www.youtube.com/embed/{{ $cleanYoutubeId }}?enablejsapi=1&rel=0" 
                            class="w-full h-full absolute inset-0" 
                            allowfullscreen 
                            allow="autoplay; encrypted-media">
                    </iframe>
                @endif
                
                @if($cleanRutubeId)
                    <iframe x-show="player === 'rutube'" 
                            id="rutube-player" 
                            src="https://rutube.ru/play/embed/{{ $cleanRutubeId }}" 
                            class="w-full h-full absolute inset-0" 
                            allowfullscreen 
                            allow="autoplay; encrypted-media">
                    </iframe>
                @endif

                <div x-show="player === 'none'" class="absolute inset-0 flex items-center justify-center bg-[#19191C]">
                    <div class="text-center text-gray-600">
                        <i class="fas fa-video-slash text-5xl mb-3 opacity-30"></i>
                        <p class="text-sm font-medium">Видео недоступно</p>
                    </div>
                </div>
            </div>

            {{-- Переключатель плееров --}}
            <div class="bg-[#141417] px-6 py-4 flex items-center justify-between border-t border-[#2C2C32]">
                <div class="flex items-center text-gray-500 text-[10px] font-bold uppercase tracking-widest">
                    <i class="fas fa-server mr-2"></i> Источник видео
                </div>
                <div class="flex gap-2">
                    @if($cleanRutubeId && $cleanYoutubeId)
                        <button @click="player = 'rutube'" :class="player === 'rutube' ? 'bg-[#0057b7] text-white shadow-[0_0_15px_rgba(0,87,183,0.4)]' : 'bg-[#252529] text-gray-400 hover:text-white'" class="flex items-center px-4 py-2 rounded-xl text-xs font-bold transition-all duration-300">
                            <img src="https://rutube.ru/favicon.ico" :class="player === 'rutube' ? '' : 'opacity-50 grayscale'" class="w-3.5 h-3.5 mr-2 transition-all"> RuTube
                        </button>
                        <button @click="player = 'youtube'" :class="player === 'youtube' ? 'bg-[#ff0000] text-white shadow-[0_0_15px_rgba(255,0,0,0.4)]' : 'bg-[#252529] text-gray-400 hover:text-white'" class="flex items-center px-4 py-2 rounded-xl text-xs font-bold transition-all duration-300">
                            <i class="fab fa-youtube mr-2 text-sm" :class="player === 'youtube' ? 'text-white' : 'text-gray-500'"></i> YouTube
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- ПЛАШКА: Название урока --}}
        <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-6 relative z-10">
            <div>
                @php
                    $lessonIndex = $lessons->pluck('id')->search($lesson->id) + 1;
                    $totalLessons = $lessons->count();
                @endphp
                <div class="flex items-center gap-3 mb-3">
                    <span class="bg-[#E85C24]/10 text-[#E85C24] px-3 py-1 rounded-md text-[11px] font-extrabold uppercase tracking-wider">
                        Урок {{ $lessonIndex }} из {{ $totalLessons }}
                    </span>
                    @if($lesson->duration)
                        <span class="text-sm text-gray-500 font-bold flex items-center">
                            <i class="far fa-clock mr-1.5 text-gray-400"></i> {{ $lesson->duration }} мин
                        </span>
                    @endif
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-[#1A1A1A] leading-tight">{{ $lesson->title }}</h1>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <a href="https://t.me/rusamskrtam" target="_blank" class="flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-50 text-gray-600 hover:bg-blue-500 hover:text-white border border-gray-200 transition-colors shadow-sm" title="Написать куратору">
                    <i class="fab fa-telegram-plane text-xl"></i>
                </a>

                @if(auth()->user()->completedLessons->contains($lesson->id))
                    <div class="inline-flex justify-center items-center px-6 py-3 rounded-2xl bg-green-50 text-green-600 text-sm font-extrabold border border-green-200 cursor-default">
                        <i class="fas fa-check-circle mr-2 text-lg"></i> Пройден
                    </div>
                @else
                    <form action="{{ route('student.lesson.complete', [$course->slug, $lesson->id]) }}" method="POST">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center px-8 py-3 bg-[#E85C24] hover:bg-[#d04a15] text-white rounded-2xl font-extrabold text-sm transition-all shadow-[0_5px_15px_rgba(232,92,36,0.3)] hover:-translate-y-0.5 active:translate-y-0 uppercase tracking-wide">
                            Завершить
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- КОНТЕНТ УРОКА (Описание) --}}
        @if($lesson->content || $lesson->topic)
        <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 relative z-10">
            <div class="prose prose-lg max-w-none text-gray-800 leading-relaxed font-nunito font-medium marker:bg-[#E85C24]/20 marker:text-[#1A1A1A]">
                {!! formatTimecodes(nl2br(e($lesson->content ?? $lesson->topic))) !!}
            </div>
        </div>
        @endif
        
        {{-- ДОП МАТЕРИАЛЫ --}}
        @if(!empty($lesson->attachments) && is_array($lesson->attachments))
        <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 relative z-10">
            <h3 class="font-extrabold text-gray-900 mb-6 flex items-center text-lg">
                <i class="fas fa-layer-group text-[#E85C24] mr-3 text-xl"></i> Дополнительные материалы
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @foreach($lesson->attachments as $file)
                    @php
                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        $isAudio = in_array($ext, ['mp3', 'wav', 'm4a', 'ogg', 'aac']);
                        $isVideo = in_array($ext, ['mp4', 'mov', 'webm']);
                        $isPdf = $ext === 'pdf';
                        
                        $mimeType = $ext === 'm4a' ? 'audio/mp4' : ($isAudio ? "audio/$ext" : "video/$ext");
                        
                        $iconClass = 'far fa-file-alt text-gray-400';
                        $bgClass = 'bg-gray-100';
                        
                        if ($isPdf) {
                            $iconClass = 'far fa-file-pdf text-red-500';
                            $bgClass = 'bg-red-50';
                        } elseif ($isAudio) {
                            $iconClass = 'fas fa-music text-blue-500';
                            $bgClass = 'bg-blue-50';
                        } elseif ($isVideo) {
                            $iconClass = 'fas fa-video text-purple-500';
                            $bgClass = 'bg-purple-50';
                        } elseif (in_array($ext, ['zip', 'rar'])) {
                            $iconClass = 'far fa-file-archive text-yellow-600';
                            $bgClass = 'bg-yellow-50';
                        }
                    @endphp

                    <div class="flex flex-col rounded-[1.25rem] border border-gray-100 bg-gray-50/50 hover:bg-white hover:border-[#E85C24]/30 hover:shadow-[0_8px_20px_rgba(232,92,36,0.06)] transition-all duration-300 group overflow-hidden">
                        <div class="p-5 flex items-center justify-between">
                            <div class="flex items-center gap-4 flex-1 min-w-0">
                                <div class="w-12 h-12 rounded-full {{ $bgClass }} flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                    <i class="{{ $iconClass }} text-xl"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-extrabold text-gray-900 text-sm truncate group-hover:text-[#E85C24] transition-colors">
                                        {{ basename($file) }}
                                    </h4>
                                    <p class="text-[10px] text-gray-400 font-black uppercase tracking-widest mt-0.5">
                                        {{ strtoupper($ext) }} ФАЙЛ
                                    </p>
                                </div>
                            </div>
                            <a href="{{ asset('storage/' . $file) }}" download target="_blank" class="ml-4 w-10 h-10 flex items-center justify-center rounded-full bg-white border border-gray-200 text-gray-400 hover:text-white hover:bg-[#E85C24] hover:border-[#E85C24] transition-colors shadow-sm shrink-0" title="Скачать">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>

                        @if($isAudio)
                            <div class="px-5 pb-5 pt-1">
                                <audio controls class="w-full h-10 custom-audio-player outline-none">
                                    <source src="{{ asset('storage/' . $file) }}" type="{{ $mimeType }}">
                                </audio>
                            </div>
                        @endif

                        @if($isVideo)
                            <div class="px-2 pb-2">
                                <video controls class="w-full rounded-xl bg-black max-h-48 object-cover outline-none">
                                    <source src="{{ asset('storage/' . $file) }}" type="{{ $mimeType }}">
                                </video>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <style>
            .custom-audio-player::-webkit-media-controls-panel { background-color: #f3f4f6; border-radius: 9999px; }
            .custom-audio-player::-webkit-media-controls-play-button { background-color: #E85C24; border-radius: 50%; color: white;}
            .custom-audio-player::-webkit-media-controls-timeline { margin-left: 10px; margin-right: 10px; }
        </style>
        @endif
    </div>

    {{-- ========================================== --}}
    {{-- ПРАВАЯ КОЛОНКА (Заметки)                   --}}
    {{-- ========================================== --}}
    <div class="w-full xl:w-[350px] 2xl:w-[420px] shrink-0 xl:sticky xl:top-6 h-max order-3 flex flex-col gap-6">
        <div class="bg-[#FFF9C4] rounded-3xl p-6 shadow-sm border border-yellow-200">
            <h3 class="font-extrabold text-yellow-900 mb-4 flex items-center text-sm uppercase tracking-wide">
                <i class="far fa-edit mr-2 text-yellow-600"></i> Мои заметки
            </h3>
            <form action="{{ route('student.lesson.note', [$course->slug, $lesson->id]) }}" method="POST">
                @csrf
                <textarea name="notes" rows="6" class="w-full bg-white/50 border-transparent rounded-xl p-4 focus:ring-2 focus:ring-yellow-400 focus:bg-white text-gray-800 transition resize-y font-medium text-sm placeholder-yellow-700/50" placeholder="Важные мысли из урока...">{{ $currentNote ?? '' }}</textarea>
                <button type="submit" class="mt-3 w-full bg-yellow-400 hover:bg-yellow-500 text-yellow-900 py-3 rounded-xl font-extrabold text-sm transition-colors shadow-sm">
                    Сохранить
                </button>
            </form>
        </div>
    </div>

</div>
@endsection