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

@php
    $hasAttachments = !empty($lesson->attachments) && is_array($lesson->attachments);
    $showTabs = $hasTranscript || $hasAttachments; // Показываем табы, если есть хотя бы транскрипт ИЛИ файлы
@endphp

{{-- ========================================== --}}
{{-- ГЛАВНЫЙ КОНТЕЙНЕР (Умный Flexbox с 3 Табами) --}}
{{-- ========================================== --}}
<div class="w-full flex flex-col xl:flex-row gap-6 2xl:gap-8 items-start relative max-w-full"
     x-data="{ 
         player: '{{ $cleanRutubeId ? 'rutube' : ($cleanYoutubeId ? 'youtube' : 'none') }}',
         currentTime: 0, 
         autoScroll: true, 
         searchQuery: '',
         activeTab: '{{ $hasTranscript ? 'transcript' : ($hasAttachments ? 'materials' : 'notes') }}',
         
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
                 if (this.autoScroll && this.searchQuery === '' && this.activeTab === 'transcript') {
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
             return text.toLowerCase().includes(this.searchQuery.toLowerCase());
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
    {{-- ЛЕВАЯ КОЛОНКА (Главная: Видео и Текст)     --}}
    {{-- ========================================== --}}
    <div class="flex-1 min-w-0 flex flex-col gap-6 w-full">
        
        {{-- ВИДЕОПЛЕЕР --}}
        <div class="w-full bg-[#19191C] rounded-[24px] overflow-hidden shadow-2xl border border-gray-200/50 relative z-40">
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
            <div class="bg-[#141417] px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t border-[#2C2C32]">
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
        <div class="bg-white rounded-3xl p-5 md:p-6 shadow-sm border border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6 relative z-10">
            <div>
                @php
                    $lessonIndex = $lessons->pluck('id')->search($lesson->id) + 1;
                    $totalLessons = $lessons->count();
                @endphp
                <div class="flex items-center gap-3 mb-2">
                    <span class="bg-[#E85C24]/10 text-[#E85C24] px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase tracking-wider">
                        Урок {{ $lessonIndex }} из {{ $totalLessons }}
                    </span>
                    @if($lesson->duration)
                        <span class="text-xs md:text-sm text-gray-500 font-bold flex items-center">
                            <i class="far fa-clock mr-1.5 text-gray-400"></i> {{ $lesson->duration }} мин
                        </span>
                    @endif
                </div>
                <h1 class="text-lg md:text-xl font-extrabold text-[#1A1A1A] leading-tight">{{ $lesson->title }}</h1>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <a href="https://t.me/rusamskrtam" target="_blank" class="flex items-center justify-center w-10 h-10 md:w-11 md:h-11 rounded-xl bg-gray-50 text-gray-600 hover:bg-blue-500 hover:text-white border border-gray-200 transition-colors shadow-sm" title="Написать куратору">
                    <i class="fab fa-telegram-plane text-lg"></i>
                </a>

                @if(auth()->user()->completedLessons->contains($lesson->id))
                    <div class="inline-flex justify-center items-center px-5 py-2.5 rounded-xl bg-green-50 text-green-600 text-xs md:text-sm font-extrabold border border-green-200 cursor-default">
                        <i class="fas fa-check-circle mr-2 text-base"></i> Пройден
                    </div>
                @else
                    <form action="{{ route('student.lesson.complete', [$course->slug, $lesson->id]) }}" method="POST">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center px-6 py-2.5 md:py-3 bg-[#E85C24] hover:bg-[#d04a15] text-white rounded-xl font-extrabold text-xs md:text-sm transition-all shadow-[0_5px_15px_rgba(232,92,36,0.3)] hover:-translate-y-0.5 active:translate-y-0 uppercase tracking-wide">
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
    </div>

    {{-- ========================================== --}}
    {{-- ПРАВАЯ КОЛОНКА (Инструменты: Транскрипт / Заметки / Файлы) --}}
    {{-- ========================================== --}}
    <div class="w-full xl:w-[420px] shrink-0 xl:sticky xl:top-6 h-auto xl:h-[calc(100vh-48px)] flex flex-col relative z-20">
        
        {{-- ПЕРЕКЛЮЧАТЕЛЬ ТАБОВ (Показываем только если есть выбор) --}}
        @if($showTabs)
        <div class="flex items-center bg-gray-100/80 p-1.5 rounded-2xl mb-4 shrink-0 shadow-inner overflow-x-auto custom-scrollbar">
            @if($hasTranscript)
            <button @click="activeTab = 'transcript'" 
                    class="flex-1 flex items-center justify-center gap-1.5 py-2.5 px-2 rounded-xl text-[13px] font-extrabold transition-all duration-300"
                    :class="activeTab === 'transcript' ? 'bg-white text-[#E85C24] shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-white/50'">
                <i class="fas fa-align-left text-base"></i> Текст
            </button>
            @endif
            
            @if($hasAttachments)
<button @click="activeTab = 'materials'" 
        class="flex-1 flex items-center justify-center gap-1.5 py-2.5 px-2 rounded-xl text-[13px] font-extrabold transition-all duration-300"
        :class="activeTab === 'materials' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-white/50'">
    <i class="fas fa-layer-group text-base"></i> Файлы
</button>
@endif

<button @click="activeTab = 'notes'" 
        class="flex-1 flex items-center justify-center gap-1.5 py-2.5 px-2 rounded-xl text-[13px] font-extrabold transition-all duration-300"
        :class="activeTab === 'notes' ? 'bg-white text-yellow-600 shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-white/50'">
    <i class="far fa-edit text-base"></i> Заметки
</button>
            @endif
        </div>
        

        {{-- БЛОК 1: ТРАНСКРИПТ --}}
        @if($hasTranscript)
        <div x-show="activeTab === 'transcript'" 
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="bg-white rounded-3xl shadow-sm border border-gray-100 flex flex-col flex-1 min-h-[500px] overflow-hidden">
            
            <div class="bg-gray-50 p-4 flex flex-col gap-3 border-b border-gray-100 shrink-0">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] text-gray-500 font-bold uppercase tracking-wider">Навигация по видео</p>
                    </div>
                    <button @click="autoScroll = !autoScroll" 
                            class="w-8 h-8 rounded-lg border flex items-center justify-center transition-colors"
                            :class="autoScroll ? 'bg-indigo-50 text-indigo-600 border-indigo-200' : 'bg-white text-gray-400 border-gray-200 hover:bg-gray-50'"
                            title="Автопрокрутка">
                        <i class="fas fa-location-arrow text-xs" :class="{'animate-pulse': autoScroll}"></i>
                    </button>
                </div>
                
                <div class="relative w-full">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" x-model="searchQuery" placeholder="Поиск фразы..." 
                           @input="autoScroll = (searchQuery === '')" 
                           class="w-full pl-9 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-[#E85C24] outline-none transition-all placeholder-gray-400 shadow-inner">
                </div>
            </div>

            <div x-ref="scrollContainer" class="flex-1 overflow-y-auto p-3 pb-10 space-y-1 custom-scrollbar bg-white" @wheel="autoScroll = false" @touchstart="autoScroll = false">
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
                        
                        <span class="text-[14px] leading-relaxed transition-colors" 
                              x-html="highlight({{ json_encode($sentence['text'], JSON_UNESCAPED_UNICODE) }})">
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
        @endif

        {{-- БЛОК 2: ЗАМЕТКИ --}}
        <div x-show="activeTab === 'notes'" 
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="bg-[#FFF9C4] rounded-3xl p-5 md:p-6 shadow-sm border border-yellow-200 flex flex-col h-max">
             
            <h3 class="font-extrabold text-yellow-900 mb-4 flex items-center text-sm uppercase tracking-wide shrink-0">
                <i class="far fa-edit mr-2 text-yellow-600"></i> Мои заметки
            </h3>
            
            <form action="{{ route('student.lesson.note', [$course->slug, $lesson->id]) }}" method="POST" class="flex flex-col">
                @csrf
                <textarea name="notes" rows="8" class="w-full bg-white/50 border-transparent rounded-2xl p-4 focus:ring-2 focus:ring-yellow-400 focus:bg-white text-gray-800 transition resize-y font-medium text-sm placeholder-yellow-700/50 custom-scrollbar" placeholder="Важные мысли из урока, инсайты, вопросы к преподавателю...">{{ $currentNote ?? '' }}</textarea>
                
                <button type="submit" class="mt-4 w-full bg-yellow-400 hover:bg-yellow-500 text-yellow-900 py-3.5 rounded-2xl font-extrabold text-sm transition-colors shadow-sm shrink-0">
                    Сохранить изменения
                </button>
            </form>
        </div>

        {{-- БЛОК 3: ФАЙЛЫ И МАТЕРИАЛЫ --}}
        @if($hasAttachments)
        <div x-show="activeTab === 'materials'" 
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="bg-white rounded-3xl p-5 md:p-6 shadow-sm border border-gray-100 flex flex-col h-max">
            
            <h3 class="font-extrabold text-gray-900 mb-4 flex items-center text-sm uppercase tracking-wide">
                <i class="fas fa-layer-group text-blue-500 mr-2"></i> Материалы к уроку
            </h3>
            
            {{-- Сетка в один столбец для боковой колонки --}}
            <div class="grid grid-cols-1 gap-4">
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

                    <div class="flex flex-col rounded-2xl border border-gray-100 bg-gray-50/50 hover:bg-white hover:border-blue-500/30 hover:shadow-md transition-all duration-300 group overflow-hidden">
                        <div class="p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <div class="w-10 h-10 rounded-full {{ $bgClass }} flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform">
                                    <i class="{{ $iconClass }} text-lg"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-bold text-gray-900 text-[13px] truncate group-hover:text-blue-600 transition-colors">
                                        {{ basename($file) }}
                                    </h4>
                                    <p class="text-[9px] text-gray-400 font-black uppercase tracking-widest mt-0.5">
                                        {{ strtoupper($ext) }} ФАЙЛ
                                    </p>
                                </div>
                            </div>
                            <a href="{{ asset('storage/' . $file) }}" download target="_blank" class="ml-3 w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 text-gray-400 hover:text-white hover:bg-blue-500 hover:border-blue-500 transition-colors shadow-sm shrink-0" title="Скачать">
                                <i class="fas fa-download text-xs"></i>
                            </a>
                        </div>

                        @if($isAudio)
                            <div class="px-4 pb-4 pt-1">
                                <audio controls class="w-full h-8 custom-audio-player outline-none">
                                    <source src="{{ asset('storage/' . $file) }}" type="{{ $mimeType }}">
                                </audio>
                            </div>
                        @endif

                        @if($isVideo)
                            <div class="px-2 pb-2">
                                <video controls class="w-full rounded-xl bg-black max-h-32 object-cover outline-none">
                                    <source src="{{ asset('storage/' . $file) }}" type="{{ $mimeType }}">
                                </video>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            
            {{-- Стили для аудиоплеера перенесли сюда, чтобы они загружались только если есть материалы --}}
            <style>
                .custom-audio-player::-webkit-media-controls-panel { background-color: #f3f4f6; border-radius: 9999px; }
                .custom-audio-player::-webkit-media-controls-play-button { background-color: #3b82f6; border-radius: 50%; color: white;}
                .custom-audio-player::-webkit-media-controls-timeline { margin-left: 8px; margin-right: 8px; }
            </style>
        </div>
        @endif

    </div>

</div>
@endsection