{{-- H476: самоотчётный «источник прихода» — единственный необязательный select,
     подписи здесь, ключи в AttributionService::SIGNUP_SOURCES. Тёмная (trial-модалка)
     и светлая (чекаут) темы выбираются параметром $dark. --}}
@php
    $labels = [
        'telegram' => 'Telegram',
        'youtube' => 'YouTube',
        'vk' => 'ВКонтакте',
        'search' => 'Поиск (Яндекс, Google)',
        'friend' => 'Рекомендация знакомых',
        'article' => 'Статья или материалы сайта',
        'other' => 'Другое',
    ];
    $classes = ($dark ?? false)
        ? 'block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition'
        : 'block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 transition';
@endphp
<select name="signup_source" class="{{ $classes }}">
    <option value="">— не указывать —</option>
    @foreach ($labels as $value => $label)
        <option value="{{ $value }}" @selected(old('signup_source') === $value)>{{ $label }}</option>
    @endforeach
</select>
@error('signup_source')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
