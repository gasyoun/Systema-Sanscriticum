{{-- Превью охвата рассылки перед отправкой. --}}
<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4 mb-4 text-sm">
    <p class="font-bold text-gray-800 dark:text-gray-100 mb-2">
        Выбрано студентов: {{ $total }}
    </p>
    <ul class="space-y-1 text-gray-600 dark:text-gray-300">
        <li>✈️ С Telegram: <span class="font-semibold tabular-nums">{{ $tg }}</span></li>
        <li>💬 С VK: <span class="font-semibold tabular-nums">{{ $vk }}</span></li>
        <li class="pt-1 border-t border-gray-200 dark:border-white/10 mt-1">
            Получат сообщение (есть хотя бы один мессенджер):
            <span class="font-bold text-info-600 dark:text-info-400 tabular-nums">{{ $reachable }}</span>
        </li>
    </ul>
    @if ($reachable < $total)
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
            {{ $total - $reachable }} студ. не получат — у них не привязан ни один мессенджер.
        </p>
    @endif
</div>
