@extends('layouts.articles')

@section('title', $title . ' — Общество ревнителей санскрита')
@section('meta_description', $title)

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
    <div class="container mx-auto px-4 max-w-5xl py-10 md:py-14">

        {{-- Хлебная крошка --}}
        <nav class="mb-6 text-sm text-gray-500">
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
            <span class="mx-2 text-gray-300">/</span>
            <span class="text-gray-700">Документы</span>
        </nav>

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
            <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight">
                {{ $title }}
            </h1>

            <a href="{{ asset("docs/{$slug}.pdf") }}" download
               class="shrink-0 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand text-white font-semibold hover:bg-brand-hover transition-colors shadow-sm">
                <i class="fas fa-download"></i>
                Скачать PDF
            </a>
        </div>

        @if($slug === 'privacy')
            {{-- H5168: правка §1.5 политики (реестр AI-рисков R7, рулинг MG
                 19-09-2026) — архивная оговорка о хранении расшифровок живых
                 занятий в приватном git-репозитории (решение MG 27-07-2026
                 «из git НЕ вычищать, репозиторий приватный»). PDF-версия
                 перегенерируется отдельно; до тех пор оговорка публикуется
                 здесь, рядом с PDF. --}}
            <div class="mb-6 rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm leading-relaxed text-gray-700">
                <p class="font-semibold text-[#101010] mb-2">Изменение к разделу 1.5 (от 20.09.2026)</p>
                <p>
                    Обработка и хранение персональных данных субъектов осуществляются
                    исключительно на территории Российской Федерации, за исключением
                    архивных расшифровок живых занятий: они хранятся в приватном
                    git-репозитории на инфраструктуре GitHub (США). Доступ к репозиторию
                    ограничен кругом лиц, допущенных оператором; архив нигде не
                    публикуется — перевод репозитория в публичный режим, создание
                    публичного форка или размещение его содержимого на общедоступных
                    ресурсах приравнивается к раскрытию персональных данных и не
                    производится. Хранение архива в приватном репозитории — осознанно
                    принятое решение оператора (27.07.2026).
                </p>
            </div>

            {{-- H6503: сводка «Ключевые положения политики» (аудит H6481, находка F4):
                 текстовые сканеры (suveren и аналоги) читают HTML, а не PDF-бинарник —
                 четыре литеральные ключевые фразы 152-ФЗ дублируются здесь рядом с PDF. --}}
            <div class="mb-6 rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm leading-relaxed text-gray-700">
                <p class="font-semibold text-[#101010] mb-2">Ключевые положения политики</p>
                <ul class="space-y-1.5">
                    <li>Правовое основание обработки персональных данных — Федеральный закон от 27.07.2006 № <strong>152-ФЗ</strong> «О персональных данных» (§1.1 политики); правовые основания обработки — §2.</li>
                    <li>Какие <strong>цели обработки</strong> закрепляет политика (§3): договоры информационно-просветительских услуг; дистанционные мероприятия и доступ к их записям; расчёты с Участниками и Контрагентами; кадровый, налоговый и бухгалтерский учёт; информирование о новых мероприятиях (при согласии); идентификация и авторизация в сервисах Оператора.</li>
                    <li>Какие <strong>права субъекта</strong> персональных данных гарантирует политика (§10): получение сведений об обработке, доступ к своим данным, уточнение, блокирование и уничтожение, отзыв, защита и обжалование; запрос — rusamskrtam@yandex.ru или почтой (§10.2); кабинет — настройки приватности и удаление учётной записи.</li>
                    <li><strong>Отзыв согласия</strong> (§8.2, §10.1): субъект вправе в любой момент подать отзыв согласия — в кабинете (настройки приватности) или письмом на rusamskrtam@yandex.ru; сроки прекращения обработки — §8.</li>
                </ul>
                <p class="mt-2">Полный текст политики — в PDF ниже.</p>
            </div>
        @endif

        {{-- Встроенный просмотр PDF --}}
        <div class="rounded-2xl overflow-hidden border border-gray-200 shadow-sm bg-white">
            <iframe src="{{ asset("docs/{$slug}.pdf") }}"
                    class="w-full h-[80vh]" frameborder="0"
                    title="{{ $title }}"></iframe>
        </div>

        <p class="mt-4 text-sm text-gray-500">
            Если документ не отображается,
            <a href="{{ asset("docs/{$slug}.pdf") }}" target="_blank" rel="noopener"
               class="text-brand underline hover:no-underline">откройте PDF в новой вкладке</a>.
        </p>
    </div>
@endsection
