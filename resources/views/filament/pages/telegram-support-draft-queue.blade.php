<x-filament-panels::page>
    {{-- H3999 (рулинг I1b): очередь черновиков — Отправить / Изменить / Пропустить.
         Черновик с политикой draft_only (деньги, доступ, сертификат) отправляется
         ТОЛЬКО отсюда: кнопки под подсказкой в Telegram у него нет. --}}
    <div class="space-y-4">
        @php($drafts = $this->drafts)

        @if($drafts->isEmpty())
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-500 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-gray-400">
                Черновиков в очереди нет.
            </div>
        @endif

        @foreach($drafts as $draft)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-gray-950 dark:text-white">
                        {{ $draft->user?->name ?? 'Без привязки' }}
                    </span>
                    <span>· категория {{ $draft->category }}</span>
                    <span>· {{ $draft->created_at?->format('d.m.Y H:i') }}</span>

                    @if($draft->isDraftOnly())
                        <span class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                            требует проверки — кнопки в Telegram нет
                        </span>
                    @endif
                </div>

                @php($question = $this->questionFor($draft))
                @if(filled($question))
                    <div class="mt-3 border-l-2 border-gray-200 pl-3 text-sm italic text-gray-600 dark:border-white/10 dark:text-gray-400">
                        {{ \Illuminate\Support\Str::limit($question, 400) }}
                    </div>
                @endif

                @if($this->editingId === $draft->id)
                    <textarea
                        wire:model="editingText"
                        rows="8"
                        class="mt-3 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950 dark:text-gray-100"
                    ></textarea>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-filament::button wire:click="saveEdit" size="sm">Сохранить</x-filament::button>
                        <x-filament::button wire:click="cancelEdit" size="sm" color="gray">Отмена</x-filament::button>
                    </div>
                @else
                    <div class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-900 dark:bg-gray-950 dark:text-gray-100">{{ $draft->draft_text }}</div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-filament::button wire:click="send({{ $draft->id }})" size="sm">
                            Отправить
                        </x-filament::button>
                        <x-filament::button wire:click="startEdit({{ $draft->id }})" size="sm" color="gray">
                            Изменить
                        </x-filament::button>
                        <x-filament::button wire:click="skip({{ $draft->id }})" size="sm" color="danger">
                            Пропустить
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
