@extends('layouts.student')

@section('title', 'Статистика по карточкам')
@section('header', 'Статистика по карточкам')

@section('content')
    <div class="font-nunito max-w-3xl mx-auto px-4 py-8">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-[#101010] tracking-tight">Статистика</h1>
                <p class="text-gray-500">Прогресс по каждой колоде и разбор ошибок.</p>
            </div>
            <a href="{{ route('student.srs') }}"
               class="text-sm font-bold text-brand hover:text-[#c94c1c] transition-colors">
                &larr; К карточкам
            </a>
        </div>

        @if($stats->isEmpty())
            <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 p-10 text-center text-gray-400">
                Пока нет ни одной колоды с карточками.
            </div>
        @endif

        @foreach($stats as $s)
            <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="p-6 sm:p-8">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-extrabold text-gray-900">{{ $s['deck']->name }}</h2>
                        <span class="text-sm font-bold text-gray-400">{{ $s['reviewed_cards'] }} / {{ $s['total_cards'] }}</span>
                    </div>

                    {{-- Прогресс-бар --}}
                    <div class="w-full h-3 bg-gray-100 rounded-full overflow-hidden mb-6">
                        <div class="h-full bg-brand rounded-full transition-all"
                             style="width: {{ $s['progress_pct'] }}%"></div>
                    </div>

                    {{-- Правильно/неправильно --}}
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div class="text-center">
                            <div class="text-2xl font-extrabold text-gray-900">{{ $s['total_reviews'] }}</div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-wide">Повторений</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-extrabold text-green-600">{{ $s['correct'] }}</div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-wide">Верно</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-extrabold text-red-500">{{ $s['incorrect'] }}</div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-wide">Ошибок</div>
                        </div>
                    </div>

                    @if($s['total_reviews'] > 0)
                        <p class="text-sm text-gray-500 mb-4">Точность: <span class="font-bold text-gray-700">{{ $s['accuracy_pct'] }}%</span></p>
                    @endif

                    {{-- Разбор ошибок --}}
                    @if($s['errors']->isNotEmpty())
                        <div class="border-t border-gray-100 pt-4 mt-4">
                            <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wide mb-3">Последние ошибки</h3>
                            <ul class="space-y-2">
                                @foreach($s['errors'] as $log)
                                    @php $f = $log->card->fields ?? []; @endphp
                                    <li class="flex items-center justify-between text-sm bg-gray-50/60 rounded-xl px-4 py-2">
                                        <span class="font-bold text-gray-900">
                                            {{ $f['devanagari'] ?? $f['iast'] ?? $f['cyrillic'] ?? '—' }}
                                        </span>
                                        <span class="text-gray-500">{{ $f['translation'] ?? '' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endsection
