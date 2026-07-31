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
@include($skinView)
@endsection
