{{-- resources/views/partials/csrf-token-refresh.blade.php
     H1774 — shared anti-419 helper (checkout's own copy predates this and is
     left as-is, out of scope). Fetches a fresh token from GET /csrf-token and
     writes it into <meta name=csrf-token> + any input[name=_token] on the page. --}}
<script>
    window.CsrfTokenRefresh = (function () {
        // Таймаут обязателен: на мобильной сети fetch без AbortController может
        // висеть десятками секунд, оставляя форму заблокированной.
        const TOKEN_TIMEOUT_MS = 4000;

        function applyToken(token) {
            if (!token) return null;
            const meta = document.querySelector('meta[name=csrf-token]');
            if (meta) meta.content = token;
            document.querySelectorAll('input[name=_token]').forEach((i) => { i.value = token; });
            return token;
        }

        async function fetchToken() {
            const ctl = new AbortController();
            const timer = setTimeout(() => ctl.abort(), TOKEN_TIMEOUT_MS);
            try {
                const r = await fetch(@json(route('csrf.token')), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: ctl.signal,
                });
                const data = await r.json();
                return data.token;
            } finally {
                clearTimeout(timer);
            }
        }

        // Best-effort: сеть недоступна/таймаут — токен мог и не протухнуть,
        // вызывающий код всё равно продолжает свой сабмит/фетч.
        async function refresh() {
            try {
                return applyToken(await fetchToken());
            } catch (_) {
                return null;
            }
        }

        return { fetchToken, applyToken, refresh };
    })();
</script>
