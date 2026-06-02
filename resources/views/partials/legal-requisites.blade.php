{{--
    Реквизиты ИП для подвала сайта.
    Цвет/размер текста — через $class под тему конкретного layout'а.
--}}
@php
    $class = $class ?? 'text-gray-400 text-[12px] md:text-[13px]';
@endphp

<p class="{{ $class }} leading-relaxed">
    ИП Гасунс Марцис · ОГРНИП 325400000076450 · ИНН 540861224623<br>
    <a href="mailto:rusamskrtam@yandex.ru" class="underline-offset-2 hover:text-[#E85C24] hover:underline transition-colors">rusamskrtam@yandex.ru</a>
</p>
