{{--
    Плашка режима просмотра за пользователя (H1947).

    Подмешивается перед </body> из ImpersonationGuard — поэтому одна и та же
    разметка работает и в кабинете, и на витрине, и в Filament, без правки
    макетов. Стили — инлайновые: у кабинета Tailwind с CDN, у Filament свой
    сборанный CSS, общего класса, на который можно было бы опереться, нет.

    Плашка фиксирована сверху, а контент сдвигается вниз на её высоту: под
    Filament фиксированный сайдбар и липкий топбар padding у body игнорируют,
    поэтому им top переставляется отдельно.
--}}
@php
    $isManager = $mode === \App\Support\Impersonation::MODE_MANAGER;
    $label = $isManager ? 'Вы в режиме куратора' : 'Вы смотрите кабинет как';
    $exit = $isManager ? 'Вернуться в супер-админ' : 'Выйти из режима';
@endphp

<style>
    body { padding-top: {{ $height }}px !important; box-sizing: border-box; }
    .fi-sidebar, .fi-topbar { top: {{ $height }}px !important; }
    #impersonation-banner {
        position: fixed; top: 0; left: 0; right: 0; z-index: 2147483000;
        height: {{ $height }}px; box-sizing: border-box;
        display: flex; align-items: center; justify-content: center;
        gap: 12px; flex-wrap: nowrap; overflow: hidden;
        padding: 0 14px;
        background: {{ $isManager ? '#7C2D12' : '#9A3412' }};
        color: #FFF7ED;
        font: 600 14px/1.2 'Nunito Sans', system-ui, -apple-system, sans-serif;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .25);
    }
    #impersonation-banner .imp-text {
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    #impersonation-banner .imp-who { font-weight: 800; }
    #impersonation-banner .imp-from { opacity: .75; font-weight: 500; }
    #impersonation-banner button {
        flex: 0 0 auto; cursor: pointer;
        padding: 6px 14px; border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, .45);
        background: rgba(255, 255, 255, .12); color: #FFF7ED;
        font: inherit; font-weight: 700;
    }
    #impersonation-banner button:hover { background: rgba(255, 255, 255, .24); }
</style>

<div id="impersonation-banner" role="status">
    <span class="imp-text">
        {{ $label }}
        <span class="imp-who">{{ $actingAs?->name ?? 'пользователь' }}</span>
        @if ($impersonator)
            <span class="imp-from">· вошёл {{ $impersonator->name }}</span>
        @endif
    </span>

    <form method="POST" action="{{ route('impersonate.stop') }}">
        @csrf
        <button type="submit">{{ $exit }}</button>
    </form>
</div>
