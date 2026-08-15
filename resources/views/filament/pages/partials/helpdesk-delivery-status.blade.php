{{--
    Статус доставки исходящего ответа в Telegram.

    Включается ОБЕИМИ лентами хелпдеска (студенческой и гостевой) — именно
    поэтому вынесен в партиал: до 15-08-2026 они рендерились по-разному, и
    зависший ответ в гостевой ветке выглядел как отправленный.

    $message — App\Support\UnifiedMessage. Плашки нет вовсе, если доставку этого
    сообщения мы не отслеживаем (импорт из синка): см. SupportDeliveryStatus.
--}}
@if($message->delivery)
    <div class="msg-delivery {{ $message->delivery->cssClass() }}">
        <span>{{ $message->delivery->label() }}</span>
        @if($message->delivery->canRetry())
            <button type="button" class="msg-delivery-retry"
                wire:click="resendDelivery({{ $message->sourceId }})"
                wire:target="resendDelivery({{ $message->sourceId }})"
                wire:loading.attr="disabled">Дослать</button>
        @endif
    </div>
    @if($message->delivery->error)
        <div class="msg-delivery-error" title="{{ $message->delivery->error }}">{{ \Illuminate\Support\Str::limit($message->delivery->error, 60) }}</div>
    @endif
@endif
