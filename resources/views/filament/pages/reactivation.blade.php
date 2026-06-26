<x-filament-panels::page>
    @php($s = $this->summary())

    <div class="rounded-xl bg-gray-50 p-3 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p class="text-gray-700 dark:text-gray-200">
            <span class="font-medium">Кандидаты на реактивацию: {{ $s['total'] }}</span>
            — не продлил: {{ $s['not_renewed'] }} · без оплат: {{ $s['no_payment'] }}.
            Сегмент и исключения (Покинул/Исключён/Льготник/Выпускник) — те же, что у «Должников».
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Только чтение: страница ничего не рассылает. «Подсказка куратору» — какой
            win/loss-шаблон <code>/реактивация-*</code> предложить; написать студенту куратор
            решает сам, тёплым тоном и без давления (срочность и «все берут» отталкивают).
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
