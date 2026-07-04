{{-- Микродоверие: статичная полоса под hero. Данных нет — всегда видна. --}}
<section class="mb-16 lg:mb-20" data-analytics="trust-strip">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @php
            $trust = [
                ['icon' => 'fas fa-award', 'title' => '20+ лет традиции', 'text' => 'Преподавание санскрита в традиции живых учителей.'],
                ['icon' => 'fas fa-film', 'title' => 'Записи всех занятий', 'text' => 'Доступ к видеозаписям остаётся с вами навсегда.'],
                ['icon' => 'fas fa-comments', 'title' => 'Полностью на русском', 'text' => 'Объяснения и материалы — на понятном русском языке.'],
            ];
        @endphp
        @foreach($trust as $item)
            <div class="flex items-start gap-4 rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                <div class="w-11 h-11 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                    <i class="{{ $item['icon'] }} text-[#38BDF8]"></i>
                </div>
                <div>
                    <div class="text-base font-bold text-white mb-1">{{ $item['title'] }}</div>
                    <p class="text-sm text-slate-400 leading-relaxed">{{ $item['text'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
