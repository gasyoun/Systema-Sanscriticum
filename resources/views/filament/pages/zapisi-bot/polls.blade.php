<x-filament-panels::page>
    <div>
        <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-medium text-gray-900 dark:text-gray-100">Опросы бота в чатах групп</p>
            <p class="mt-1 text-gray-700 dark:text-gray-300">
                Опрос открытый: в чате видно, кто как проголосовал, здесь — то же поимённо. Имя из кабинета
                подставляется, если Telegram студента привязан к кабинету; иначе показываем имя и @username из Telegram.
                Голос можно поменять или отозвать — здесь всегда последний.
            </p>
        </div>
        <div class="mt-3">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
