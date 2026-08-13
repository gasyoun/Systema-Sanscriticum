@extends('layouts.student')

@section('title', $topic->title_ru)
@section('header', $topic->title_ru)

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <p class="text-xs text-gray-500 mb-2">{{ $topic->cluster }} · {{ $topic->difficulty }}</p>
    <p class="text-gray-800 mb-6">{{ $topic->summary_ru }}</p>
    @if($topic->alignment_note)
        <p class="text-sm text-gray-600 mb-6 border-l-2 border-brand pl-3">{{ $topic->alignment_note }}</p>
    @endif

    <div class="flex flex-wrap gap-2 mb-8">
        <a href="{{ route('student.grammar-lab.compare', $topic->slug) }}"
           class="px-3 py-2 rounded-xl bg-brand text-white text-sm font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
            Уитни ↔ Зализняк
        </a>
        <form method="post" action="{{ route('student.grammar-lab.bookmark', $topic->slug) }}">
            @csrf
            <button type="submit"
                    class="px-3 py-2 rounded-xl border border-gray-300 text-sm font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                {{ $bookmarked ? 'Убрать из закладок' : 'В закладки' }}
            </button>
        </form>
    </div>

    <h2 class="text-lg font-bold mb-3">Свидетельства словарей</h2>
    <ul class="space-y-2 mb-8">
        @forelse($topic->dictionary_evidence ?? [] as $card)
            <li class="px-4 py-3 rounded-xl bg-white border border-gray-200">
                <span class="font-bold">{{ strtoupper((string) ($card['dict'] ?? '')) }}</span>
                @if(!empty($card['root_slp1']))
                    <span class="text-sm text-gray-600"> · {{ $card['root_slp1'] }}</span>
                @endif
                @if(!empty($card['gloss']))
                    <span class="block text-sm text-gray-700 mt-1">{{ $card['gloss'] }}</span>
                @endif
                @if(!empty($card['entry_id']))
                    <span class="block text-xs text-gray-500 mt-1">{{ $card['entry_id'] }}</span>
                @endif
            </li>
        @empty
            <li class="text-sm text-gray-500">Нет словарной карточки.</li>
        @endforelse
    </ul>

    <h2 class="text-lg font-bold mb-3">Корпус</h2>
    <ul class="space-y-2 mb-8">
        @forelse($topic->corpus_evidence ?? [] as $card)
            <li class="px-4 py-3 rounded-xl bg-white border border-gray-200 text-sm text-gray-700">
                <span class="font-bold">{{ $card['status'] ?? '' }}</span>
                @if(isset($card['dcs_freq']))
                    <span> · частота DCS {{ $card['dcs_freq'] }}</span>
                @endif
                @if(!empty($card['limitation']))
                    <span class="block text-xs text-gray-500 mt-2">{{ $card['limitation'] }}</span>
                @endif
            </li>
        @empty
            <li class="text-sm text-gray-500">Нет корпусной карточки.</li>
        @endforelse
    </ul>

    @if(!empty($topic->provenance))
        <h2 class="text-lg font-bold mb-3">Происхождение</h2>
        <p class="text-xs text-gray-500 mb-8">
            {{ $topic->provenance['source_snapshot'] ?? '' }}
            @if(!empty($topic->provenance['curated_date']))
                · {{ $topic->provenance['curated_date'] }}
            @endif
        </p>
    @endif

    <p>
        <a href="{{ route('student.grammar-lab.index') }}" class="text-sm text-brand underline">Ко всем темам</a>
    </p>
</div>
@endsection
