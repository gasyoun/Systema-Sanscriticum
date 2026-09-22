{{--
    H5134 — Alpine-компонент сердечка «Избранное» + самодостаточная разметка.

    Гость: клик ведёт на вход (как у голоса ждуна — 401 → /login), состояние
    на странице не «залипает». Залогиненный: toggle в месте, без перезагрузки.
    Подключать один раз на странице (@include) рядом с сердечками.
--}}
@if(config('features.course_favorites', false))
<script>
function courseFavorite (props) {
    return {
        key: props.key,
        active: !!props.active,
        busy: false,

        async toggle (el) {
            if (this.busy) return;
            this.busy = true;

            const target = { course_id: null, waitlist_slug: null };
            if (this.key.startsWith('c:')) target.course_id = this.key.slice(2);
            else target.waitlist_slug = this.key.slice(2);

            try {
                const resp = await fetch('{{ route('shop.favorites.toggle') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(target),
                });
                if (resp.status === 401 || resp.redirected
                    || ! (resp.headers.get('content-type') || '').includes('application/json')) {
                    // Гость: ведём на вход; на карточке ничего не меняем.
                    window.location.href = '/login';
                    return;
                }
                const data = await resp.json();
                if (data.ok) {
                    this.active = !!data.favorited;
                    // Кабинет слушает: строка «Избранного» уходит без перезагрузки.
                    window.dispatchEvent(new CustomEvent('favorite-toggled', {
                        detail: { key: this.key, favorited: this.active },
                    }));
                }
            } catch (e) {
                // Тихо: сердечко — лёгкий сигнал, сетевые сбои не мешают странице.
            } finally {
                this.busy = false;
            }
        },
    };
}
</script>
@endif
