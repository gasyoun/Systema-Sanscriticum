// H1463 — /transliterate playground (Sanskrit-HUB L5 workstream A, v0).
//
// Live IAST → Devanāgarī + SLP1 conversion, driven by the VENDORED canonical
// transcoder (./vendor/sanskrit-util.js — a verbatim copy of sanskrit-lexicon/
// sanskrit-util, never a hand-rolled table). Vanilla JS port of csl-guides'
// React Transliterator: Systema is Blade + minimal Vite + axios (no React/Alpine),
// so this keeps the to_slp1 → slp1_to_devanagari core and drops the JSX.
//
// The abugida-correct path is to_slp1(iast) → slp1_to_devanagari(slp1); the
// module's iast_to_devanagari is display-only and wrong (turns "rāma" into
// "रआमअ") — see the module's own comments. Never call it here.
import { to_slp1, slp1_to_devanagari } from './vendor/sanskrit-util.js';

const DIACRITICS = ['ā', 'ī', 'ū', 'ṛ', 'ṝ', 'ḷ', 'ḹ', 'ṃ', 'ḥ', 'ṅ', 'ñ', 'ṭ', 'ḍ', 'ṇ', 'ś', 'ṣ'];

const EMPTY = 'Начните печатать, чтобы увидеть результат…';

function init() {
    const input = document.getElementById('translit-input');
    const devaOut = document.getElementById('translit-deva');
    const iastOut = document.getElementById('translit-iast');
    const slp1Out = document.getElementById('translit-slp1');
    if (!input || !devaOut || !iastOut || !slp1Out) return;

    function render() {
        const text = input.value || '';
        const slp1 = text ? to_slp1(text) : '';
        const deva = slp1 ? slp1_to_devanagari(slp1) : '';

        if (deva) {
            devaOut.textContent = deva;
            devaOut.classList.remove('is-empty');
        } else {
            devaOut.textContent = EMPTY;
            devaOut.classList.add('is-empty');
        }
        iastOut.textContent = text || '—';
        slp1Out.textContent = slp1 || '—';
    }

    input.addEventListener('input', render);

    // Diacritic-insert buttons (inserted at the cursor), progressively enhanced
    // onto a container the Blade view renders.
    const buttons = document.querySelectorAll('[data-diacritic]');
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const mark = btn.getAttribute('data-diacritic') || '';
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            input.value = input.value.slice(0, start) + mark + input.value.slice(end);
            const pos = start + mark.length;
            input.focus();
            input.setSelectionRange(pos, pos);
            render();
        });
    });

    render();
}

// Export for potential reuse/tests; the marks list mirrors the Blade buttons.
export { DIACRITICS };

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}
