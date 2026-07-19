{{--
    «Почему мы» (H431, Phase 1 п.2): объективные преимущества формата вместо
    общей рекламной прозы — маленькие группы, домашние задания, доступ к
    записям, квалификация преподавателя. Числа (лет/книг) — из config/trust.php,
    никогда не хардкодить.

    ⚠️ Формулировка про очные встречи ЖЁСТКО ограничена (custdev/SELLING_LAYOUT_COMPARISON_2026.md,
    Phase 1 п.2): только «встречи сообщества» / «встречи Общества ревнителей
    санскрита». Нельзя писать «занятия», «практикумы», «очный модуль», «часть
    программы», «сборы», «интенсив» — это разрушает статус «обучение
    исключительно через ЭО и ДОТ» (dpo/ip-gasuns/CONVENTIONS.md).
--}}
@php
    $sinceYear = (int) config('trust.since_year');
    $yearsTeaching = max(1, now()->year - $sinceYear);
    $booksPublished = config('trust.books_published');

    $whyUs = [
        [
            'icon' => 'fas fa-users',
            'title' => 'Маленькие группы',
            'text' => '5–9 человек в группе — преподаватель успевает разобрать каждого, а не читает лекцию в пустоту.',
        ],
        [
            'icon' => 'fas fa-pencil-alt',
            'title' => 'Домашние задания',
            'text' => 'Каждое занятие закрепляется практикой с проверкой — санскрит не выучить одним просмотром видео.',
        ],
        [
            'icon' => 'fas fa-film',
            'title' => 'Пропустили — не беда',
            'text' => 'Все занятия записываются. Пропущенное занятие можно посмотреть в удобное время и не выпасть из группы.',
        ],
        [
            'icon' => 'fas fa-user-graduate',
            'title' => 'Кто ведет занятия',
            'text' => 'Кандидат филологических наук, '.$yearsTeaching.'+ лет практики санскрита'
                .($booksPublished ? ', автор '.$booksPublished.'+ изданных книг' : '').'.',
        ],
        [
            'icon' => 'fas fa-people-arrows',
            'title' => 'Не только онлайн',
            'text' => 'Раз-два в год — встречи сообщества «Общество ревнителей санскрита» в Москве, а по очереди и в других городах.',
        ],
    ];
@endphp
<section class="mb-16 lg:mb-20" data-analytics="why-us-block">
    <h2 class="text-2xl md:text-3xl font-extrabold text-white text-center mb-8">
        Почему <span class="text-[#E85C24]">именно мы</span>
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($whyUs as $item)
            <div class="flex items-start gap-4 rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                <div class="w-11 h-11 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                    <i class="{{ $item['icon'] }} text-[#E85C24]"></i>
                </div>
                <div>
                    <div class="text-base font-bold text-white mb-1">{{ $item['title'] }}</div>
                    <p class="text-sm text-slate-400 leading-relaxed">{{ $item['text'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>
