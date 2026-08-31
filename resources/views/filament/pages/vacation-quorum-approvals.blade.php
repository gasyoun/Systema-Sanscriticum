<x-filament-panels::page>
    <div>
        <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-medium text-gray-900 dark:text-gray-100">Каникулы групп: опросы кворума</p>
            <p class="mt-1 text-gray-700 dark:text-gray-300">
                Группу спрашиваем в её TG-чате 25–31 августа, если дата выхода из каникул не проставлена.
                Дедлайн +14 дней: кворум платных ≥ min_size (по умолчанию 4) — куратору напоминание;
                нет — предложение о распускании (одобрить здесь или кнопкой в админ-чате).
            </p>
        </div>
        <div class="mt-3">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>