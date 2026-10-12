@extends('layouts.articles')

{{-- H6373 — публичная страница политики конфиденциальности /privacy.
     Пререквизит подачи Google OAuth consent screen на верификацию sensitive
     scope calendar (docs/GOOGLE_CALENDAR_VERIFICATION_PACK_H4434.md шаг 2:
     валидный privacy URL). Паттерн — pages/trust-moat.blade.php:
     layouts.articles, хлебная крошка, robots index,follow. 152-ФЗ: какие ПД,
     зачем, сроки, права, контакт; cookies; раздел OAuth Google. --}}

@section('title', 'Политика конфиденциальности | samskrte.ru')
@section('meta_description', 'Как «Общество ревнителей санскрита» обрабатывает персональные данные на samskrte.ru: какие данные, зачем, сколько храним, cookies, вход через Google (OAuth) и ваши права по 152-ФЗ.')

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
    <div class="container mx-auto px-4 max-w-3xl py-10 md:py-14">

        {{-- Хлебная крошка --}}
        <nav class="mb-6 text-sm text-gray-500">
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
            <span class="mx-2 text-gray-300">/</span>
            <span class="text-gray-700">Политика конфиденциальности</span>
        </nav>

        <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight mb-4">
            Политика конфиденциальности
        </h1>

        <p class="text-gray-600 leading-relaxed mb-8">
            «Общество ревнителей санскрита» (далее — «мы») ведёт образовательную
            платформу <strong>samskrte.ru</strong>. Эта политика объясняет, какие
            персональные данные мы обрабатываем, зачем, как долго их храним и какими
            правами вы распоряжаетесь. Формальные документы — сама
            <a href="{{ route('docs.show', 'privacy') }}" class="text-brand hover:underline">Политика конфиденциальности (PDF)</a>
            и
            <a href="{{ route('docs.show', 'oferta') }}" class="text-brand hover:underline">публичная оферта</a>;
            здесь — то же самое человеческим языком.
        </p>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Какие данные мы обрабатываем и зачем</h2>
        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-4 shadow-sm">
            <ul class="list-disc pl-5 text-gray-700 leading-relaxed space-y-2">
                <li><strong>Учётная запись:</strong> имя, email, телефон, ник в Telegram — чтобы создать личный кабинет, впустить на занятия и связаться по вопросам учёбы.</li>
                <li><strong>Учебные данные:</strong> ответы на домашние задания, прогресс по курсам, результаты тестов — чтобы куратор проверял работу и вы видели свой прогресс.</li>
                <li><strong>Платёжные данные:</strong> сумма, статус и назначение платежа — чтобы подтвердить доступ к курсу. Данные банковских карт обрабатывает платёжный провайдер; мы номера карт не получаем и не храним.</li>
                <li><strong>Служебные данные:</strong> журнал входов и технические логи (включая IP-адрес) — безопасность аккаунта и работоспособность сервиса.</li>
                <li><strong>Рассылки:</strong> email и Telegram-уведомления о курсах — только с вашего согласия; отписаться можно в любой момент (ссылка есть в каждом письме).</li>
            </ul>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Сколько мы храним данные</h2>
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 mb-4">
            <p class="text-gray-700 leading-relaxed">
                Пока действует ваша учётная запись и открыт доступ к курсам. После
                удаления аккаунта мы храним данные ровно столько, сколько требуют
                бухгалтерский и налоговый учёт, затем удаляем их или обезличиваем.
                Логи безопасности храним ограниченный срок.
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Ваши права</h2>
        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-4 shadow-sm">
            <p class="text-gray-700 leading-relaxed">
                Вы можете запросить доступ к своим данным, их исправление или
                удаление, отозвать согласие на обработку и на рассылки. Напишите на
                <a href="mailto:zabota@samskrte.ru" class="text-brand hover:underline">zabota@samskrte.ru</a> —
                отвечаем и исполняем запросы в сроки, установленные законом 152-ФЗ
                «О персональных данных».
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Cookies</h2>
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 mb-4">
            <p class="text-gray-700 leading-relaxed">
                Необходимые cookies (сессия, вход, корзина) работают всегда — без
                них сайт не работает. Аналитические cookies (Яндекс.Метрика) мы
                включаем только после вашего согласия в баннере; согласие можно
                отозвать в любой момент кнопкой «Настройки cookie» в подвале каждой
                страницы. Рекламные трекеры третьих лиц мы не подключаем.
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Вход через Google (OAuth)</h2>
        <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-4 shadow-sm">
            <p class="text-gray-700 leading-relaxed mb-3">
                Войти на samskrte.ru можно через Google. Что мы запрашиваем и что
                делаем с этим:
            </p>
            <ul class="list-disc pl-5 text-gray-700 leading-relaxed space-y-2 mb-3">
                <li><strong>Сейчас — scope <code>email</code> и <code>profile</code>:</strong>
                    получаем ваше имя, email и идентификатор Google-аккаунта, чтобы
                    создать учётную запись и впускать вас без пароля. Храним только
                    имя, email и Google ID.</li>
                <li><strong>Готовим — scope <code>https://www.googleapis.com/auth/calendar</code></strong>
                    (только для преподавателей и администраторов, никогда для
                    студентов): чтобы занятия из нашего расписания появлялись в
                    личном Google Календаре преподавателя и изменения в Календаре
                    обновляли расписание. Мы читаем и пишем события только в
                    календарь того пользователя, который сам подключил связку;
                    календари студентов не затрагиваются; содержимое календаря мы не
                    храним дольше связанного с расписанием события.</li>
            </ul>
            <p class="text-gray-700 leading-relaxed">
                Мы никогда не видим и не храним ваш пароль Google, переписку,
                файлы, контакты или содержимое других ваших календарей. Связать
                календарь можно только по явному согласию, отключить — в личном
                кабинете или через Google.
            </p>
        </div>

        <h2 class="text-xl font-bold text-gray-900 mb-3">Кому передаются данные</h2>
        <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 mb-8">
            <p class="text-gray-700 leading-relaxed">
                Только тем, без кого сервис не работает: платёжному провайдеру
                (платежи), почтовому сервису (письма), хостингу (работа сайта) и
                системам аналитики — строго по вашему согласию. Мы не продаём
                персональные данные и не передаём их для рекламы.
            </p>
        </div>

        <p class="text-sm text-gray-500 leading-relaxed">
            Оператор персональных данных — «Общество ревнителей санскрита», сайт
            <a href="{{ url('/') }}" class="hover:text-brand transition-colors">samskrte.ru</a>.
            Вопросы по этой политике и по вашим данным:
            <a href="mailto:zabota@samskrte.ru" class="text-brand hover:underline">zabota@samskrte.ru</a>.
        </p>
    </div>
@endsection
