{{--
    Инлайн-бокс «Подписаться на рассылку» (H324), стиль — GitHub changelog-футер.
    Не-студент вводит ТОЛЬКО email + согласие; получает кабинет и лид-магниты.
    Самогейтится по фича-флагу: при OFF ничего не рендерит.

    Использование:  <x-newsletter-subscribe />
    Необязательно:  <x-newsletter-subscribe title="..." blurb="..." />
--}}
@if (config('features.newsletter_subscribe'))
    @props([
        'title' => 'Подпишитесь на рассылку',
        'blurb' => 'Оставьте email — заведём личный кабинет и подарим бесплатные материалы.',
    ])

    <div class="newsletter-subscribe" style="border: 1px solid #e5d9c3; border-radius: 10px; padding: 20px 22px; background: #fcf9f2; max-width: 560px;">
        @if (session('newsletter_subscribed'))
            <p style="margin: 0; font-size: 15px; color: #2f7a3f;">
                🎉 Спасибо! Проверьте почту — мы отправили ссылку для входа в кабинет и ваши бонусы.
            </p>
        @else
            <div style="font-weight: 700; font-size: 16px; margin-bottom: 4px;">{{ $title }}</div>
            <p style="margin: 0 0 12px; font-size: 14px; color: #7f6f57;">{{ $blurb }}</p>

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
                        style="flex: 1 1 220px; padding: 10px 12px; border: 1px solid #d8c8ab; border-radius: 8px; font-size: 15px;">
                    <button type="submit"
                        style="padding: 10px 20px; background: #E85C24; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer;">
                        Подписаться
                    </button>
                </div>
                <label style="display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; font-size: 12px; color: #7f6f57;">
                    <input type="checkbox" name="is_promo_agreed" value="1" required style="margin-top: 2px;">
                    <span>Я согласен(на) получать письма и принимаю
                        <a href="/dokumenty/soglasie-promo" style="color: #E85C24;">условия рассылки</a>.</span>
                </label>
            </form>
        @endif
    </div>
@endif
