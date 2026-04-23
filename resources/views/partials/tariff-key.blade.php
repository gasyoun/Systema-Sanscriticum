{{-- resources/views/partials/tariff-key.blade.php --}}
@php
    // Ожидает $tariff в контексте. Возвращает через $tariffKey.
    $tariffKey = $tariff->type === 'block' ? 'block_' . $tariff->block_number : 'full';
@endphp