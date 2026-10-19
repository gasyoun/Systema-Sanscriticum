@extends('layouts.shop')

@section('title', $deck?->seoTitle() ?? 'Карточки — пробный режим')
@section('meta_description', $deck?->seoDescription() ?? 'Публичные колоды карточек по санскриту: пробный режим без регистрации, интервальное повторение и разбор ошибок.')

@push('head')
    <link rel="canonical" href="{{ route('srs.deck', ['slug' => $deck?->slug ?? $slug]) }}">
    @if($deck !== null && ! $deck->isSeoIndexable())
        {{-- SEO-META-1: generic "Level N" shells duplicate level 1; keep them crawlable but out of the index. --}}
        <meta name="robots" content="noindex, follow">
    @endif
@endpush

@section('content')
<div class="bg-slate-100 min-h-[70vh]">
    @livewire('srs-review', ['slug' => $slug])
</div>

{{-- H5184 N06 — разбор ошибок: CTA под публичной колодой --}}
<div class="container mx-auto px-4 py-10 max-w-3xl">
    @include('srs.partials.error-analysis-cta')
</div>
@endsection
