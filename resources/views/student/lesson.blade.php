@extends('layouts.student')

@section('title', $lesson->title)
@section('header', '')

@section('content')

<div x-data="lessonHeartbeat({ lessonId: {{ $lesson->id }} })" x-init="init()" class="hidden"></div>

@if(($recordingAccess->allowed ?? true) && !empty($kinescopeEmbedUrl))
    {{-- Kinescope Player SDK (H1451 W3) — enables W2 video-resume kinescope adapter --}}
    <script src="https://player.kinescope.io/latest/iframe.player.js" defer></script>
@endif
{{-- video-resume.js — общий адаптерный слой для currentTime/seekTo (транскрипт-
     синхронизация использовала этот же код инлайново до H1450, вынесено без
     изменения поведения). Грузится ВСЕГДА, независимо от флага video_resume —
     флаг решает только показ баннера «продолжить» и отправку позиции в
     heartbeat (см. onVideoTick() выше). --}}
@push('scripts')
    <script src="{{ asset('js/video-resume.js') }}?v={{ filemtime(public_path('js/video-resume.js')) }}" defer></script>
@endpush

<style>
    /* ============================================ */
    /* GRID-ЛЕЙАУТ УРОКА                            */
    /* ============================================ */
    .lesson-layout {
        display: block;
        width: 100%;
        position: relative;
    }
    .lesson-main-col {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    .lesson-side-col {
        width: 100%;
        margin-top: 1.5rem;
        display: flex;
        flex-direction: column;
        position: relative;
        z-index: 20;
    }
    @media (min-width: 768px) {
        .lesson-layout {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) 420px !important;
            gap: 1.5rem !important;
            align-items: start !important;
        }
        .lesson-main-col {
            min-width: 0 !important;
            width: auto !important;
        }
        .lesson-side-col {
            width: 420px !important;
            margin-top: 0 !important;
            position: sticky !important;
            top: 1.5rem !important;
            align-self: start !important;
        }
        .lesson-side-col.has-tabs {
            /* H4118: dvh с vh-фолбэком — 100vh на iPhone Safari включает панель браузера */
            max-height: calc(100vh - 3rem);
            max-height: calc(100dvh - 3rem);
        }
    }
</style>

