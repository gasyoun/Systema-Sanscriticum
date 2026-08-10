{{--
    Первые вопросы новичка (H1868, beginner orientation): витрина показывала
    каталог без входной последовательности — «стена курсов» для первого визита.
    Пять вопросов ниже — не выдуманы, это реальные первые вопросы из custdev-корпуса
    ~2 600 размеченных диалогов @rusamskrtam (ORS-FAQ docs/custdev_full_report.md +
    docs/FAQ_FUNNEL_OBJECTION_MAP.md, коды В1–В11):
      · «нет времени / пропущу» — возражение №1, 285 упоминаний (В1);
      · «когда старт, успею ли» — тема №1 по весу, 141 (В6);
      · «сколько стоит» — 252 упоминания (В2);
      · «какой курс мой / с чего начать» — 869 диалогов «разовый интерес»
        конвертят 20% против 71% у «готов к программе» (В5) — перевод в
        программу и есть главная задача этой полосы;
      · «справлюсь ли / сложно» — сомнение снимается ответом (конверсия 68–82%
        при снятии, В3).
    Регистр — ориентация, не маркетинг: без срочности и «мест не осталось»
    (анти-приемы с измеренным отрицательным лифтом −7/−6 в Win/Loss).
    Ответы ведут только на существующие поверхности: квиз «С чего начать»,
    фильтры каталога, якорь #ceny, хаб «Материалы». Цен и дат в тексте нет —
    волатильные факты живут в своих источниках.
--}}
@php
    $firstQuestions = [
        [
            'icon' => 'fas fa-shoe-prints',
            'q' => 'С чего начать, если я с нуля?',
            'a' => 'Подготовка не нужна: большинство курсов начинается с первых букв. Короткий квиз из двух вопросов подскажет ваш.',
            'href' => route('shop.start'),
            'cta' => 'Подобрать курс',
        ],
        [
            'icon' => 'fas fa-clock',
            'q' => 'У меня мало времени. Успею?',
            'a' => 'Записи каждого занятия остаются с вами бессрочно: можно заниматься в своем темпе, пропуск — не катастрофа.',
            'href' => route('shop.index', ['format' => 'recorded']),
            'cta' => 'Курсы в записи',
        ],
        [
            'icon' => 'fas fa-ruble-sign',
            'q' => 'Сколько это стоит?',
            'a' => 'Цены честные, «от и до» — ниже на этой странице. Платить можно по блокам, а не всей суммой сразу.',
            'href' => '#ceny',
            'cta' => 'Как устроены цены',
        ],
        [
            'icon' => 'fas fa-calendar-check',
            'q' => 'Когда старт? Я не опоздал?',
            'a' => 'Потоки стартуют в течение всего года, а присоединиться к идущему курсу можно и позже — записи помогают догнать.',
            'href' => route('shop.index', ['format' => 'live']),
            'cta' => 'Идут сейчас',
        ],
        [
            'icon' => 'fas fa-seedling',
            'q' => 'Справлюсь ли я? Это же сложно',
            'a' => 'Санскрит — путь понятных маленьких шагов с живым преподавателем, а не отвесная стена. Начните с бесплатных материалов.',
            'href' => route('shop.materials'),
            'cta' => 'Попробовать бесплатно',
        ],
    ];
@endphp
<section class="mb-16 lg:mb-20" data-analytics="first-questions">
    <div class="text-center mb-8">
        <h2 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight mb-3">
            Первые вопросы — короткие ответы
        </h2>
        <p class="text-slate-400 max-w-2xl mx-auto leading-relaxed">
            Если вы на этой странице впервые, вот с чего обычно начинают — и куда идти дальше.
        </p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($firstQuestions as $item)
            <div class="flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] p-5">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-9 h-9 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                        <i class="{{ $item['icon'] }} text-[#38BDF8] text-sm"></i>
                    </div>
                    <div class="text-base font-bold text-white">{{ $item['q'] }}</div>
                </div>
                <p class="text-sm text-slate-400 leading-relaxed mb-4 grow">{{ $item['a'] }}</p>
                <a href="{{ $item['href'] }}"
                   class="inline-flex items-center gap-2 text-xs font-bold text-[#38BDF8] hover:text-brand transition-colors">
                    {{ $item['cta'] }} <i class="fas fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        @endforeach
    </div>
</section>
