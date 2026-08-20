<x-filament-panels::page>
    <div class="space-y-6" data-testid="hindi-agent-drills-review">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Это все упражнения, которые агент собрал из расшифровки занятия и из файлов раздатки.
            Студентам они сейчас не показываются. Посмотрите список целиком и напишите, что из этого
            можно чинить, а что лучше выбросить. Словари модулей M1–M12 — отдельная страница
            «Мой хинди» → «Словарь Костиной».
        </p>

        @php($lessons = $this->lessons())
        @if($lessons === [])
            <p class="text-sm text-gray-500" data-testid="hindi-agent-drills-empty">
                Сейчас нет ни одного агентского задания на хинди-потоках.
            </p>
        @endif

        @foreach($lessons as $block)
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3"
                     data-testid="hindi-agent-drills-lesson"
                     data-lesson-id="{{ $block['lesson_id'] }}">
                <h2 class="text-base font-semibold">
                    {{ $block['course'] }} · {{ $block['lesson'] }}
                </h2>
                <p class="text-xs text-gray-500">
                    источник: {{ $block['source'] }} · занятие {{ $block['lesson_id'] }} · {{ count($block['items']) }} шт.
                </p>
                <ol class="list-decimal pl-5 space-y-2 text-sm">
                    @foreach($block['items'] as $item)
                        <li data-testid="hindi-agent-drills-item" data-item-type="{{ $item['type'] }}">
                            <span class="uppercase tracking-wide text-xs text-gray-400">{{ $item['type'] }}</span>
                            <div class="font-medium">{{ $item['prompt'] }}</div>
                            <div class="text-gray-600 dark:text-gray-300">ответ: {{ $item['answer'] }}</div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
