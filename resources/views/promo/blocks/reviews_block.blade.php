<section class="py-16 lg:py-24 bg-[#F9FAFB] overflow-hidden relative font-nunito" id="otz"
         x-data="{
            // Лайтбокс для скриншотов (картинок).
            lightboxOpen: false,
            lightboxMedia: '',

            // Видео: выбор плеера + запоминание выбора (как в карусели на главной).
            videoOpen: false,
            embedUrl: '',
            chooserOptions: [],
            cookieName: 'preferredVideoPlatform',
            getPref() {
                const prefix = this.cookieName + '=';
                const pair = document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(prefix));
                return pair ? decodeURIComponent(pair.substring(prefix.length)) : null;
            },
            savePref(platform) {
                const d = new Date();
                d.setTime(d.getTime() + 365 * 24 * 60 * 60 * 1000);
                document.cookie = this.cookieName + '=' + encodeURIComponent(platform) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
            },
            open(options) {
                if (!options || options.length === 0) return;
                if (options.length === 1) {
                    this.embedUrl = options[0].embed;
                    this.chooserOptions = [];
                } else {
                    const pref = this.getPref();
                    const match = pref ? options.find(o => o.platform === pref) : null;
                    if (match) {
                        this.embedUrl = match.embed;
                        this.chooserOptions = [];
                    } else {
                        this.chooserOptions = options;
                        this.embedUrl = '';
                    }
                }
                this.videoOpen = true;
            },
            pick(opt) {
                this.savePref(opt.platform);
                this.embedUrl = opt.embed;
                this.chooserOptions = [];
            },
            closeVideo() {
                this.videoOpen = false;
                this.embedUrl = '';
                this.chooserOptions = [];
            }
         }"
         @keydown.escape.window="closeVideo()">
    
    {{-- Фоновый декоративный блик --}}
    <div class="absolute top-0 right-0 w-[40rem] h-[40rem] bg-brand/5 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>

    <div class="container mx-auto px-4 relative z-10">
        
        {{-- ЗАГОЛОВОК И НАВИГАЦИЯ --}}
        <div class="relative mb-12 md:mb-16">
            <div class="max-w-3xl mx-auto text-center flex flex-col items-center">
                <h2 class="text-3xl md:text-4xl lg:text-4xl font-extrabold text-[#101010] mb-4 tracking-tight">
                    {{ $data['title'] ?? 'Отзывы учеников' }}
                </h2>
                <div class="w-20 h-1.5 bg-brand rounded-full mx-auto"></div>
            </div>

            {{-- Кнопки (Десктоп) --}}
            <div class="hidden lg:flex gap-3 absolute right-0 bottom-0 top-0 items-center">
                <button @click="$refs.slider.scrollBy({left: -380, behavior: 'smooth'})"
                        class="w-12 h-12 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-brand hover:border-brand hover:text-white hover:shadow-[0_4px_12px_rgba(232,92,36,0.3)] transition-all bg-white shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
                </button>
                <button @click="$refs.slider.scrollBy({left: 380, behavior: 'smooth'})"
                        class="w-12 h-12 rounded-full border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-brand hover:border-brand hover:text-white hover:shadow-[0_4px_12px_rgba(232,92,36,0.3)] transition-all bg-white shadow-sm">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
                </button>
            </div>
        </div>

        {{-- СЛАЙДЕР С КАРТОЧКАМИ --}}
        @if(!empty($data['reviews']))
        <div class="relative">
            <div class="absolute top-0 left-0 w-12 h-full bg-gradient-to-r from-[#F9FAFB] to-transparent z-20 pointer-events-none md:hidden"></div>
            <div class="absolute top-0 right-0 w-12 h-full bg-gradient-to-l from-[#F9FAFB] to-transparent z-20 pointer-events-none md:hidden"></div>

            <div x-ref="slider" class="flex items-stretch gap-5 md:gap-6 overflow-x-auto snap-x snap-mandatory pb-12 pt-10 hide-scrollbar -mx-4 px-4 md:mx-0 md:px-0">

                @foreach($data['reviews'] as $review)
                    @php
                        // Источники плеера: каждое заполненное поле → отдельная кнопка
                        // выбора. Платформу определяем по URL (метка/цвет), дедуп по платформе.
                        // embed()/poster() — общий помощник (как в карусели «беседы» на главной).
                        $mk = function (?string $url) {
                            if (! $url || ! ($e = \App\Support\VideoEmbed::embed($url))) {
                                return null;
                            }
                            $p = \Illuminate\Support\Str::contains($url, 'youtu') ? 'youtube'
                                : (\Illuminate\Support\Str::contains($url, 'rutube') ? 'rutube'
                                : (\Illuminate\Support\Str::contains($url, ['vk.com', 'vkvideo']) ? 'vk' : 'video'));
                            $labels = ['youtube' => 'YouTube', 'rutube' => 'RuTube', 'vk' => 'ВКонтакте', 'video' => 'Смотреть'];

                            return ['platform' => $p, 'label' => $labels[$p], 'embed' => $e];
                        };

                        $options = [];
                        foreach ([
                            $review['youtube_link'] ?? null,
                            $review['rutube_link'] ?? null,
                            $review['vk_link'] ?? null,
                            $review['video_link'] ?? $review['video'] ?? null,
                        ] as $u) {
                            if ($opt = $mk($u)) {
                                $options[$opt['platform']] = $opt; // ключ = платформа → дедуп
                            }
                        }
                        $options = array_values($options);

                        $poster = \App\Support\VideoEmbed::poster(($review['youtube_link'] ?? null) ?: ($review['video_link'] ?? null));
                    @endphp

                    <div class="flex-shrink-0 w-[85vw] sm:w-[320px] md:w-[360px] snap-center pt-6">
                        <div class="relative bg-white rounded-3xl p-6 md:p-8 shadow-[0_8px_30px_rgba(0,0,0,0.04)] border border-gray-100 flex flex-col h-full hover:shadow-[0_15px_40px_rgba(232,92,36,0.08)] hover:border-brand/20 transition-all duration-300 group">
                            
                            {{-- Аватар --}}
                            <div class="absolute -top-8 left-6 w-16 h-16 rounded-full border-4 border-white shadow-md bg-gray-100 overflow-hidden z-20">
                                @if(!empty($review['avatar']))
                                    <img src="{{ Storage::url($review['avatar']) }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-orange-100 to-orange-50 text-brand font-black text-xl">
                                        {{ mb_substr($review['name'], 0, 1) }}
                                    </div>
                                @endif
                            </div>

                            {{-- Декоративная кавычка --}}
                            <div class="absolute top-4 right-5 text-orange-50 group-hover:text-orange-100 transition-colors duration-500 z-0">
                                <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                            </div>

                            {{-- Имя, Дата --}}
                            <div class="pt-6 mb-5 flex justify-between items-start relative z-10">
                                <div>
                                    <h4 class="font-extrabold text-[#101010] text-lg leading-tight">{{ $review['name'] }}</h4>
                                    <div class="flex items-center gap-3 mt-1.5">
                                        <div class="flex text-[#FFB800] gap-0.5">
                                            @for($i=0; $i<5; $i++)
                                                <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                                            @endfor
                                        </div>
                                        @if(!empty($review['date']))
                                            <span class="text-[10px] font-extrabold text-gray-300 uppercase tracking-widest">{{ $review['date'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- ВИДЕООТЗЫВ — реальный постер + выбор плеера (как на главной) --}}
                            @if(!empty($options))
                                <button type="button"
                                        @click="open(@js($options))"
                                        class="relative w-full h-48 mb-5 rounded-2xl overflow-hidden bg-gray-900 group/video cursor-pointer shadow-sm border border-gray-100 block">

                                    {{-- Постер: кадр ролика (YouTube) либо тёмный fallback --}}
                                    @if($poster)
                                        <img src="{{ $poster }}" alt="{{ $review['name'] ?? '' }}" loading="lazy"
                                             class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover/video:scale-105">
                                        <div class="absolute inset-0 bg-black/20 group-hover/video:bg-black/35 transition-colors"></div>
                                    @else
                                        <div class="absolute inset-0 bg-gradient-to-br from-[#1a1c23] via-[#101010] to-black opacity-95 transition-transform duration-700 group-hover/video:scale-105"></div>
                                        <div class="absolute -top-10 -right-10 w-32 h-32 bg-brand rounded-full mix-blend-screen filter blur-[45px] opacity-30 group-hover/video:opacity-50 transition-opacity duration-500"></div>
                                        <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-blue-500 rounded-full mix-blend-screen filter blur-[45px] opacity-20 group-hover/video:opacity-30 transition-opacity duration-500"></div>
                                    @endif

                                    {{-- Бейджик --}}
                                    <div class="absolute top-3 left-3 flex items-center gap-2 bg-black/40 backdrop-blur-md px-3 py-1.5 rounded-full border border-white/10 z-10">
                                        <span class="relative flex h-2 w-2">
                                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                          <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                                        </span>
                                        <span class="text-white text-[9px] font-extrabold tracking-widest uppercase mt-0.5">Смотреть</span>
                                    </div>

                                    {{-- Стеклянная кнопка Play --}}
                                    <div class="absolute inset-0 flex items-center justify-center z-10">
                                        <div class="w-16 h-16 bg-white/10 backdrop-blur-md border border-white/20 text-white rounded-full flex items-center justify-center shadow-[0_8px_32px_rgba(0,0,0,0.3)] transition-all duration-300 group-hover/video:bg-brand group-hover/video:border-brand group-hover/video:scale-110 group-hover/video:shadow-[0_8px_25px_rgba(232,92,36,0.5)]">
                                            <svg class="w-7 h-7 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </div>
                                    </div>
                                </button>
                            @endif

                            {{-- КРУПНАЯ ЦИТАТА (акцент) --}}
                            @if(!empty($review['quote']))
                                <p class="relative z-10 mb-4 text-[#8A3D17] font-extrabold text-lg md:text-xl leading-snug tracking-tight">
                                    «{{ $review['quote'] }}»
                                </p>
                            @endif

                            {{-- ТЕКСТ ОТЗЫВА --}}
                            @if(!empty($review['text']))
                                <div class="relative mb-6 flex-grow"
                                     x-data="{ expanded: false, showButton: false }" 
                                     x-init="$nextTick(() => { showButton = $refs.textNode.scrollHeight > $refs.textNode.clientHeight })">
                                    
                                    <div class="text-gray-600 text-[15px] leading-relaxed overflow-hidden transition-all duration-300"
                                         :class="expanded ? '' : 'line-clamp-4'"
                                         x-ref="textNode">
                                        {{ $review['text'] }}
                                    </div>
                                    
                                    <div x-show="!expanded && showButton" class="absolute bottom-6 left-0 w-full h-10 bg-gradient-to-t from-white to-transparent pointer-events-none"></div>

                                    <button x-show="showButton" 
                                            @click="expanded = !expanded"
                                            class="text-brand text-[11px] font-extrabold uppercase tracking-widest mt-2 hover:text-[#d6501f] transition-colors focus:outline-none">
                                        <span x-text="expanded ? 'Свернуть' : 'Читать полностью'"></span>
                                    </button>
                                </div>
                            @endif

                            {{-- СКРИНШОТЫ --}}
                            @if(!empty($review['images']))
                                <div class="flex gap-2 overflow-x-auto pb-1 mt-auto hide-scrollbar">
                                    @foreach($review['images'] as $image)
                                        @php
                                            $imageUrl = null;
                                            
                                            // Проверяем: это цифра от Куратора или старый путь?
                                            if (is_numeric($image)) {
                                                $imageUrl = \Awcodes\Curator\Models\Media::find($image)?->url;
                                            } else {
                                                $imageUrl = Storage::url($image);
                                            }
                                        @endphp

                                        @if($imageUrl)
                                            <div class="shrink-0 cursor-zoom-in group/img"
                                                 @click="lightboxOpen = true; lightboxMedia = '{{ $imageUrl }}'">
                                                <div class="w-16 h-16 rounded-xl overflow-hidden border border-gray-100 relative">
                                                    <img src="{{ $imageUrl }}" class="w-full h-full object-cover group-hover/img:scale-110 transition-transform duration-500">
                                                    <div class="absolute inset-0 bg-black/20 opacity-0 group-hover/img:opacity-100 transition-opacity flex items-center justify-center">
                                                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" /></svg>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                        </div>
                    </div>
                @endforeach

            </div>
        </div>
        @endif
    </div>

    {{-- ЛАЙТБОКС СКРИНШОТОВ --}}
    <div x-show="lightboxOpen"
         x-transition.opacity.duration.300ms
         class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#101010]/95 p-4 sm:p-8 backdrop-blur-md"
         style="display: none;"
         @keydown.escape.window="lightboxOpen = false; lightboxMedia = ''">

        <button @click="lightboxOpen = false; lightboxMedia = ''" class="absolute top-6 right-6 w-12 h-12 bg-white/10 hover:bg-brand text-white rounded-full flex items-center justify-center transition-colors z-[10000]">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>

        <div @click.outside="lightboxOpen = false; lightboxMedia = ''" class="relative max-w-5xl w-full flex justify-center items-center rounded-2xl overflow-hidden mx-auto transition-all duration-300">
            <template x-if="lightboxOpen">
                <img :src="lightboxMedia" class="max-w-full max-h-[85vh] object-contain rounded-2xl shadow-2xl">
            </template>
        </div>
    </div>

    {{-- ВИДЕО: чузер плеера + iframe (как в карусели на главной) --}}
    <template x-teleport="body">
        <div x-show="videoOpen"
             style="display: none;"
             @click.self="closeVideo()"
             class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#101010]/90 backdrop-blur-md p-4 sm:p-6"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">

            <button type="button" @click="closeVideo()" aria-label="Закрыть"
                    class="fixed top-4 right-4 md:top-6 md:right-6 w-11 h-11 bg-white/15 hover:bg-brand text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-colors z-[10000]">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>

            {{-- 1. Выбор плеера --}}
            <template x-if="!embedUrl && chooserOptions.length > 0">
                <div class="relative w-full max-w-sm bg-[#161b28] border border-gray-700/60 rounded-2xl p-5 shadow-2xl">
                    <h3 class="text-white text-base font-bold text-center mb-1">В каком плеере смотреть?</h3>
                    <p class="text-gray-400 text-xs text-center mb-4">Выберите, где смотреть</p>
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <template x-for="opt in chooserOptions" :key="opt.platform">
                            <button type="button" x-on:click="pick(opt)"
                                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-white rounded-lg transition-all duration-150 hover:brightness-110 active:scale-95"
                                    :style="{
                                        backgroundColor: opt.platform === 'youtube' ? '#FF0000' : (opt.platform === 'rutube' ? '#000000' : (opt.platform === 'vk' ? '#0077FF' : '#E85C24')),
                                        boxShadow: opt.platform === 'youtube' ? '0 4px 14px rgba(255,0,0,0.4)' : (opt.platform === 'rutube' ? '0 4px 14px rgba(0,0,0,0.6)' : (opt.platform === 'vk' ? '0 4px 14px rgba(0,119,255,0.4)' : 'none')),
                                        border: opt.platform === 'rutube' ? '1px solid rgba(255,255,255,0.15)' : 'none'
                                    }">
                                <template x-if="opt.platform === 'youtube'">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                                </template>
                                <template x-if="opt.platform !== 'youtube'">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </template>
                                <span x-text="opt.label"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>

            {{-- 2. Плеер --}}
            <template x-if="!!embedUrl">
                <div class="relative w-full max-w-5xl bg-black rounded-2xl md:rounded-[2rem] overflow-hidden shadow-2xl aspect-video">
                    <iframe :src="embedUrl" class="w-full h-full border-0 absolute inset-0"
                            allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe>
                </div>
            </template>
        </div>
    </template>

    <style>
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</section>