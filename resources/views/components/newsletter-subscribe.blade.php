{{--
    Инлайн-бокс «Подписаться на рассылку» (H324), стиль — GitHub changelog-футер.
    Не-студент вводит ТОЛЬКО email + согласие; получает кабинет и лид-магниты.
    Самогейтится по фича-флагу: при OFF ничего не рендерит.

    Использование:  <x-newsletter-subscribe />
    Необязательно:  <x-newsletter-subscribe title="..." blurb="..." variant="dark" />
    variant="dark" — для тёмных секций (главная витрина); по умолчанию светлая карточка.
--}}
@if (config('features.newsletter_subscribe'))
    @props([
        'title' => 'Подпишитесь на рассылку',
        'blurb' => 'Оставьте email — заведем личный кабинет и подарим бесплатные материалы.',
        'variant' => 'light',
    ])

    @php
        $isDark = $variant === 'dark';
        $boxStyle = $isDark
            ? 'border: 1px solid rgba(232,92,36,0.35); border-radius: 10px; padding: 24px 26px; background: rgba(31,41,55,0.6); max-width: 480px; backdrop-filter: blur(4px);'
            : 'border: 1px solid #e5d9c3; border-radius: 10px; padding: 20px 22px; background: #fcf9f2; max-width: 480px;';
        $titleStyle = $isDark
            ? 'font-weight: 700; font-size: 17px; margin-bottom: 4px; color: #fff;'
            : 'font-weight: 700; font-size: 16px; margin-bottom: 4px;';
        $blurbStyle = $isDark
            ? 'margin: 0 0 14px; font-size: 14px; color: #9ca3af;'
            : 'margin: 0 0 12px; font-size: 14px; color: #7f6f57;';
        $inputStyle = $isDark
            ? 'flex: 1 1 220px; padding: 10px 12px; border: 1px solid #4b5563; border-radius: 8px; font-size: 15px; background: #111827; color: #fff;'
            : 'flex: 1 1 220px; padding: 10px 12px; border: 1px solid #d8c8ab; border-radius: 8px; font-size: 15px;';
        $consentStyle = $isDark
            ? 'display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; font-size: 12px; color: #9ca3af;'
            : 'display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; font-size: 12px; color: #7f6f57;';
        $consentLinkStyle = 'color: #E85C24;';
        $successStyle = $isDark
            ? 'margin: 0; font-size: 15px; color: #6ee78b;'
            : 'margin: 0; font-size: 15px; color: #2f7a3f;';
    @endphp

    <div class="newsletter-subscribe" style="{{ $boxStyle }}">
        @if (session('newsletter_subscribed'))
            <p style="{{ $successStyle }}">
                🎉 Спасибо! Проверьте почту — мы отправили ссылку для входа в кабинет и ваши бонусы.
            </p>
        @else
            <div style="{{ $titleStyle }}">{{ $title }}</div>
            <p style="{{ $blurbStyle }}">{{ $blurb }}</p>

            @error('email')
                <p style="margin: 0 0 8px; font-size: 13px; color: #c0392b;">{{ $message }}</p>
            @enderror
            @error('is_promo_agreed')
                <p style="margin: 0 0 8px; font-size: 13px; color: #c0392b;">Нужно согласие на рассылку.</p>
            @enderror

            <form method="POST" action="{{ route('newsletter.subscribe') }}">
                @csrf
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <input type="email" name="email" required placeholder="you@example.com"
                        value="{{ old('email') }}"
                        style="{{ $inputStyle }}">
                    <button type="submit"
                        style="padding: 10px 20px; background: #E85C24; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer;">
                        Подписаться
                    </button>
                </div>
                <label style="{{ $consentStyle }}">
                    <input type="checkbox" name="is_promo_agreed" value="1" required style="margin-top: 2px;">
                    <span>Я согласен(на) получать письма и принимаю
                        <a href="/dokumenty/soglasie-promo" style="{{ $consentLinkStyle }}">условия рассылки</a>.</span>
                </label>
            </form>
        @endif
    </div>
@endif
