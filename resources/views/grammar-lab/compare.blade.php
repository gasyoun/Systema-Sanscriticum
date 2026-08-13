@extends('layouts.student')

@section('title', 'Сравнение — '.$topic->title_ru)
@section('header', 'Уитни ↔ Зализняк')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 pb-12">
    <h1 class="text-2xl font-bold mb-2">{{ $topic->title_ru }}</h1>
    <p class="text-sm text-gray-600 mb-6">{{ $topic->alignment_note }}</p>

    <div class="grid md:grid-cols-2 gap-4 mb-8">
        <section class="px-4 py-3 rounded-xl bg-white border border-gray-200" aria-labelledby="whitney-h">
            <h2 id="whitney-h" class="font-bold mb-3">Whitney</h2>
            <ul class="space-y-2 text-sm">
                @forelse($whitney as $source)
                    <li>
                        <span class="font-mono text-xs">{{ $source->anchor_id }}</span>
                        <span class="block text-gray-500">{{ $source->source_dataset }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">Нет якоря Whitney — тема не должна была попасть в пакет.</li>
                @endforelse
            </ul>
        </section>
        <section class="px-4 py-3 rounded-xl bg-white border border-gray-200" aria-labelledby="zal-h">
            <h2 id="zal-h" class="font-bold mb-3">Зализняк</h2>
            <ul class="space-y-2 text-sm">
                @forelse($zalizniak as $source)
                    <li>
                        <span class="font-mono text-xs">{{ $source->anchor_id }}</span>
                        <span class="block text-gray-500">{{ $source->source_dataset }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">Нет якоря Зализняка — тема не должна была попасть в пакет.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <p class="flex gap-4">
        <a href="{{ route('student.grammar-lab.show', $topic->slug) }}" class="text-sm text-brand underline">К теме</a>
        <a href="{{ route('student.grammar-lab.index') }}" class="text-sm text-brand underline">Ко всем темам</a>
    </p>
</div>
@endsection
