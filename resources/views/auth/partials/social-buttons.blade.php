{{-- Кнопки входа через соцсети. Показываются, только если у провайдера задан
     client_id (см. SocialAuthService::enabledProviders) — иначе блок пуст. --}}
@php
    $providers = \App\Services\SocialAuthService::enabledProviders();
    $meta = [
        'google' => ['Google', 'fa-google'],
        'vkontakte' => ['ВКонтакте', 'fa-vk'],
        'yandex' => ['Яндекс', 'fa-yandex'],
    ];
@endphp

@if (! empty($providers))
    <div class="mt-5">
        <div class="relative flex items-center my-4">
            <div class="flex-grow border-t border-gray-200"></div>
            <span class="mx-3 text-xs text-gray-400 uppercase tracking-wider">или войдите через</span>
            <div class="flex-grow border-t border-gray-200"></div>
        </div>
        <div class="grid gap-2">
            @foreach ($providers as $p)
                <a href="{{ route('social.redirect', $p) }}"
                   class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand transition-colors">
                    <i class="fab {{ $meta[$p][1] ?? 'fa-right-to-bracket' }}"></i>
                    {{ $meta[$p][0] ?? ucfirst($p) }}
                </a>
            @endforeach
        </div>
    </div>
@endif
