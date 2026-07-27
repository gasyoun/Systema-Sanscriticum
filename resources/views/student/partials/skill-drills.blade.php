{{-- H1680 — cabinet skill-drill strip (games_cabinet_skill_drills flag).
     Short /lila free-play drills, distinct from the FSRS review loop at
     /dvaram/srs — this is quick script/vocabulary practice, not spaced
     repetition. Curated (not every /lila pack — only ones that read well
     as a short standalone drill). --}}
@if (config('features.games_cabinet_skill_drills'))
    @php
        $skillDrills = [
            ['href' => '/lila/match/kochergina-l1/', 'title' => 'Кочергина, урок 1', 'sub' => '20 слов · match'],
            ['href' => '/lila/roots/top-25/', 'title' => 'Топ-25 корней', 'sub' => 'частотность · match'],
            ['href' => '/lila/sort/vowel-length/', 'title' => 'Краткие/долгие гласные', 'sub' => '24 слога · sort'],
            ['href' => '/lila/match/iast-cyrillic/', 'title' => 'IAST ↔ кириллица', 'sub' => 'чтение · match'],
            ['href' => '/lila/ligatures/top-10/', 'title' => 'Топ-10 лигатур', 'sub' => 'деванагари · ligatures'],
        ];
    @endphp
    <div class="mb-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-extrabold text-gray-900">Короткие тренажёры</h3>
            <span class="text-xs text-gray-400">2–5 минут · без повторений</span>
        </div>
        <div class="flex flex-wrap gap-3">
            @foreach ($skillDrills as $drill)
                <a href="{{ $drill['href'] }}" target="_blank" rel="noopener"
                   class="flex-1 min-w-[160px] px-4 py-3 rounded-xl border border-gray-200 hover:border-[#E85C24] hover:bg-[#E85C24]/5 transition-colors">
                    <div class="text-sm font-bold text-gray-800">{{ $drill['title'] }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">{{ $drill['sub'] }}</div>
                </a>
            @endforeach
        </div>
    </div>
@endif
