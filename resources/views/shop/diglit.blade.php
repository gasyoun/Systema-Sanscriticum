{{-- Лендинг «Основы цифровой грамотности» — поток октября 2026. Публичный,
    но живёт только при features.diglit_landing = true (404 в контроллере):
    страница не может появиться раньше, чем курс и четыре тарифа заведены
    в Filament и набор открыт. Цены — из БД, не отсюда.

    Честностные ограничения копирайта (ратифицированное досье MG 24-08-2026):
    1. Позиционирование — «уверенная работа с нейросетями», НЕ «цифровая
       грамотность» (категория воюет с бесплатным госсектором).
    2. Никаких обещаний дохода и никаких слов про удостоверение ПК — корочки
       ДПО у потока нет, сертификат собственный.
    3. Обе цифры исследований дословно сверены с первоисточниками 24-08-2026
       (PwC press release 03-06-2025; Fortune/Google-Ipsos 19-02-2026) —
       править только вместе с docs/copy/diglit-landing-copy.md. --}}

@extends('layouts.shop')

@section('title', 'Основы цифровой грамотности — уверенная работа с нейросетями')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-12">

    {{-- HERO --}}
    <section class="mb-14">
        <p class="text-[11px] font-black uppercase tracking-widest text-brand mb-3">Поток · старт в октябре · живые занятия</p>
        <h1 class="text-3xl md:text-4xl font-extrabold text-white leading-tight mb-4">
            Уверенная работа с нейросетями — за 16 занятий
        </h1>
        <p class="text-lg text-slate-300 leading-relaxed max-w-3xl mb-2">
            Курс для работы и учёбы без технического бэкграунда: от базовой цифровой
            гигиены до собственных инструментов на нейросетях. Не «обзор 30 сервисов»,
            а порядок работы, который остаётся с вами после потока.
        </p>
    </section>

    {{-- FORMAT --}}
    <section class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-14">
        <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
            <p class="text-brand font-bold mb-1">16 занятий × 1,5 часа</p>
            <p class="text-sm text-slate-400">Живой Zoom, ~2 месяца, два занятия в неделю. Записи остаются у вас навсегда.</p>
        </div>
        <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
            <p class="text-brand font-bold mb-1">Домашние задания с проверкой</p>
            <p class="text-sm text-slate-400">Каждое занятие — практика на ваших реальных задачах; проверка и разбор ошибок на время потока.</p>
        </div>
        <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
            <p class="text-brand font-bold mb-1">Чат потока</p>
            <p class="text-sm text-slate-400">Вопросы между занятиями — отвечаем в общем чате, разборы сложных случаев на эфирах.</p>
        </div>
        <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6">
            <p class="text-brand font-bold mb-1">Итог — свой инструмент</p>
            <p class="text-sm text-slate-400">Выпускная работа: повторяющуюся задачу вы закрываете настроенным под себя инструментом или рабочим процессом.</p>
        </div>
    </section>

    {{-- VERIFIED STATS --}}
    <section class="bg-[#101521] border border-[#1F2636] rounded-2xl p-6 mb-14">
        <h2 class="text-xl font-bold text-white mb-4">Почему сейчас</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-slate-300 leading-relaxed">
            <p>
                По данным <a class="text-brand underline decoration-brand/40" href="https://www.pwc.com/gx/en/news-room/press-releases/2025/ai-linked-to-a-fourfold-increase-in-productivity-growth.html" target="_blank" rel="noopener">PwC Global AI Jobs Barometer 2025</a>
                (анализ почти миллиарда вакансий), в 2024 году роли с требованиями навыков ИИ давали
                <span class="text-white font-bold">+56 % к зарплате</span> по сравнению с похожими ролями без таких навыков —
                вдвое больше, чем годом ранее (+25 %).
            </p>
            <p>
                Совместное исследование <a class="text-brand underline decoration-brand/40" href="https://fortune.com/2026/02/19/exclusive-google-ipsos-report-five-percent-of-workers-ai-fluent-raises-promotions-workforce-advantage-gen-z-advice/" target="_blank" rel="noopener">Google и Ipsos</a> (работники США, февраль 2026):
                лишь <span class="text-white font-bold">5 % работников</span> по-настоящему перестроили свою работу вокруг ИИ —
                и они <span class="text-white font-bold">в 4,5 раза чаще</span> сообщают о более высокой зарплате
                и <span class="text-white font-bold">в 4 раза чаще</span> о повышении, чем те, кто ещё на ранней стадии освоения.
            </p>
        </div>
    </section>

    {{-- PROGRAM --}}
    <section class="mb-14">
        <h2 class="text-2xl font-bold text-white mb-6">Программа: четыре блока по четыре занятия</h2>
        <ol class="space-y-4">
            @foreach([
                ['Цифровая база и безопасность', 'Файлы и облака без хаоса, пароли и двухфакторка, Госуслуги и платежи, распознавание фишинга и фейков.'],
                ['Нейросети для текстов и поиска', 'Точные запросы вместо «что-нибудь придумай», протоколы встреч из расшифровок, письма, посты, редактура под вашим тоном.'],
                ['Нейросети для данных', 'Таблицы и отчёты: просим скрипт, а не «проанализируй» — проверяемо и повторяемо; дашборды из ваших выгрузок.'],
                ['Свой рабочий процесс', 'Проекты и инструкции, которые помнят контекст; сборка инструмента под вашу повторяющуюся задачу — выпускная работа.'],
            ] as $i => [$title, $desc])
                <li class="flex gap-4 bg-[#101521] border border-[#1F2636] rounded-2xl p-5">
                    <span class="shrink-0 w-8 h-8 rounded-full bg-brand/20 text-brand font-black flex items-center justify-center">{{ $i + 1 }}</span>
                    <div>
                        <h3 class="font-bold text-white mb-1">{{ $title }}</h3>
                        <p class="text-sm text-slate-400 leading-relaxed">{{ $desc }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- PRICING LADDER --}}
    <section class="mb-14">
        <h2 class="text-2xl font-bold text-white mb-2">Тарифы</h2>
        <p class="text-sm text-slate-500 mb-6">Полный формат: живые занятия + проверка домашних заданий + чат + записи навсегда.</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            @foreach($ladder as $tariff)
                <div class="bg-[#101521] border {{ $tariff->is($earlyBird) ? 'border-brand/50' : 'border-[#1F2636]' }} rounded-2xl p-6 flex flex-col">
                    <h3 class="text-lg font-bold text-white mb-3">{{ $tariff->title }}</h3>
                    <div class="mb-1">
                        <span class="text-3xl font-extrabold text-white tabular-nums">{{ number_format((float) $tariff->price, 0, '.', ' ') }}</span>
                        <span class="text-slate-400 font-bold ml-1">₽</span>
                    </div>
                    @if($tariff->description)
                        <p class="text-sm text-slate-400 leading-relaxed mb-6">{{ $tariff->description }}</p>
                    @else
                        <p class="text-sm text-slate-500 mb-6">&nbsp;</p>
                    @endif
                    <a href="{{ route('checkout.show', $tariff) }}"
                       class="mt-auto flex items-center justify-center w-full px-4 py-3 {{ $tariff->is($earlyBird) ? 'bg-brand hover:bg-brand/90 text-white' : 'bg-[#141A28] border border-[#1F2636] hover:border-brand/60 text-white' }} text-sm font-bold rounded-xl transition-all">
                        Занять место
                    </a>
                </div>
            @endforeach
        </div>

        @if($recordings->isNotEmpty())
            <div class="bg-[#101521] border border-dashed border-[#1F2636] rounded-2xl p-6">
                <h3 class="font-bold text-white mb-1">{{ $recordings->first()->title }}</h3>
                <p class="text-sm text-slate-400 mb-3">{{ $recordings->first()->description }}</p>
                <div class="flex items-center gap-4 flex-wrap">
                    <span class="text-2xl font-extrabold text-white tabular-nums">{{ number_format((float) $recordings->first()->price, 0, '.', ' ') }} ₽</span>
                    <a href="{{ route('checkout.show', $recordings->first()) }}"
                       class="px-4 py-2 bg-[#141A28] border border-[#1F2636] hover:border-brand/60 text-white text-sm font-bold rounded-xl transition-all">
                        Оплатить
                    </a>
                </div>
            </div>
        @endif

        <p class="text-xs text-slate-600 mt-4">
            Сертификат курса собственный (без удостоверения о повышении квалификации).
            Оплата — российской картой; вопросы по рассрочке решаются в чате набора до старта.
        </p>
    </section>

    {{-- HONEST FAQ --}}
    <section class="mb-10">
        <h2 class="text-2xl font-bold text-white mb-6">Честно о деталях</h2>
        <div class="space-y-4">
            @foreach([
                ['Пропущу занятие — что будет?', 'Ничего страшного: записи остаются у вас навсегда, а домашние задания можно сдать с отставанием в пределах потока.'],
                ['Будет ли удостоверение о повышении квалификации?', 'Нет. Выдаём собственный сертификат школы. Поток про навык и результат, а не про корочку.'],
                ['Чем это не «курс про ChatGPT»?', 'Тем, что половина программы — про порядок работы: контекст, проверяемость результатов и свой инструмент. Нейросети — средство, не герой.'],
                ['Что если не подойдёт?', 'Напишите в чат потока до второго занятия — решим возврат по правилам школы без долгих разбирательств.'],
            ] as [$q, $a])
                <div class="bg-[#101521] border border-[#1F2636] rounded-2xl p-5">
                    <h3 class="font-bold text-white mb-1">{{ $q }}</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">{{ $a }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- FINAL CTA --}}
    <section class="text-center py-8 border-t border-[#1F2636]">
        <h2 class="text-2xl font-bold text-white mb-3">Октябрь близко</h2>
        <p class="text-slate-400 mb-6">Группа ограничена: живые занятия и проверка работ не масштабируются на толпу.</p>
        @if($earlyBird)
            <a href="{{ route('checkout.show', $earlyBird) }}"
               class="inline-flex items-center px-8 py-4 bg-brand hover:bg-brand/90 text-white font-bold rounded-xl transition-all">
                Занять место — {{ number_format((float) $earlyBird->price, 0, '.', ' ') }} ₽
            </a>
        @endif
    </section>

</div>
@endsection
