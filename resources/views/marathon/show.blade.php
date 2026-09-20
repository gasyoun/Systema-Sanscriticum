@extends('layouts.shop')

@section('title', 'Консультация по онлайн-курсам Общества ревнителей санскрита')

@section('content')
{{-- H1975 — shell: resolves the visual skin (a|b|c|d, default b via
     App\Support\MarathonVisual), then hands off to that skin's own
     content partial. Flash/session error data is read directly inside
     each skin partial (Blade @include shares this file's variable scope). --}}
@php
    $skinKey = in_array($skin ?? 'b', \App\Support\MarathonVisual::VARIANTS, true) ? $skin : 'b';
    $skinView = "marathon.skins.{$skinKey}.content";
    if (! view()->exists($skinView)) {
        $skinView = 'marathon.skins.b.content';
    }
@endphp
@php($beginnerOffer = \App\Support\BeginnerPilotOffer::forView())
@if (! $beginnerOffer['supportAvailable'])
    <aside class="max-w-3xl mx-auto mt-6 px-5 py-4 rounded-xl border border-amber-300 bg-amber-50 text-stone-900" role="status">
        <strong>Перед выбором участия с проверкой:</strong> новая дата живой групповой консультации пока не подтверждена. Уточните дату и возможность проверки у куратора до оплаты. Бесплатные материалы доступны без личной проверки.
    </aside>
@endif
@include($skinView)
@endsection
