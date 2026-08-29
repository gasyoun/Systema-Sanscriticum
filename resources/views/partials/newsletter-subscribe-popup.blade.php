{{--
    Плавающее окно подписки (правый низ) — паттерн Claude Marketplaces
    «This week in Claude / Join N+ developers…», адаптированный под samskrte.ru.

    Backend тот же, что у инлайн-бокса H324: POST /subscribe + magic-link +
    полка подписчика. Самогейтится по features.newsletter_subscribe.

    Позиция: fixed bottom-right, выше пузыря чата (scw z=9998 / bottom 20),
    z-index 9990 — ниже cookie-баннера (z-10050). Пока cookie не приняты,
    попап не открывается (H3674): иначе «Новости санскрита» + cookie
    закрывают CTA/статы на первом гостевом заходе.

    Dismiss: localStorage `newsletter_popup_v1` = dismissed.
    Не показываем залогиненным подписчикам и после session success.
--}}
@if (config('features.newsletter_subscribe'))
    @php
        $justSubscribed = (bool) session('newsletter_subscribed');
        $hasFormErrors = $errors->has('email') || $errors->has('is_promo_agreed');
        $isSubscriber = auth()->check() && method_exists(auth()->user(), 'isNewsletterSubscriber')
            && auth()->user()->isNewsletterSubscriber();
    @endphp

    @unless ($isSubscriber && ! $justSubscribed && ! $hasFormErrors)
        <style>
            .nsp-root {
                position: fixed;
                right: 20px;
                bottom: 96px; /* над support-chat-widget (bottom 20 + ~56 bubble + gap) */
                z-index: 9990;
                width: min(360px, calc(100vw - 32px));
                font-family: inherit;
            }
            @media (max-width: 640px) {
                .nsp-root {
                    right: 12px;
                    left: 12px;
                    width: auto;
                    bottom: 88px;
                }
            }
            .nsp-card {
                background: #111622;
                border: 1px solid #1F2636;
                border-radius: 16px;
                box-shadow: 0 18px 50px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(232, 92, 36, 0.12);
                padding: 18px 18px 16px;
                color: #e2e8f0;
            }
            .nsp-title {
                font-weight: 800;
                font-size: 16px;
                line-height: 1.25;
                color: #fff;
                margin: 0 28px 6px 0;
            }
            .nsp-blurb {
                margin: 0 0 12px;
                font-size: 13px;
                line-height: 1.45;
                color: #94a3b8;
            }
            .nsp-blurb strong { color: #E85C24; font-weight: 800; }
            .nsp-row {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .nsp-input {
                flex: 1 1 160px;
                min-width: 0;
                padding: 10px 12px;
                border-radius: 10px;
                border: 1px solid #2a3347;
                background: #0A0D14;
                color: #fff;
                font-size: 14px;
            }
            .nsp-input::placeholder { color: #64748b; }
            .nsp-input:focus {
                outline: none;
                border-color: #E85C24;
                box-shadow: 0 0 0 2px rgba(232, 92, 36, 0.25);
            }
            .nsp-btn {
                padding: 10px 16px;
                border: none;
                border-radius: 10px;
                background: #E85C24;
                color: #fff;
                font-weight: 800;
                font-size: 14px;
                cursor: pointer;
                white-space: nowrap;
            }
            .nsp-btn:hover { background: #d14e1a; }
            .nsp-consent {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                margin-top: 10px;
                font-size: 11px;
                line-height: 1.4;
                color: #94a3b8;
            }
            .nsp-consent a { color: #E85C24; text-decoration: underline; }
            .nsp-consent a:hover { text-decoration: none; }
            .nsp-close {
                position: absolute;
                top: 10px;
                right: 10px;
                width: 28px;
                height: 28px;
                border: none;
                border-radius: 8px;
                background: transparent;
                color: #64748b;
                font-size: 18px;
                line-height: 1;
                cursor: pointer;
            }
            .nsp-close:hover { color: #fff; background: #1F2636; }
            .nsp-success {
                margin: 0;
                font-size: 14px;
                color: #6ee78b;
                line-height: 1.4;
            }
            .nsp-error {
                margin: 0 0 8px;
                font-size: 12px;
                color: #f87171;
            }
            .nsp-foot {
                margin-top: 10px;
                font-size: 12px;
            }
            .nsp-foot a { color: #94a3b8; text-decoration: underline; }
            .nsp-foot a:hover { color: #E85C24; text-decoration: none; }
        </style>

        <div
            class="nsp-root"
            data-analytics="newsletter-subscribe-popup"
            x-data="{
                open: {{ ($justSubscribed || $hasFormErrors) ? 'true' : 'false' }},
                justSubscribed: {{ $justSubscribed ? 'true' : 'false' }},
                hasErrors: {{ $hasFormErrors ? 'true' : 'false' }},
                init() {
                    if (this.justSubscribed) {
                        localStorage.setItem('newsletter_popup_v1', 'subscribed');
                        this.open = true;
                        return;
                    }
                    if (this.hasErrors) {
                        this.open = true;
                        return;
                    }
                    if (localStorage.getItem('newsletter_popup_v1')) {
                        this.open = false;
                        return;
                    }
                    if (! localStorage.getItem('cookie_consent_v1')) {
                        this.open = false;
                        return;
                    }
                    // Появление с небольшой задержкой — не бьёт по LCP.
                    setTimeout(() => { this.open = true }, 1800);
                },
                dismiss() {
                    localStorage.setItem('newsletter_popup_v1', 'dismissed');
                    this.open = false;
                }
            }"
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-3"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            role="dialog"
            aria-label="Подписка на рассылку"
        >
            <div class="nsp-card" style="position: relative;">
                <button type="button" class="nsp-close" @click="dismiss()" aria-label="Закрыть">×</button>

                @if ($justSubscribed)
                    <p class="nsp-success">
                        Спасибо! Проверьте почту — отправили ссылку в кабинет и бонусы.
                    </p>
                @else
                    <div class="nsp-title">Новости санскрита</div>
                    <p class="nsp-blurb">
                        Анонсы курсов, открытые занятия и бесплатные материалы — на почту.
                        <strong>Отписка в один клик.</strong>
                    </p>

                    @error('email')
                        <p class="nsp-error">{{ $message }}</p>
                    @enderror
                    @error('is_promo_agreed')
                        <p class="nsp-error">Нужно согласие на рассылку.</p>
                    @enderror

                    <form method="POST" action="{{ route('newsletter.subscribe') }}">
                        @csrf
                        <div aria-hidden="true"
                             style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">
                            <label>Оставьте это поле пустым
                                <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
                            </label>
                        </div>
                        <input type="hidden" name="ff_ts" value="{{ encrypt((string) now()->timestamp) }}">
                        <input type="hidden" name="utm_source" value="newsletter_popup">
                        <input type="hidden" name="utm_medium" value="site">
                        <input type="hidden" name="utm_campaign" value="floating_subscribe">

                        <div class="nsp-row">
                            <input
                                class="nsp-input"
                                type="email"
                                name="email"
                                required
                                autocomplete="email"
                                placeholder="ваш@email.com"
                                value="{{ old('email') }}"
                            >
                            <button class="nsp-btn" type="submit">Подписаться</button>
                        </div>

                        <label class="nsp-consent">
                            <input type="checkbox" name="is_promo_agreed" value="1" required style="margin-top:2px;">
                            <span>Я согласен(на) получать письма и принимаю
                                <a href="/dokumenty/soglasie-promo">условия рассылки</a>.</span>
                        </label>
                    </form>

                    <div class="nsp-foot">
                        <a href="{{ url('/online/materialy') }}">Бесплатные материалы</a>
                    </div>
                @endif
            </div>
        </div>
    @endunless
@endif
