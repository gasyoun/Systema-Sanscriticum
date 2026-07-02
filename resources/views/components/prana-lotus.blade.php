{{-- Лотос праны как inline-картинка вместо эмодзи 🪷 (U+1FAB7): этого глифа нет
     в Segoe UI Emoji на Windows 10, и у таких студентов вместо лотоса рисовался
     пустой квадрат (в т.ч. крупный — в карточке баланса). Ассет: Twemoji 15.1
     (jdecked/twemoji, CC-BY 4.0), public/images/prana-lotus.svg.
     NB: файл намеренно кончается сразу за тегом — хвостовой перенос строки
     давал пробел перед знаками препинания после компонента. --}}
<img src="{{ asset('images/prana-lotus.svg') }}" alt="прана" draggable="false" {{ $attributes->merge(['class' => 'inline-block w-[1.1em] h-[1.1em] align-[-0.15em] select-none']) }}>