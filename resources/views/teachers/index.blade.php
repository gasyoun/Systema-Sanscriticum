@extends('layouts.shop')

@section('title', 'Преподаватели Общества ревнителей санскрита')

@push('head')
    <meta name="description" content="Преподаватели Общества ревнителей санскрита: санскритская грамматика, индийская философия, хинди. Образование, курсы, научные интересы и контакты каждого преподавателя.">
    <link rel="canonical" href="{{ url('/prepodavately') }}">
    <meta name="robots" content="index, follow">

    {{-- SEO: ItemList из персон — поисковики собирают из него «карусель» преподавателей --}}
    @php
        $teachersSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $teachers->values()->map(function (\App\Models\Teacher $teacher, int $i) {
                $item = [
                    '@type' => 'Person',
                    'name' => $teacher->name,
                    'url' => url('/prepodavately/'.$teacher->page_slug),
                ];
                if (filled($teacher->page_role)) {
                    $item['jobTitle'] = $teacher->page_role;
                }
                if ($teacher->photo_path) {
                    $item['image'] = url(Storage::url($teacher->photo_path));
                }

                return [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'item' => $item,
                ];
            })->all(),
        ];
    @endphp
    {{-- Сырой вывод {{ }} HTML-экранировал JSON до &quot;@context&quot; — см.
         комментарий в teachers/show.blade.php. HEX-флаги держат payload
         валидным JSON и безопасным для тега </script>. --}}
    <script type="application/ld+json">{!! json_encode($teachersSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white relative overflow-hidden font-sans">
    {{-- Световые пятна — тот же приём, что на /online --}}
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-12 relative z-10 py-16 lg:py-24">

        <div class="text-center mb-12 lg:mb-16">
            <div class="text-[11px] font-black uppercase tracking-[0.3em] text-brand mb-4">
                Общество ревнителей санскрита
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight mb-6">
                Преподаватели
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed">
                Санскрит, хинди и индийская философия — у людей, которые учат этому профессионально.
                В карточке каждого — образование, курсы, научные интересы и контакты.
            </p>
        </div>

        @if($teachers->isEmpty())
            <div class="max-w-xl mx-auto text-center bg-[#111622] border border-[#1F2636] rounded-2xl p-10">
                <i class="fas fa-user-graduate text-4xl text-slate-600 mb-4"></i>
                <p class="text-slate-400 mb-6">
                    Анкеты преподавателей ещё готовятся — а каталог курсов уже открыт.
                </p>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-hover text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-brand/20">
                    <i class="fas fa-book-open"></i>
                    Смотреть все курсы
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8">
                @foreach($teachers as $teacher)
                    <a href="{{ route('teachers.show', $teacher->page_slug) }}"
                       class="group flex flex-col bg-[#111622] border border-[#1F2636] rounded-2xl overflow-hidden
                              hover:border-brand/60 hover:shadow-[0_0_25px_rgba(232,92,36,0.15)] hover:-translate-y-1
                              transition-all duration-300">
                        {{-- Фото или типографская заглушка с инициалом --}}
                        <div class="relative aspect-[4/3] overflow-hidden border-b border-[#1F2636] bg-gradient-to-br from-slate-800 to-[#0A0D14]">
                            @if($teacher->photo_path)
                                <img src="{{ Storage::url($teacher->photo_path) }}" alt="{{ $teacher->name }}"
                                     loading="lazy" decoding="async"
                                     class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-90">
                                <div class="absolute inset-0 bg-gradient-to-t from-[#111622] via-transparent to-transparent opacity-70"></div>
                            @else
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <i class="fas fa-om absolute text-[8rem] text-white/5 pointer-events-none"></i>
                                    <span class="relative text-6xl font-extrabold text-white/90">
                                        {{ mb_strtoupper(mb_substr($teacher->name, 0, 1)) }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div class="p-6 flex flex-col flex-grow">
                            @if($teacher->page_role)
                                <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-2">
                                    {{ $teacher->page_role }}
                                </div>
                            @endif
                            <h2 class="text-xl font-bold text-white mb-3 group-hover:text-brand transition-colors">
                                {{ $teacher->name }}
                            </h2>
                            @if($teacher->page_excerpt)
                                <p class="text-sm text-slate-400 leading-relaxed line-clamp-3 mb-4">
                                    {{ $teacher->page_excerpt }}
                                </p>
                            @endif
                            <span class="mt-auto inline-flex items-center gap-2 text-sm font-bold text-brand">
                                Страница преподавателя
                                <i class="fas fa-arrow-right text-xs transition-transform group-hover:translate-x-1"></i>
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="mt-14 text-center">
            <a href="{{ route('shop.index') }}"
               class="inline-flex items-center gap-2 px-6 py-3 bg-[#141A28] border border-[#1F2636] hover:border-brand/60 hover:bg-brand/5 text-brand text-sm font-bold rounded-xl transition-all">
                <i class="fas fa-book-open"></i>
                Все курсы Общества
            </a>
        </div>
    </div>
</div>
@endsection
