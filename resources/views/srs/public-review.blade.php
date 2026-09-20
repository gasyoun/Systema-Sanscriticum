@extends('layouts.shop')

@section('title', 'Карточки — пробный режим')

@section('content')
<div class="bg-slate-100 min-h-[70vh]">
    @livewire('srs-review', ['slug' => $slug])
</div>

{{-- H5184 N06 — разбор ошибок: CTA под публичной колодой --}}
<div class="container mx-auto px-4 py-10 max-w-3xl">
    @include('srs.partials.error-analysis-cta')
</div>
@endsection
