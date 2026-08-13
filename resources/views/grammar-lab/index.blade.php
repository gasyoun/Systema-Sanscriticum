@extends('layouts.student')

@section('title', 'Лаборатория грамматики')
@section('header', 'Лаборатория грамматики')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <p class="text-sm text-gray-500 mb-4">
        Темы Уитни и Зализняка. Поиск понимает русский, деванагари, IAST и SLP1.
    </p>

    <form method="get" action="{{ route('student.grammar-lab.search') }}" class="mb-6" role="search">
        <label for="grammar-lab-q" class="sr-only">Поиск темы</label>
        <div class="flex gap-2">
            <input id="grammar-lab-q" name="q" type="search" value="{{ $query }}"
                   placeholder="гуна, guṇa, गुण, guRa"
                   class="flex-1 rounded-xl border border-gray-300 px-3 py-2 text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
            <button type="submit"
                    class="px-4 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                Найти
            </button>
        </div>
    </form>

    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('student.grammar-lab.index') }}"
           class="px-3 py-1 rounded-full text-sm border {{ $cluster === '' ? 'bg-brand text-white border-brand' : 'border-gray-300 text-gray-700' }}">все</a>
        @foreach($clusters as $item)
            <a href="{{ route('student.grammar-lab.index', ['cluster' => $item]) }}"
               class="px-3 py-1 rounded-full text-sm border {{ $cluster === $item ? 'bg-brand text-white border-brand' : 'border-gray-300 text-gray-700' }}">{{ $item }}</a>
        @endforeach
        <a href="{{ route('student.grammar-lab.history') }}"
           class="px-3 py-1 rounded-full text-sm border border-gray-300 text-gray-700">закладки и история</a>
    </div>

    @if(!empty($recommendation))
        <div class="mb-6 px-4 py-3 rounded-xl border border-brand bg-white">
            <p class="text-xs uppercase tracking-wide text-gray-500 mb-1">Дальше</p>
            <a href="{{ route('student.grammar-lab.show', $recommendation['slug']) }}"
               class="font-bold text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                {{ $recommendation['title_ru'] }}
            </a>
            <p class="text-sm text-gray-600 mt-1">{{ $recommendation['reason'] }}</p>
        </div>
    @endif

    @if($topics->isEmpty())
        <p class="text-sm text-gray-500">Темы ещё не импортированы. Куратор запускает <code>php artisan grammar-lab:sync</code>.</p>
    @else
        <ul class="space-y-3">
            @foreach($topics as $topic)
                <li>
                    <a href="{{ route('student.grammar-lab.show', $topic->slug) }}"
                       class="block px-4 py-3 rounded-xl bg-white border border-gray-200 hover:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        <span class="font-bold text-gray-900">{{ $topic->title_ru }}</span>
                        @if(in_array($topic->topic_id, $bookmarks, true))
                            <span class="ml-2 text-xs text-brand">в закладках</span>
                        @endif
                        <span class="block text-sm text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit($topic->summary_ru, 160) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
