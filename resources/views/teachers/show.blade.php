@extends('layouts.shop')

@section('title', $teacher->name)

@php
    // Соцсети: telegram-поле в базе бывает и ником («@ivan»), и полной ссылкой.
    $telegramLink = null;
    if (filled($teacher->telegram)) {
        $tg = trim((string) $teacher->telegram);
        $telegramLink = str_starts_with($tg, 'http') ? $tg : 'https://t.me/'.ltrim($tg, '@');
    }
    $socialLinks = array_values(array_filter([
        filled($teacher->vk) ? ['url' => $teacher->vk, 'icon' => 'fab fa-vk', 'label' => 'ВКонтакте'] : null,
        $telegramLink ? ['url' => $telegramLink, 'icon' => 'fab fa-telegram-plane', 'label' => 'Telegram'] : null,
        filled($teacher->youtube_url) ? ['url' => $teacher->youtube_url, 'icon' => 'fab fa-youtube', 'label' => 'YouTube'] : null,
    ]));
    $pageUrl = url('/prepodavately/'.$teacher->page_slug);
    // Описание — из санитизированного HTML, не из сырого page_html: strip_tags
    // снимает теги, но оставляет текст <script>/<style>, и он утекал бы в meta.
    $metaDescription = filled($teacher->page_excerpt)
        ? \Illuminate\Support\Str::limit($teacher->page_excerpt, 160)
        : \Illuminate\Support\Str::limit(trim(strip_tags(\App\Support\SanitizedHtml::render($teacher->page_html))) ?: $teacher->page_role, 160);
@endphp

