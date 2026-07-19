<x-filament-panels::page>
    {{-- H1197 — Jivo-паритет Pillar 2: живой список посетителей витрины + «написать первым». --}}

    <div
        @if (is_null($composeFor)) wire:poll.15s @endif
        class="fi-visitors-online"
    >
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Посетители на сайте прямо сейчас — обновляется автоматически. Вы можете написать первым:
            сообщение всплывет в чат-виджете посетителя. Список пуст, если сейчас никого нет онлайн
            (или отслеживание присутствия выключено).
        </p>

        @if ($this->visitors->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-8 text-center text-gray-500 dark:text-gray-400">
                Сейчас на сайте нет активных посетителей.
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Посетитель</th>
                            <th class="px-4 py-2 font-medium">Город</th>
                            <th class="px-4 py-2 font-medium">Страница</th>
                            <th class="px-4 py-2 font-medium">На сайте</th>
                            <th class="px-4 py-2 font-medium text-right">Действие</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->visitors as $visitor)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full bg-green-500"></span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $visitor['name'] }}</span>
                                        @if ($visitor['is_student'])
                                            <span class="text-xs rounded bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300 px-1.5 py-0.5">студент</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $visitor['location'] ? '📍 ' . $visitor['location'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    <span title="{{ $visitor['current_url'] }}">{{ $visitor['page_label'] }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $visitor['time_on_site'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($composeFor !== $visitor['id'])
                                        <x-filament::button size="sm" wire:click="startCompose({{ $visitor['id'] }})">
                                            Написать
                                        </x-filament::button>
                                    @else
                                        <span class="text-xs text-gray-400">пишем ниже…</span>
                                    @endif
                                </td>
                            </tr>

                            @if ($composeFor === $visitor['id'])
                                <tr>
                                    <td colspan="5" class="px-4 py-3 bg-gray-50 dark:bg-white/5">
                                        <div class="flex flex-col gap-2">
                                            <label class="text-xs text-gray-500 dark:text-gray-400">
                                                Сообщение для «{{ $visitor['name'] }}» — оператор пишет первым
                                            </label>
                                            <textarea
                                                wire:model="proactiveMessage"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Здравствуйте! Подсказать что-нибудь по курсам?"
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm"
                                            ></textarea>
                                            @error('proactiveMessage')
                                                <span class="text-xs text-danger-600">{{ $message }}</span>
                                            @enderror
                                            <div class="flex gap-2">
                                                <x-filament::button size="sm" wire:click="sendProactive" wire:loading.attr="disabled">
                                                    Отправить
                                                </x-filament::button>
                                                <x-filament::button size="sm" color="gray" wire:click="cancelCompose">
                                                    Отмена
                                                </x-filament::button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
