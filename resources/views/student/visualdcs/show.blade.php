@extends('layouts.student')

@section('title', $item['title'])
@section('header', $item['title'])

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 pb-12 overflow-x-hidden">
    <p class="text-sm text-gray-500 mb-4">
        <a href="{{ route('student.visualdcs.index', $surface) }}" class="text-brand underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">← к списку</a>
    </p>

    @if (session('success'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-xl px-4 py-2" role="status">{{ session('success') }}</p>
    @endif

    <article class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
        <p class="text-xs uppercase tracking-widest text-gray-400">{{ $surface }} · {{ $item['tier'] }}</p>
        <h1 class="text-2xl font-extrabold text-gray-900 break-words">{{ $item['title'] }}</h1>

        @if($surface === 'passage')
            <p class="text-sm text-gray-500">{{ $item['src'] }} · {{ $item['genre'] }}</p>
            <p class="text-lg leading-relaxed text-gray-900 whitespace-pre-wrap break-words font-serif">{{ $item['txt'] }}</p>
            @if(!empty($item['links']))
                <h2 class="text-sm font-bold text-gray-700">Связанные формы</h2>
                <ul class="text-sm text-gray-700 space-y-1">
                    @foreach($item['links'] as $link)
                        <li class="break-words">{{ $link['form'] }} — {{ $link['exampleText'] }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-gray-500">У этого пассажа нет сверки с конкордансом — это известный пробел контракта, не ошибка.</p>
            @endif
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">Ячейки парадигмы</caption>
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="py-2 pr-3">Ячейка</th>
                            <th scope="col" class="py-2">Формы</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($item['cells'] ?? []) as $cell)
                            <tr class="border-t border-gray-100">
                                <td class="py-2 pr-3 font-mono text-xs break-all">{{ $cell['cellId'] ?? ($cell['cell'] ?? '') }}</td>
                                <td class="py-2 break-words">
                                    @forelse(($cell['forms'] ?? []) as $form)
                                        <span class="mr-2">{{ is_array($form) ? ($form[1] ?? '') : $form }}</span>
                                    @empty
                                        <span class="text-gray-400">нет</span>
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    @if($canWrite)
        <form method="POST" action="{{ route('student.visualdcs.progress', [$surface, rawurlencode($item['id'])]) }}" class="mt-6 bg-white border border-gray-200 rounded-2xl p-5 space-y-3">
            @csrf
            <fieldset>
                <legend class="text-sm font-bold text-gray-800 mb-2">Отметить прогресс</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="status" value="started" @checked(!$progress || $progress->status === 'started') class="focus-visible:ring-2 focus-visible:ring-brand">
                    Начал
                </label>
                <label class="flex items-center gap-2 text-sm mt-1">
                    <input type="radio" name="status" value="completed" @checked($progress && $progress->status === 'completed') class="focus-visible:ring-2 focus-visible:ring-brand">
                    Пройдено
                </label>
                <label class="flex items-center gap-2 text-sm mt-1">
                    <input type="radio" name="status" value="mastered" @checked($progress && $progress->status === 'mastered') class="focus-visible:ring-2 focus-visible:ring-brand">
                    Освоено
                </label>
            </fieldset>
            <label class="block text-sm text-gray-700">
                Оценка (0–100)
                <input type="number" name="score" min="0" max="100" value="{{ $progress->score ?? '' }}"
                       class="mt-1 w-full max-w-[8rem] border border-gray-300 rounded-lg px-2 py-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
            </label>
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                Сохранить
            </button>
        </form>
    @endif
</div>
@endsection
