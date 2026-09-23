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
@if (! $paidSupportAvailable)
    <aside class="max-w-3xl mx-auto mt-6 px-5 py-4 rounded-xl border border-amber-300 bg-amber-50 text-stone-900" role="status">
        @if ($sessionScheduled ?? false)
            <strong>Сейчас доступно бесплатное введение.</strong> Запись на ближайшую групповую консультацию закрыта. Бесплатные материалы остаются доступны.
        @else
            <strong>Сейчас доступно бесплатное введение.</strong> Новая дата живой групповой консультации и возможность проверки куратором пока не подтверждены. Платная запись откроется после подтверждения даты.
        @endif
    </aside>
@endif
@include($skinView)
@endsection
