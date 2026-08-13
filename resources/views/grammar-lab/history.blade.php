@extends('layouts.student')

@section('title', 'Закладки и история')
@section('header', 'Закладки и история')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12">
    <h2 class="text-lg font-bold mb-3">Закладки</h2>
    @if($bookmarks->isEmpty())
        <p class="text-sm text-gray-500 mb-8">Пока пусто.</p>
    @else
        <ul class="space-y-2 mb-8">
            @foreach($bookmarks as $topic)
                <li>
                    <a href="{{ route('student.grammar-lab.show', $topic->slug) }}"
                       class="text-brand underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">{{ $topic->title_ru }}</a>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="text-lg font-bold mb-3">Недавние темы</h2>
    @if($views->isEmpty())
        <p class="text-sm text-gray-500">Истории ещё нет.</p>
    @else
        <ol class="space-y-2">
            @foreach($views as $view)
                @php $topic = $topics[$view->topic_id] ?? null; @endphp
                @if($topic)
                    <li class="text-sm">
                        <a href="{{ route('student.grammar-lab.show', $topic->slug) }}" class="text-brand underline">{{ $topic->title_ru }}</a>
                        <span class="text-gray-500"> · {{ $view->viewed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                    </li>
                @endif
            @endforeach
        </ol>
    @endif
</div>
@endsection
