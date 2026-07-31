<x-filament-panels::page>
    <div class="rounded-xl bg-gray-50 p-3 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p class="text-gray-700 dark:text-gray-200">
            Все enrollment’ы марафона: время на тап-выбор и кнопки
            <strong>Сброс Д1 / Д2 / Д1+Д2</strong> — чтобы участник мог пройти
            опрос заново по тому же magnet-токену.
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Сброс чистит только engaged_at и quiz_seconds дня. Не трогает доставку
            (dayN_completed_at), оплату и вопрос к консультации.
            URL: /online/konsultaciya/dine/1|2/{token}.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
