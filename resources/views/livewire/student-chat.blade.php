{{-- Веб-чат поддержки: опрос раз в 10с подтягивает ответ ИИ из очереди и реплики куратора. --}}
<div class="font-nunito max-w-3xl mx-auto" wire:poll.10s>
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden flex flex-col" style="height: min(70vh, calc(100dvh - 11rem)); min-height: 280px;">

        {{-- Шапка --}}
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 shrink-0">
            <div class="w-10 h-10 rounded-xl bg-[#E85C24]/10 text-[#E85C24] flex items-center justify-center">
                <i class="fas fa-headset"></i>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-gray-900 leading-tight">Чат поддержки</h3>
                <p class="text-[12px] sm:text-[13px] text-gray-500 leading-snug">Сначала отвечает ИИ-куратор. Напишите «позови куратора» — подключится человек.</p>
            </div>
        </div>

        {{-- Лента сообщений --}}
        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4 bg-gray-50/40">
            @forelse ($messages as $m)
                @php
                    $isStudent = $m->role === 'user';
                    $who = match ($m->role) {
                        'user' => 'Вы',
                        'curator' => '👨‍🏫 '.($m->answeredBy->name ?? 'Куратор'),
                        default => '🤖 ИИ-куратор',
                    };
                    $bubble = match ($m->role) {
                        'user' => 'bg-gray-100 border-gray-200',
                        'curator' => 'bg-primary-50 border-blue-200 bg-blue-50',
                        default => 'bg-orange-50/70 border-orange-100',
                    };
                @endphp
                <div class="flex {{ $isStudent ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[85%] rounded-2xl border p-3.5 {{ $bubble }}">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs font-extrabold {{ $isStudent ? 'text-gray-700' : 'text-gray-800' }}">{{ $who }}</span>
                            <span class="text-[11px] text-gray-400">{{ $m->created_at->format('d.m.Y H:i') }}</span>
                        </div>
                        <p class="text-sm text-gray-800 whitespace-pre-line leading-relaxed">{{ $m->text }}</p>
                    </div>
                </div>
            @empty
                <div class="h-full flex flex-col items-center justify-center text-center text-gray-400 px-6">
                    <i class="fas fa-comments text-4xl mb-3 text-gray-300"></i>
                    <p class="text-sm">Здесь можно задать вопрос по учебе, оплате или доступу.<br>Напишите первым сообщением — ответит ИИ-куратор.</p>
                </div>
            @endforelse
        </div>

        {{-- Ввод --}}
        <form wire:submit="send" class="border-t border-gray-100 p-3 flex items-end gap-2 shrink-0">
            <textarea wire:model="newMessage" rows="1"
                      placeholder="Напишите сообщение..."
                      class="flex-1 resize-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition"
                      x-data x-on:keydown.enter.prevent="$wire.send()"></textarea>
            <button type="submit"
                    class="shrink-0 bg-[#E85C24] hover:bg-[#d04a15] text-white font-bold w-12 h-12 rounded-xl shadow transition-colors flex items-center justify-center"
                    wire:loading.attr="disabled">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>