@php
    $recordingAllowed = $recordingAccess->allowed ?? true;
    // --- УМНЫЙ ПАРСЕР ССЫЛОК YOUTUBE ---
    $cleanYoutubeId = null;
    $rawYoutube = $recordingAllowed ? ($youtubeId ?? $lesson->youtube_url ?? null) : null;
    if (!empty($rawYoutube)) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawYoutube, $match)) {
            $cleanYoutubeId = $match[1];
        } else {
            $cleanYoutubeId = $rawYoutube;
        }
    }

    // --- УМНЫЙ ПАРСЕР ССЫЛОК RUTUBE ---
    $cleanRutubeId = null;
    $rawRutube = $recordingAllowed ? ($rutubeId ?? $lesson->rutube_url ?? null) : null;
    if (!empty($rawRutube)) {
        if (preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9_-]+)/i', $rawRutube, $match)) {
            $cleanRutubeId = $match[1];
        } else {
            $cleanRutubeId = $rawRutube;
        }
    }

    // --- Kinescope pilot (H1451 W3): only when flag + pilot course + video_url ---
    $kinescopeEmbedUrl = $recordingAllowed ? ($kinescopeEmbedUrl ?? null) : null;
    $kinescopePilotActive = !empty($kinescopeEmbedUrl);

    // --- ПАРСЕР ТАЙМКОДОВ ---
    // function_exists-guard: вью включается дважды в одном PHP-процессе (например,
    // в нескольких тест-кейсах в рамках одного php artisan test), без guard'а
    // получаем fatal «Cannot redeclare function formatTimecodes()».
    if (!function_exists('formatTimecodes')) {
        function formatTimecodes($text) {
            if (!$text) return '';
            $pattern = '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/';
            $replacement = '<button @click.prevent="seekTo(\'$1\')" class="inline-flex items-center gap-1.5 px-2 py-0.5 mx-1 rounded-md bg-brand/10 text-brand border border-brand/30 hover:bg-brand hover:text-white font-mono text-sm font-bold transition-all shadow-sm group"><i class="fas fa-play text-[10px] opacity-60 group-hover:opacity-100 group-hover:text-white transition-colors"></i>$1</button>';
            return preg_replace($pattern, $replacement, $text);
        }
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
<div class="lesson-layout relative"
     x-data="{
         player: '{{ $kinescopePilotActive ? 'kinescope' : ($cleanRutubeId ? 'rutube' : ($cleanYoutubeId ? 'youtube' : 'none')) }}',
         currentTime: 0,
         videoDuration: {{ $resumeDuration ?? 'null' }},
         videoResumeEnabled: {{ $videoResumeEnabled ? 'true' : 'false' }},
         resumeOffered: {{ $resumePosition ? (int) $resumePosition : 'null' }},
         autoScroll: true,
         searchQuery: '',
         activeTab: '{{ $hasTranscript ? 'transcript' : ($hasAttachments ? 'materials' : 'notes') }}',

         activePlayerId() {
             if (this.player === 'kinescope') return 'kinescope-player';
             if (this.player === 'youtube') return 'youtube-player';
             if (this.player === 'rutube') return 'rutube-player';
             return null;
         },

         init() {
             // Один общий парсер postMessage на все хосты, живущие на этом
             // транспорте (YouTube/RuTube) — адаптеры в video-resume.js.
             window.addEventListener('message', (event) => {
                 if (!window.VideoResumeAdapters) return;
                 try {
                     let data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                     let adapter = window.VideoResumeAdapters[this.player];
                     let parsed = adapter && adapter.parseMessage ? adapter.parseMessage(data) : null;
                     if (parsed) { this.onVideoTick(parsed); }
                 } catch (e) {}
             });

             setInterval(() => {
                 if (!window.VideoResumeAdapters) return;
                 let id = this.activePlayerId();
                 let iframe = id ? document.getElementById(id) : null;
                 let adapter = window.VideoResumeAdapters[this.player];
                 if (!iframe || !adapter || !adapter.poll) return;
                 // pull-модель (RuTube/VK/Kinescope/Vimeo) шлёт время через onTick;
                 // push-модель (YouTube) просто подтверждает подписку — реальное
                 // время прилетит через 'message' и onVideoTick() выше.
                 adapter.poll(iframe, (tick) => this.onVideoTick(tick));
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

         onVideoTick({ time, duration }) {
             this.currentTime = time;
             if (duration) { this.videoDuration = duration; }

             // Мост к heartbeat (public/js/lesson-heartbeat.js слушает это же
             // событие) — только когда флаг включён, чтобы при video_resume=false
             // heartbeat вообще не знал о позиции (D10: прод-инертность).
             if (this.videoResumeEnabled) {
                 window.dispatchEvent(new CustomEvent('lesson-video-tick', {
                     detail: { position: Math.round(time), duration: duration ? Math.round(duration) : null },
                 }));
             }
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

             this.resumeOffered = null;

             let id = this.activePlayerId();
             let iframe = id ? document.getElementById(id) : null;
             let adapter = window.VideoResumeAdapters && window.VideoResumeAdapters[this.player];
             if (iframe && adapter && adapter.seek) {
                 adapter.seek(iframe, sec);
             }
             window.scrollTo({ top: 0, behavior: 'smooth' });
         },

         resumePlayback() {
             if (this.resumeOffered) { this.seekTo(this.resumeOffered); }
         }
     }">

    {{-- ========================================== --}}
    {{-- ЛЕВАЯ КОЛОНКА (Главная: Видео и Текст)     --}}
<div class="lesson-main-col">
        
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

                @if($kinescopePilotActive)
                    <iframe x-show="player === 'kinescope'"
                            id="kinescope-player"
                            src="{{ $kinescopeEmbedUrl }}"
                            class="w-full h-full absolute inset-0"
                            allowfullscreen
                            allow="autoplay; fullscreen; picture-in-picture; encrypted-media; gyroscope; accelerometer; clipboard-write;"
                            referrerpolicy="strict-origin-when-cross-origin">
                    </iframe>
                @endif

                {{-- «Продолжить с HH:MM» (H1450, video_resume). Показывается только пока
                     resumeOffered не сброшен (первый seekTo — свой или чужой — прячет её). --}}
                @if($videoResumeEnabled && $resumePosition)
                    @php
                        $resumeLabel = sprintf('%02d:%02d', intdiv($resumePosition, 60), $resumePosition % 60);
                    @endphp
                    <div x-show="resumeOffered" x-cloak
                         class="absolute bottom-4 left-4 right-4 sm:right-auto z-10 flex items-center gap-3 bg-black/80 backdrop-blur rounded-2xl px-4 py-3 shadow-xl border border-white/10">
                        <i class="fas fa-history text-brand"></i>
                        <span class="text-white text-sm font-bold">Продолжить с {{ $resumeLabel }}?</span>
                        <button type="button" @click="resumePlayback()"
                                class="px-3 py-1.5 rounded-lg bg-brand hover:bg-brand-hover text-white text-xs font-extrabold uppercase tracking-wide transition-colors">
                            Продолжить
                        </button>
                        <button type="button" @click="resumeOffered = null" title="Начать с начала"
                                class="text-gray-400 hover:text-white text-xs">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                @endif

                <div x-show="player === 'none'" class="absolute inset-0 flex items-center justify-center bg-[#19191C] px-6">
                    @if(!$recordingAllowed)
                        <div class="text-center text-white max-w-lg px-6" data-membership-recording-gate>
                            <i class="fas fa-lock text-4xl text-brand mb-4"></i>
                            <p class="text-lg font-bold mb-2">Запись закрыта уровнем членства</p>
                            <p class="text-gray-300 text-sm leading-relaxed">{{ $recordingAccess->notice() }}</p>
                            <a href="{{ route('membership.landing') }}" class="inline-flex mt-5 px-5 py-2.5 bg-brand hover:bg-brand-hover text-white font-bold rounded-xl">Выбрать уровень</a>
                        </div>
                    @elseif(!empty($upcomingSession))
                        {{-- Живое занятие ещё не прошло (например, оплаченное пробное): зовём в Zoom.
                             После занятия n8n зальёт запись, и здесь появится видео. --}}
                        <div class="text-center text-white max-w-md">
                            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#38BDF8]/15 border border-[#38BDF8]/30 text-[#38BDF8] text-[11px] font-bold uppercase tracking-widest mb-4">
                                <i class="fas fa-broadcast-tower"></i> Живое занятие
                            </div>
                            <p class="text-lg font-bold mb-1">Занятие состоится</p>
                            @if($upcomingSession->start)
                                <p class="text-gray-300 mb-5">{{ $upcomingSession->start->translatedFormat('d F Y, H:i') }} (МСК)</p>
                            @endif
                            @if($upcomingSession->link)
                                {{-- Трекинг-редирект (учёт посещаемости) → настоящий Zoom-URL. --}}
                                <a href="{{ route('class.join', $upcomingSession) }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#38BDF8] hover:bg-[#2da4dd] text-white font-bold rounded-xl transition-all shadow-lg shadow-[#38BDF8]/20">
                                    <i class="fas fa-video"></i> Подключиться к Zoom
                                </a>
                                <p class="text-gray-500 text-xs mt-4">Запись появится здесь после занятия.</p>
                            @else
                                <p class="text-gray-400 text-sm">Ссылку на подключение пришлем дополнительно.</p>
                            @endif
                        </div>
                    @else
                        <div class="text-center text-gray-600">
                            <i class="fas fa-video-slash text-5xl mb-3 opacity-30"></i>
                            <p class="text-sm font-medium">Видео недоступно</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Переключатель плееров --}}
            <div class="bg-[#141417] px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t border-[#2C2C32]">
                <div class="flex items-center text-gray-500 text-[10px] font-bold uppercase tracking-widest">
                    <i class="fas fa-server mr-2"></i> Источник видео
                </div>
                <div class="flex gap-2 flex-wrap">
                    @if($kinescopePilotActive)
                        <button type="button" @click="player = 'kinescope'" :class="player === 'kinescope' ? 'bg-brand text-white shadow-[0_0_15px_rgba(232,92,36,0.4)]' : 'bg-[#252529] text-gray-400 hover:text-white'" class="flex items-center px-4 py-2 rounded-xl text-xs font-bold transition-all duration-300">
                            <i class="fas fa-film mr-2 text-sm"></i> Kinescope
                        </button>
                    @endif
                    @if($cleanRutubeId && ($cleanYoutubeId || $kinescopePilotActive))
                        <button type="button" @click="player = 'rutube'" :class="player === 'rutube' ? 'bg-[#0057b7] text-white shadow-[0_0_15px_rgba(0,87,183,0.4)]' : 'bg-[#252529] text-gray-400 hover:text-white'" class="flex items-center px-4 py-2 rounded-xl text-xs font-bold transition-all duration-300">
                            <img src="https://rutube.ru/favicon.ico" :class="player === 'rutube' ? '' : 'opacity-50 grayscale'" class="w-3.5 h-3.5 mr-2 transition-all"> RuTube
                        </button>
                    @endif
                    @if($cleanYoutubeId && ($cleanRutubeId || $kinescopePilotActive))
                        <button type="button" @click="player = 'youtube'" :class="player === 'youtube' ? 'bg-[#ff0000] text-white shadow-[0_0_15px_rgba(255,0,0,0.4)]' : 'bg-[#252529] text-gray-400 hover:text-white'" class="flex items-center px-4 py-2 rounded-xl text-xs font-bold transition-all duration-300">
                            <i class="fab fa-youtube mr-2 text-sm" :class="player === 'youtube' ? 'text-white' : 'text-gray-500'"></i> YouTube
                        </button>
                    @endif
                </div>
            </div>
        </div>

        @if($recordingAccess->notice())
            <div class="mt-3 rounded-2xl border border-amber-300/40 bg-amber-50 px-5 py-4 text-sm text-amber-950" data-membership-recording-notice>
                {{ $recordingAccess->notice() }}
            </div>
        @endif

        {{-- ПЛАШКА: Название урока + Навигация + Действия --}}
<div x-data="{ navOpen: false }" 
     class="bg-white rounded-3xl p-5 md:p-6 shadow-sm border border-gray-100 relative transition-[z-index]"
     :class="navOpen ? 'z-40' : 'z-10'">
    
    @php
        $lessonIndex = $lessons->pluck('id')->search($lesson->id) + 1;
        $totalLessons = $lessons->count();
        
        // Текущий список доступных блоков для студента (для выпадашки)
        $unlockedBlocksSet = collect($unlockedTariffs ?? [])
            ->map(fn($t) => str_replace('block_', '', $t))
            ->filter(fn($v) => $v === 'full' || is_numeric($v))
            ->values();
        $hasFull = $unlockedBlocksSet->contains('full');
    @endphp

    {{-- Верхний ряд: метки и кнопки --}}
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        
        {{-- Левая часть: заголовок --}}
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3 mb-2 flex-wrap">
                <span class="bg-brand/10 text-brand px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase tracking-wider">
                    Урок {{ $lessonIndex }} из {{ $totalLessons }}
                </span>
                @if($lesson->duration ?? null)
                    <span class="text-xs md:text-sm text-gray-500 font-bold flex items-center">
                        <i class="far fa-clock mr-1.5 text-gray-400"></i> {{ $lesson->duration }} мин
                    </span>
                @endif
            </div>
            <h1 class="text-lg md:text-xl font-extrabold text-[#1A1A1A] leading-tight">
                {{ $lesson->title }}
            </h1>
        </div>

        {{-- Правая часть: действия --}}
        <div class="flex items-center gap-2 md:gap-3 shrink-0 flex-wrap">

            {{-- === КНОПКА: Чат курса (если URL задан в админке) === --}}
            <x-course-chat-button :url="$course->chat_url" />

            {{-- === КНОПКА: Написать куратору (синяя, заметная) === --}}
            <a href="https://t.me/rusamskrtam" 
               target="_blank" 
               rel="noopener noreferrer"
               title="Написать куратору"
               class="inline-flex items-center gap-2 px-4 py-2.5 md:py-3 rounded-xl bg-[#229ED9] hover:bg-[#1b87b8] text-white text-xs md:text-sm font-extrabold leading-none transition-all shadow-[0_5px_15px_rgba(34,158,217,0.3)] hover:-translate-y-0.5 active:translate-y-0 uppercase tracking-wide">
                <i class="fab fa-telegram-plane text-base"></i>
                <span class="hidden md:inline">Куратору</span>
            </a>

            {{-- === КНОПКА: Завершить / Пройден === --}}
            @if(auth()->user()->completedLessons->contains($lesson->id))
                <div class="inline-flex justify-center items-center gap-2 px-4 py-2.5 md:py-3 rounded-xl bg-green-50 text-green-600 text-xs md:text-sm font-extrabold leading-none border border-green-200 cursor-default">
                    <i class="fas fa-check-circle mr-2 text-base"></i> Пройден
                </div>
            @else
                <form action="{{ route('student.lesson.complete', [$course->slug, $lesson->id]) }}" method="POST">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 md:py-3 bg-brand hover:bg-brand-hover text-white rounded-xl font-extrabold text-xs md:text-sm leading-none transition-all shadow-[0_5px_15px_rgba(232,92,36,0.3)] hover:-translate-y-0.5 active:translate-y-0 uppercase tracking-wide">
    Завершить
</button>
                </form>
            @endif
        </div>
    </div>

    {{-- === ВЫПАДАЮЩАЯ НАВИГАЦИЯ ПО УРОКАМ КУРСА === --}}
<div class="mt-5 pt-5 border-t border-gray-100 relative">
        
        <button type="button" 
        @click="navOpen = !navOpen" 
        @click.away="navOpen = false"
        class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-gray-50 hover:bg-gray-100 border border-gray-200 transition-all text-left group">
    <div class="flex items-center gap-3 min-w-0">
        <div class="w-9 h-9 rounded-lg bg-brand/10 text-brand flex items-center justify-center shrink-0">
            <i class="fas fa-list-ul text-sm"></i>
        </div>
        <div class="min-w-0">
            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-wider">Навигация по курсу</p>
            <p class="text-sm font-extrabold text-gray-900 truncate">Перейти к другому уроку</p>
        </div>
    </div>
    <i class="fas fa-chevron-down text-gray-400 transition-transform shrink-0" 
       :class="navOpen ? 'rotate-180' : ''"></i>
        </button>
        
        <div x-show="navOpen" 
     x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-1"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-1"
     class="absolute top-full left-0 right-0 mt-2 bg-white rounded-2xl shadow-xl border border-gray-200 z-30 max-h-[60vh] overflow-y-auto custom-scrollbar">
            
            <div class="p-2">
                @foreach($lessons as $idx => $l)
                    @php
                        // Проверяем доступ к уроку (та же логика, что в контроллере)
                        $isUnlocked = $hasFull || $unlockedBlocksSet->contains((string)$l->block_number);
                        $isCurrent = $l->id === $lesson->id;
                        $isCompleted = auth()->user()->completedLessons->contains($l->id);
                    @endphp

                    @if($isUnlocked && !$isCurrent)
                        <a href="{{ route('student.lesson', [$course->slug, $l->id]) }}" 
                           class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-gray-50 transition-colors group">
                            <span class="w-7 h-7 shrink-0 flex items-center justify-center rounded-md bg-gray-100 text-gray-600 text-xs font-extrabold group-hover:bg-brand group-hover:text-white transition-colors">
                                {{ $idx + 1 }}
                            </span>
                            <span class="flex-1 text-sm font-bold text-gray-800 truncate group-hover:text-brand transition-colors">
                                {{ $l->title }}
                            </span>
                            @if($isCompleted)
                                <i class="fas fa-check-circle text-green-500 text-sm shrink-0" title="Пройден"></i>
                            @endif
                        </a>
                    @elseif($isCurrent)
                        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-brand/10 border border-brand/30 cursor-default">
                            <span class="w-7 h-7 shrink-0 flex items-center justify-center rounded-md bg-brand text-white text-xs font-extrabold">
                                {{ $idx + 1 }}
                            </span>
                            <span class="flex-1 text-sm font-extrabold text-brand truncate">
                                {{ $l->title }}
                            </span>
                            <span class="text-[10px] font-extrabold uppercase tracking-wider text-brand bg-white px-2 py-0.5 rounded shrink-0">
                                Сейчас
                            </span>
                        </div>
                    @else
                        {{-- Закрытый урок --}}
                        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg opacity-60 cursor-not-allowed">
                            <span class="w-7 h-7 shrink-0 flex items-center justify-center rounded-md bg-gray-200 text-gray-400 text-xs font-extrabold">
                                <i class="fas fa-lock text-[10px]"></i>
                            </span>
                            <span class="flex-1 text-sm font-bold text-gray-400 truncate">
                                {{ $l->title }}
                            </span>
                            <span class="text-[10px] font-bold text-gray-400 bg-gray-100 px-2 py-0.5 rounded shrink-0">
                                Блок {{ $l->block_number }}
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>

        {{-- КОНТЕНТ УРОКА (Описание) --}}
        @if($lesson->content || $lesson->topic)
        <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 relative z-10">
            <div class="prose prose-lg max-w-none text-gray-800 leading-relaxed font-nunito font-medium marker:bg-brand/20 marker:text-[#1A1A1A]">
                {!! formatTimecodes(nl2br(e($lesson->content ?? $lesson->topic))) !!}
            </div>
        </div>
        @endif

        @if(!empty($hindiDrillsUrl))
        <section class="font-nunito" data-testid="hindi-transcript-drills-cta">
            <a href="{{ $hindiDrillsUrl }}"
               class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6 flex items-center gap-4 hover:border-brand/30 hover:shadow-lg transition-all">
                <span class="w-10 h-10 rounded-xl bg-orange-50 text-brand flex items-center justify-center shrink-0">
                    <i class="fas fa-pen-fancy"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-gray-900">Упражнения</span>
                    <span class="block text-sm text-gray-500">Из расшифровки этого занятия — вставить слово или перевести</span>
                </span>
                <i class="fas fa-chevron-right text-gray-300"></i>
            </a>
        </section>
        @endif

        {{-- H3521: Learn Your Way — вкладка рендерится только при LYW_ENABLED (default OFF). --}}
        @if(!empty($lywUrl))
        <section class="font-nunito" data-testid="lyw-lesson-tab">
            <a href="{{ $lywUrl }}"
               class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6 flex items-center gap-4 hover:border-brand/30 hover:shadow-lg transition-all">
                <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                    <i class="fas fa-route"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-extrabold text-gray-900">Learn Your Way</span>
                    <span class="block text-sm text-gray-500">Персональный разбор занятия: текст под ваш уровень и интерес, вопросы, мнемоники, mind map</span>
                </span>
                <i class="fas fa-chevron-right text-gray-300"></i>
            </a>
        </section>
        @endif

        {{-- ДОМАШНЕЕ ЗАДАНИЕ — внутри центральной колонки (ширина как плеер/описание) --}}
        @if($homeworkOpen ?? $lesson->homework_enabled)
            @include('student.partials.homework')
        @else
            {{-- Явное состояние «ДЗ нет», чтобы студент не гадал, задано оно или ещё нет. --}}
            <section class="font-nunito">
                <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6 flex items-center gap-3 text-gray-500">
                    <i class="fas fa-mug-hot text-gray-300 text-lg shrink-0"></i>
                    <p class="text-sm">Домашнего задания для этого урока нет.</p>
                </div>
            </section>
        @endif
    </div>

    {{-- ========================================== --}}
    {{-- ПРАВАЯ КОЛОНКА (Инструменты: Транскрипт / Заметки / Файлы) --}}
    {{-- ========================================== --}}
    {{-- Если нет ни транскрипта, ни материалов — колонка уже и без sticky-растяжки --}}
<div class="lesson-side-col {{ $showTabs ? 'has-tabs' : '' }}">
        
        {{-- ПЕРЕКЛЮЧАТЕЛЬ ТАБОВ (Показываем только если есть выбор) --}}
        @if($showTabs)
        <div class="flex items-center bg-gray-100/80 p-1.5 rounded-2xl mb-4 shrink-0 shadow-inner overflow-x-auto custom-scrollbar">
            @if($hasTranscript)
            <button @click="activeTab = 'transcript'" 
                    class="flex-1 flex items-center justify-center gap-1.5 py-2.5 px-2 rounded-xl text-[13px] font-extrabold transition-all duration-300"
                    :class="activeTab === 'transcript' ? 'bg-white text-brand shadow-sm' : 'text-gray-500 hover:text-gray-700 hover:bg-white/50'">
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
                           class="w-full pl-9 pr-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-brand outline-none transition-all placeholder-gray-400 shadow-inner">
                </div>
            </div>

            <div x-ref="scrollContainer" class="flex-1 overflow-y-auto p-3 pb-10 space-y-1 custom-scrollbar bg-white" @wheel="autoScroll = false" @touchstart="autoScroll = false">
                @foreach($transcriptSentences as $sentence)
                    <button @click.prevent="seekTo({{ $sentence['start'] }})"
                            x-show="matches({{ json_encode($sentence['safe_text']) }})"
                            :class="(currentTime >= {{ $sentence['start'] }} && currentTime <= {{ $sentence['end'] }}) 
                                ? 'is-active-sentence bg-[#F4F1EA] border-l-4 border-brand text-[#1A1A1A] font-bold z-10 relative' 
                                : 'hover:bg-gray-50 border-l-4 border-transparent text-gray-600 font-medium'"
                            class="block w-full text-left p-3 rounded-r-xl transition-all duration-200 group">
                        
                        <span class="inline-flex items-center gap-1.5 bg-white border border-gray-200 text-gray-400 text-[10px] font-mono font-extrabold px-2 py-0.5 rounded mr-2 shadow-sm group-hover:border-brand group-hover:text-brand transition-colors"
                              :class="(currentTime >= {{ $sentence['start'] }} && currentTime <= {{ $sentence['end'] }}) ? '!bg-brand !border-brand !text-white' : ''">
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
     class="bg-yellow-50 rounded-3xl p-5 md:p-6 shadow-sm border border-yellow-200 flex flex-col
            {{ $showTabs ? 'flex-1 min-h-[500px]' : 'h-auto' }}">
     
    <h3 class="font-extrabold text-yellow-900 mb-4 flex items-center text-sm uppercase tracking-wide shrink-0">
        <i class="far fa-edit mr-2 text-yellow-600"></i> Мои заметки
    </h3>
    
    <form action="{{ route('student.lesson.note', [$course->slug, $lesson->id]) }}" method="POST" class="flex flex-col flex-1">
        @csrf
        <textarea name="notes" 
                  rows="{{ $showTabs ? 8 : 14 }}" 
                  class="w-full bg-white/50 border-transparent rounded-2xl p-4 focus:ring-2 focus:ring-yellow-400 focus:bg-white text-gray-800 transition resize-y font-medium text-sm placeholder-yellow-700/50 custom-scrollbar {{ $showTabs ? 'flex-1' : '' }}" 
                  placeholder="Важные мысли из урока, инсайты, вопросы к преподавателю...">{{ $currentNote ?? '' }}</textarea>
        
        <button type="submit" class="mt-4 w-full bg-yellow-400 hover:bg-yellow-500 text-yellow-900 py-3.5 rounded-2xl font-extrabold text-sm transition-colors shadow-sm shrink-0">
            Сохранить изменения
        </button>
    </form>

    {{-- Декоративная подпись внизу, заполняет место и делает блок «завершённым» --}}
    @unless($showTabs)
        <div class="mt-4 pt-4 border-t border-yellow-200/60 flex items-start gap-3 shrink-0">
            <div class="w-8 h-8 rounded-lg bg-yellow-200/50 text-yellow-700 flex items-center justify-center shrink-0">
                <i class="fas fa-lightbulb text-sm"></i>
            </div>
            <div class="text-[11px] text-yellow-900/70 leading-relaxed">
                <p class="font-extrabold uppercase tracking-wider mb-1">Совет</p>
                <p>Записывайте ключевые мысли и тайм-коды интересных моментов — это ускорит возврат к материалу при подготовке к следующим урокам.</p>
            </div>
        </div>
    @endunless
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
                            <a href="{{ route('student.lesson.material', [$course->slug, $lesson->id, basename($file)]) }}" download target="_blank" class="ml-3 w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 text-gray-400 hover:text-white hover:bg-blue-500 hover:border-blue-500 transition-colors shadow-sm shrink-0" title="Скачать">
                                <i class="fas fa-download text-xs"></i>
                            </a>
                        </div>

                        @if($isAudio)
                            <div class="px-4 pb-4 pt-1">
                                <audio controls class="w-full h-8 custom-audio-player outline-none">
                                    <source src="{{ route('student.lesson.material', [$course->slug, $lesson->id, basename($file)]) }}" type="{{ $mimeType }}">
                                </audio>
                            </div>
                        @endif

                        @if($isVideo)
                            <div class="px-2 pb-2">
                                <video controls playsinline webkit-playsinline class="w-full rounded-xl bg-black max-h-32 object-cover outline-none">
                                    <source src="{{ route('student.lesson.material', [$course->slug, $lesson->id, basename($file)]) }}" type="{{ $mimeType }}">
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