@push('head')
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $pageUrl }}">
    <meta name="robots" content="index, follow">

    {{-- SEO: Person + хлебные крошки; sameAs склеивает профиль с соцсетями --}}
    @php
        $personSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $teacher->name,
            'jobTitle' => $teacher->page_role,
            'description' => $teacher->page_excerpt,
            'image' => $teacher->photo_path ? url(Storage::url($teacher->photo_path)) : null,
            'url' => $pageUrl,
            'worksFor' => ['@type' => 'Organization', 'name' => 'Общество ревнителей санскрита'],
            'sameAs' => array_values(array_filter(array_column($socialLinks, 'url'))),
        ], fn ($v) => $v !== null && $v !== []);
        $breadcrumbsSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Преподаватели', 'item' => route('teachers.index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $teacher->name, 'item' => $pageUrl],
            ],
        ];
    @endphp
    {{-- Сырой JSON-LD: {{ }} прогоняет e() и браузер получает {&quot;@context&quot;...} —
         не JSON. HEX-флагы экранируют < > " ' & как \uXXXX, так что даже имя
         с </script> внутри не порвёт тег (прецедент: main.blade.php). --}}
    <script type="application/ld+json">{!! json_encode($personSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbsSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white relative overflow-hidden font-sans">
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10 py-12 lg:py-16">

        {{-- Хлебные крошки --}}
        <nav class="flex items-center gap-2 text-sm text-slate-500 mb-8" aria-label="Хлебные крошки">
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
            <i class="fas fa-chevron-right text-[9px]"></i>
            <a href="{{ route('teachers.index') }}" class="hover:text-brand transition-colors">Преподаватели</a>
            <i class="fas fa-chevron-right text-[9px]"></i>
            <span class="text-slate-300">{{ $teacher->name }}</span>
        </nav>

        {{-- HERO: фото, роль, факты, соцсети --}}
        <div class="bg-[#111622] border border-[#1F2636] rounded-2xl p-6 md:p-10 mb-8">
            <div class="flex flex-col md:flex-row gap-8">
                <div class="shrink-0">
                    @if($teacher->photo_path)
                        <img src="{{ Storage::url($teacher->photo_path) }}" alt="{{ $teacher->name }}"
                             class="w-40 h-40 md:w-56 md:h-56 rounded-2xl object-cover border border-[#1F2636]">
                    @else
                        <div class="w-40 h-40 md:w-56 md:h-56 rounded-2xl bg-gradient-to-br from-brand to-brand-hover flex items-center justify-center shadow-lg shadow-brand/20">
                            <span class="text-7xl font-extrabold text-white">
                                {{ mb_strtoupper(mb_substr($teacher->name, 0, 1)) }}
                            </span>
                        </div>
                    @endif
                </div>

                <div class="min-w-0">
                    <div class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-2">
                        {{ $teacher->page_role ?: 'Преподаватель' }}
                    </div>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-white mb-5 leading-tight">
                        {{ $teacher->name }}
                    </h1>

                    @if($teacher->page_facts)
                        <dl class="flex flex-wrap gap-2 mb-6">
                            @foreach($teacher->page_facts as $fact)
                                <div class="bg-[#0A0D14]/80 border border-[#1F2636] rounded-lg px-3 py-2">
                                    <dt class="text-[10px] uppercase tracking-widest text-slate-500">{{ $fact['label'] ?? '' }}</dt>
                                    <dd class="text-sm text-slate-200">{{ $fact['value'] ?? '' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if($socialLinks !== [])
                        <div class="flex items-center gap-3">
                            @foreach($socialLinks as $link)
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $link['label'] }}"
                                   aria-label="{{ $link['label'] }}"
                                   class="w-10 h-10 flex items-center justify-center rounded-full bg-[#0A0D14] border border-[#1F2636] text-slate-400
                                          hover:text-white hover:border-brand hover:bg-brand transition-all">
                                    <i class="{{ $link['icon'] }}"></i>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- КОНТЕНТ страницы (RichEditor из админки). Плагин typography в билде
             выключен, поэтому типографику секций задаём вариантами — как принято
             в остальных шаблонах витрины. --}}
        @if(filled($teacher->page_html))
            <div class="bg-[#111622] border border-[#1F2636] rounded-2xl p-6 md:p-10 mb-8">
                <div class="text-slate-300 leading-relaxed
                            [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:text-white [&_h2]:mt-8 [&_h2]:mb-3
                            [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-brand [&_h3]:mt-8 [&_h3]:mb-3
                            [&_h4]:text-lg [&_h4]:font-bold [&_h4]:text-white [&_h4]:mt-6 [&_h4]:mb-2
                            [&_p]:mb-4 [&_p:first-child]:mt-0
                            [&_strong]:text-white [&_em]:text-slate-200
                            [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:mb-4
                            [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:mb-4
                            [&_li]:mb-1.5 [&_li::marker]:text-brand
                            [&_a]:text-[#38BDF8] [&_a]:underline [&_a:hover]:text-sky-300
                            [&_blockquote]:border-l-2 [&_blockquote]:border-brand/60 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-slate-400
                            [&_img]:rounded-xl [&_figure]:mb-4 [&_figcaption]:text-xs [&_figcaption]:text-slate-500 [&_figcaption]:mt-2
                            [&_table]:w-full [&_table]:text-sm [&_th]:text-left [&_th]:border-b [&_th]:border-[#1F2636] [&_th]:py-2 [&_td]:border-b [&_td]:border-[#1F2636]/60 [&_td]:py-2">
                    {!! \App\Support\SanitizedHtml::render($teacher->page_html) !!}
                </div>
            </div>
        @endif

        {{-- КУРСЫ преподавателя — карточками витрины --}}
        @if($courses->isNotEmpty())
            <section class="mb-8">
                <h2 class="text-2xl md:text-3xl font-bold text-white mb-6">Курсы преподавателя</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach($courses as $course)
                        <x-shop.course-card :course="$course" />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- CTA --}}
        <div class="bg-gradient-to-br from-brand/15 via-[#111622] to-[#111622] border border-[#1F2636] rounded-2xl p-8 md:p-10 text-center">
            <h2 class="text-2xl font-bold text-white mb-3">
                Хотите учиться у {{ collect(explode(' ', trim($teacher->name)))->first() }}?
            </h2>
            <p class="text-slate-400 mb-6 max-w-2xl mx-auto">
                Посмотрите живые группы и курсы в записи — первый шаг можно сделать уже на этой неделе.
            </p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-hover text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-brand/20">
                    <i class="fas fa-book-open"></i>
                    Выбрать курс
                </a>
                <a href="{{ route('shop.start') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-[#38BDF8]/60 hover:bg-[#38BDF8]/5 text-[#38BDF8] text-sm font-bold rounded-xl transition-all">
                    <i class="fas fa-compass"></i>
                    Не знаете, с чего начать?
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    {{-- Сердечки «Избранное» на карточках курсов: тот же скрипт, что в каталоге --}}
    @include('shop.partials.favorites-script')
@endpush
