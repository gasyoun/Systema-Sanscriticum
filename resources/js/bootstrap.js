/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Зеркало серверного rate-limit'а LeadController::store (1 / 5 сек / IP) —
// чтобы юзер видел "Отправка..." вместо голой 429.
let lastLeadSubmitAt = 0;

document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.action || !form.action.includes('/leads/store')) return;

    const now = Date.now();
    if (now - lastLeadSubmitAt < 5000) {
        event.preventDefault();
        return;
    }
    lastLeadSubmitAt = now;

    const button = form.querySelector('button[type="submit"]');
    if (!button) return;

    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = 'Отправка...';

    setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalText;
    }, 5000);
}, true);

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Reverb (WebSocket transport for the live-chat widget, H536).
// Guarded: only initialise when VITE_REVERB_APP_KEY is set, so pages don't
// attempt a failing WS connection before Reverb is deployed on the samskrte.ru
// host (a persistent WS process + reverse-proxy — an MG/Ivan deploy step,
// tracked in DEPLOY_QUEUE.md). Until then Echo stays off and nothing breaks.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
