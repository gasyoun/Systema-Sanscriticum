<x-filament-panels::page>
    <form wire:submit.prevent="$refresh" class="mb-6">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1" for="product-docs-q">Поиск</label>
        <input
            id="product-docs-q"
            type="search"
            wire:model.live.debounce.300ms="q"
            placeholder="Заголовок или вопрос…"
            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
    </form>

    @if (mb_strlen(trim($this->q)) >= 2)
        <h2 class="text-lg font-semibold mb-3">Результаты</h2>
        @forelse ($this->hits() as $hit)
            <p class="mb-2">
                <a class="underline" href="{{ $hit['href'] }}">{{ $hit['heading'] }}</a>
                <span class="text-sm text-gray-500"> · {{ $hit['doc']->audienceLabel() }}</span>
            </p>
        @empty
            <p class="text-sm text-gray-600 dark:text-gray-300">Ничего не найдено.</p>
        @endforelse
    @endif

    <div class="overflow-x-auto mt-6">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="py-2 pr-3">Книга</th>
                    <th class="py-2 pr-3">Аудитория</th>
                    <th class="py-2 pr-3">Ссылки</th>
                    @if ($this->isSuperAdmin())
                        <th class="py-2" data-super-meta>Путь</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($this->books() as $book)
                    <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                        <td class="py-3 pr-3">
                            <div class="font-medium">{{ $book->title }}</div>
                            @if ($book->description)
                                <div class="text-gray-500 dark:text-gray-400">{{ $book->description }}</div>
                            @endif
                        </td>
                        <td class="py-3 pr-3">{{ $book->audienceLabel() }}</td>
                        <td class="py-3 pr-3 space-x-3">
                            <a class="underline" href="{{ $book->href() }}">Открыть</a>
                            @if ($book->faqHref())
                                <a class="underline" href="{{ $book->faqHref() }}">FAQ</a>
                            @endif
                            @if ($book->quizHref())
                                <a class="underline" href="{{ $book->quizHref() }}">Проверка</a>
                            @endif
                        </td>
                        @if ($this->isSuperAdmin())
                            <td class="py-3 text-xs text-gray-500" data-super-meta>
                                @if ($book->source_path)
                                    <div>{{ $book->source_path }}</div>
                                    <div>{{ $this->gitDate($book) ?? '—' }}</div>
                                    @php($cov = $book->screenshotCoverage())
                                    @if ($cov)
                                        <div>PNG: {{ $cov['have'] }}/{{ $cov['mentioned'] }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
