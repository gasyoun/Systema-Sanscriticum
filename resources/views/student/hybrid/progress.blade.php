@extends('layouts.student')

@section('title', 'Прогресс')
@section('header', 'Прогресс')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h2 class="text-3xl font-extrabold text-[#101010] mb-2">Прогресс</h2>
    <p class="text-gray-500 mb-6">
        Карта станций грамматической лестницы — Phase 3. Здесь — сертификаты и точка входа job-nav.
    </p>

    @if ($suppressOffers ?? false)
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6">
            Режим восстановления: офферы лестницы скрыты (R29.2 / R29.7).
        </p>
    @endif

    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Сертификаты</div>
    <div class="space-y-3">
        @forelse ($certificates as $cert)
            <article class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="font-extrabold text-[#101010]">{{ $cert->course->title ?? 'Курс' }}</h3>
                    <p class="text-xs text-gray-500 mt-1">выдан {{ $cert->created_at?->format('d.m.Y') }}</p>
                </div>
                <a href="{{ route('student.certificate.download', $cert->id) }}"
                   class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 text-sm font-bold hover:border-[#E85C24] hover:text-[#E85C24]">
                    Скачать PDF
                </a>
            </article>
        @empty
            <p class="text-sm text-gray-500">Сертификатов пока нет — они появятся после завершения курса.</p>
        @endforelse
    </div>
</div>
@endsection
