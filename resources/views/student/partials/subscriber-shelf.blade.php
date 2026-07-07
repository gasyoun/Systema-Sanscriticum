{{--
    «Полка подписчика» (H324): бесплатные лид-магниты для подписчиков рассылки.
    Показывается только если фича-флаг ВКЛ, пользователь — подписчик, и есть хотя
    бы один активный магнит. Ортогонально платному доступу: тут только свободные
    файлы/ссылки, никаких уроков/групп/тарифов.
--}}
@if (config('features.newsletter_subscribe') && ($subscriberMagnets ?? collect())->isNotEmpty())
    <section class="subscriber-shelf" style="margin: 28px 0; padding: 22px; border: 1px solid #e5d9c3; border-radius: 12px; background: #fff;">
        <h2 style="margin: 0 0 4px; font-size: 20px; color: #c0531f; font-weight: 600;">🎁 Полка подписчика</h2>
        <p style="margin: 0 0 16px; font-size: 14px; color: #7f6f57;">Бесплатные материалы, доступные вам как подписчику рассылки.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px;">
            @foreach ($subscriberMagnets as $magnet)
                @php $url = $magnet->publicUrl(); @endphp
                <div style="border: 1px solid #f0e6d2; border-radius: 10px; padding: 16px; background: #fcf9f2;">
                    <div style="font-weight: 700; font-size: 15px; margin-bottom: 6px;">{{ $magnet->title }}</div>
                    @if ($magnet->description)
                        <div style="font-size: 13px; color: #7f8c8d; margin-bottom: 10px;">{{ $magnet->description }}</div>
                    @endif
                    @if ($url)
                        <a href="{{ $url }}" target="_blank" rel="noopener"
                            style="display: inline-block; padding: 8px 16px; background: #E85C24; color: #fff; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none;">
                            Скачать / открыть
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
