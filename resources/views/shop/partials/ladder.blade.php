{{--
    Лесенка продуктов (H323, hybrid ladder): бесплатно → в записи → живой курс.
    Якоря «от N ₽» считаются честно из активных тарифов (ShopController::start);
    отсутствующая цена просто не показывается — никаких выдуманных чисел.

    Ожидает: $freeUrl, $minRecordedPrice, $minBlockPrice, $catalogUrl
--}}
<section class="mb-16 lg:mb-20" data-analytics="product-ladder">
    <h2 class="text-3xl font-bold text-white mb-3">Три ступени — от знакомства до живого курса</h2>
    <p class="text-slate-400 mb-8 max-w-2xl">Начните бесплатно, продолжите в записи в своём темпе, присоединяйтесь к живому потоку, когда будете готовы.</p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        {{-- Ступень 1: бесплатно --}}
        <div class="flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center">
                    <i class="fas fa-gift text-emerald-400"></i>
                </div>
                <span class="text-[10px] font-black uppercase tracking-widest text-emerald-400">Шаг 1 · Бесплатно</span>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Попробуйте</h3>
            <p class="text-sm text-slate-400 leading-relaxed flex-1 mb-5">Пример настоящего урока — без регистрации и оплаты. Посмотрите, как проходят занятия и подходит ли вам подача.</p>
            <a href="{{ $freeUrl }}"
               class="inline-flex justify-center items-center w-full py-3 px-4 bg-emerald-500/15 border border-emerald-500/40 hover:bg-emerald-500 hover:text-white text-emerald-400 text-xs font-bold rounded-xl transition-all">
                Смотреть пример урока
            </a>
        </div>

        {{-- Ступень 2: в записи --}}
        <div class="flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-indigo-500/15 border border-indigo-500/30 flex items-center justify-center">
                    <i class="fas fa-play-circle text-indigo-400"></i>
                </div>
                <span class="text-[10px] font-black uppercase tracking-widest text-indigo-400">
                    Шаг 2 · В записи{{ $minRecordedPrice ? ' · от '.number_format($minRecordedPrice, 0, '.', ' ').' ₽' : '' }}
                </span>
            </div>
            <h3 class="text-lg font-bold text-white mb-2">Учитесь в своём темпе</h3>
            <p class="text-sm text-slate-400 leading-relaxed flex-1 mb-5">Курсы в записи — без расписания и дедлайнов. Видео остаются с вами навсегда, начать можно в любой день.</p>
            <a href="{{ $catalogUrl }}?format=recorded"
               class="inline-flex justify-center items-center w-full py-3 px-4 bg-indigo-500/15 border border-indigo-500/40 hover:bg-indigo-500 hover:text-white text-indigo-400 text-xs font-bold rounded-xl transition-all">
                Курсы в записи
            </a>
        </div>

        {{-- Ступень 3: живой курс --}}
        <div class="flex flex-col rounded-2xl bg-[#111622] border border-[#E85C24]/40 p-6 relative overflow-hidden">
            <div class="absolute -top-10 -right-10 w-32 h-32 bg-[#E85C24]/15 rounded-full blur-3xl pointer-events-none"></div>
            <div class="flex items-center gap-3 mb-4 relative">
                <div class="w-10 h-10 rounded-xl bg-[#E85C24]/15 border border-[#E85C24]/30 flex items-center justify-center">
                    <i class="fas fa-users text-[#E85C24]"></i>
                </div>
                <span class="text-[10px] font-black uppercase tracking-widest text-[#E85C24]">
                    Шаг 3 · Живой курс{{ $minBlockPrice ? ' · от '.number_format($minBlockPrice, 0, '.', ' ').' ₽/блок' : '' }}
                </span>
            </div>
            <h3 class="text-lg font-bold text-white mb-2 relative">Занимайтесь с преподавателем</h3>
            <p class="text-sm text-slate-400 leading-relaxed flex-1 mb-5 relative">Живые занятия в группе, разбор вопросов, учебный чат. Оплата по блокам — не нужно платить за весь курс сразу.</p>
            <a href="{{ $catalogUrl }}?format=live"
               class="inline-flex justify-center items-center w-full py-3 px-4 bg-[#E85C24] hover:bg-[#E85C24]/85 text-white text-xs font-bold rounded-xl transition-all shadow-[0_0_15px_rgba(232,92,36,0.3)] relative">
                Идут сейчас
            </a>
        </div>
    </div>
</section>
