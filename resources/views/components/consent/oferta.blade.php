{{--
    Принятие публичной оферты — ОТДЕЛЬНАЯ галочка, а не «принимаю оферту» в одной
    строке с политикой: оферта это акцепт договора (ст. 438 ГК), а согласие на
    обработку ПДн — законное основание обработки (152-ФЗ ст. 9). Склеенные в один
    чекбокс, они не доказывают ни того, ни другого.

    Сервер читает name="oferta_accepted"
    (App\Support\Consent\ConsentRules::oferta() — на оплате обязательно всегда).

    Пропсы:
      theme    — light | dark (цвет текста под фон формы)
      form     — id формы, если галочка лежит ВНЕ <form> (карточка кнопки оплаты)
      model    — имя Alpine-переменной, если галочка гейтит кнопку (x-model)
      required — обязательность в браузере
--}}
@props(['theme' => 'light', 'form' => null, 'model' => null, 'required' => true])

@php
    $text = $theme === 'dark' ? 'text-gray-400' : 'text-gray-500';
    $link = $theme === 'dark' ? 'text-orange-400 hover:underline' : 'text-brand hover:underline font-semibold';
@endphp

<label {{ $attributes->merge(['class' => 'flex items-start gap-2.5 cursor-pointer text-xs leading-relaxed '.$text]) }}>
    <input type="checkbox" name="oferta_accepted" value="1" @required($required)
           @if($form) form="{{ $form }}" @endif
           @if($model) x-model="{{ $model }}" @endif
           @checked(old('oferta_accepted'))
           class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer">
    <span>
        Я принимаю условия
        <a href="{{ route('docs.show', 'oferta') }}" target="_blank" class="{{ $link }}">публичной оферты</a>
    </span>
</label>
@if(isset($errors) && $errors->has('oferta_accepted'))
    <p class="text-red-500 text-xs mt-1 font-medium">{{ $errors->first('oferta_accepted') }}</p>
@endif
