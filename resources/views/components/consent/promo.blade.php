{{--
    152-ФЗ / 38-ФЗ: НЕобязательная галочка согласия на рекламную рассылку.
    По умолчанию НЕ отмечена — заранее поставленная галочка согласием не считается.

    Пропсы:
      name   — имя поля (is_promo_agreed для лид-форм, wants_announcements для оплаты/пробного)
      theme  — light | dark
      label  — текст после ссылки на согласие
--}}
@props(['name' => 'is_promo_agreed', 'theme' => 'light', 'label' => 'на получение рассылки об анонсах, новостях и расписании'])

@php
    $text = $theme === 'dark' ? 'text-gray-400' : 'text-gray-500';
    $link = $theme === 'dark' ? 'text-orange-400 hover:underline' : 'text-brand hover:underline font-semibold';
@endphp

<label {{ $attributes->merge(['class' => 'flex items-start gap-2.5 cursor-pointer text-xs leading-relaxed '.$text]) }}>
    <input type="checkbox" name="{{ $name }}" value="1"
           @checked(old($name))
           class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer">
    <span>
        Я даю <a href="{{ route('docs.show', 'soglasie-promo') }}" target="_blank" class="{{ $link }}">согласие</a> {{ $label }} (необязательно).
    </span>
</label>
