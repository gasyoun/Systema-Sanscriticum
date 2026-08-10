@php
    $d = $data ?? [];

    // --- Парсим каждый источник в готовый embed-URL ---

    // YouTube → https://www.youtube.com/embed/{id}
    $ytEmbed = null;
    $rawYt = $d['youtube_url'] ?? null;
    if (!empty($rawYt)) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawYt, $m)) {
            $ytEmbed = 'https://www.youtube.com/embed/'.$m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($rawYt))) {
            $ytEmbed = 'https://www.youtube.com/embed/'.trim($rawYt);
        } else {
            $ytEmbed = $rawYt; // вдруг уже готовый embed
        }
    }

    // RuTube → https://rutube.ru/play/embed/{id}
    $rtEmbed = null;
    $rawRt = $d['rutube_url'] ?? null;
    if (!empty($rawRt)) {
        if (preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9_-]+)/i', $rawRt, $m)) {
            $rtEmbed = 'https://rutube.ru/play/embed/'.$m[1];
        } else {
            $rtEmbed = $rawRt;
        }
    }

    // ВК → https://vk.com/video_ext.php?oid={oid}&id={id}
    $vkEmbed = null;
    $rawVk = $d['vk_url'] ?? null;
    if (!empty($rawVk)) {
        if (str_contains($rawVk, 'video_ext.php')) {
            $vkEmbed = $rawVk; // уже embed-ссылка (с hash для приватных)
        } elseif (preg_match('/video(-?\d+)_(\d+)/i', $rawVk, $m)) {
            $vkEmbed = 'https://vk.com/video_ext.php?oid='.$m[1].'&id='.$m[2];
        } else {
            $vkEmbed = $rawVk;
        }
    }

    // Прочий iframe (запасной / обратная совместимость со старым video_url)
    $otherEmbed = !empty($d['video_url']) ? $d['video_url'] : null;

    // Собираем только непустые источники в фиксированном порядке
    $sources = array_filter([
        'vk'      => $vkEmbed,
        'youtube' => $ytEmbed,
        'rutube'  => $rtEmbed,
        'other'   => $otherEmbed,
    ]);
@endphp

@if(!empty($sources))
<section class="py-16 bg-white">
    <div class="container mx-auto px-4">

        {{-- Заголовок блока (если есть) --}}
        @if(!empty($d['title']))
        <div class="text-center mb-10">
            <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 tracking-tight">
                {{ $d['title'] }}
            </h2>
            <div class="w-24 h-1.5 bg-brand mx-auto mt-4 rounded-full opacity-80"></div>
        </div>
        @endif

        <div x-data="videoSwitch({{ \Illuminate\Support\Js::from($sources) }})" x-init="init()" class="max-w-4xl mx-auto">

            {{-- Переключатель плееров (если источников больше одного) --}}
            <div class="flex flex-wrap gap-2 justify-center mb-5" x-show="keys.length > 1">
                <template x-for="k in keys" :key="k">
                    <button type="button" @click="select(k)"
                            class="px-4 py-2 rounded-full text-sm font-bold border transition-colors"
                            :class="current === k
                                ? 'bg-brand text-white border-brand'
                                : 'bg-white text-gray-700 border-gray-200 hover:border-brand hover:text-brand'"
                            x-text="labels[k]"></button>
                </template>
            </div>

            {{-- Видео --}}
            <div class="relative rounded-2xl overflow-hidden shadow-xl border border-gray-100 bg-black">
                <div class="relative w-full" style="padding-top: 56.25%;"> {{-- 16:9 --}}
                    <template x-for="k in keys" :key="k">
                        <iframe x-show="current === k"
                                :src="current === k ? sources[k] : ''"
                                class="absolute top-0 left-0 w-full h-full"
                                frameborder="0"
                                allow="autoplay; encrypted-media; fullscreen; picture-in-picture;"
                                allowfullscreen></iframe>
                    </template>
                </div>
            </div>

        </div>

    </div>
</section>

@once
<script>
    // Переключатель плееров с запоминанием выбора в localStorage.
    // Ключ глобальный: если юзер предпочёл RuTube/ВК — он откроется и на других страницах.
    function videoSwitch(sources) {
        return {
            sources: sources,
            keys: Object.keys(sources),
            labels: { vk: 'ВК Видео', youtube: 'YouTube', rutube: 'RuTube', other: 'Видео' },
            current: null,
            init() {
                const pref = localStorage.getItem('preferred_video_player');
                this.current = (pref && this.sources[pref]) ? pref : this.keys[0];
            },
            select(k) {
                this.current = k;
                localStorage.setItem('preferred_video_player', k);
            },
        };
    }
</script>
@endonce
@endif
