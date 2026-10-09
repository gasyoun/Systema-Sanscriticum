{{--
    152-ФЗ: обязательная галочка согласия на обработку ПДн.
    Сервер читает name="pd_consent" (App\Support\Consent\ConsentRules::pd,
    журнал — App\Services\Consent\ConsentRecorder).

    Пропсы:
      theme  — light | dark (цвет текста под фон формы)
      model  — имя Alpine-переменной, если галочка гейтит кнопку (x-model)
      offer  — добавить ссылку на оферту (регистрация, оплата)
--}}
@props(['theme' => 'light', 'model' => null, 'offer' => false])

@php
    $text = $theme === 'dark' ? 'text-gray-400' : 'text-gray-500';
    $link = $theme === 'dark' ? 'text-orange-400 hover:underline' : 'text-brand hover:underline font-semibold';
@endphp

<label {{ $attributes->merge(['class' => 'flex items-start gap-2.5 cursor-pointer text-xs leading-relaxed '.$text]) }}>
    <input type="checkbox" name="pd_consent" value="1" required
           @if($model) x-model="{{ $model }}" @endif
           @checked(old('pd_consent'))
           class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer">
    <span>
        Я даю <a href="{{ route('docs.show', 'soglasie-pd') }}" target="_blank" class="{{ $link }}">согласие на обработку персональных данных</a>
        и ознакомлен(а) с <a href="{{ route('docs.show', 'privacy') }}" target="_blank" class="{{ $link }}">политикой конфиденциальности</a>@if($offer), принимаю <a href="{{ route('docs.show', 'oferta') }}" target="_blank" class="{{ $link }}">оферту</a>@endif.
    </span>
</label>
