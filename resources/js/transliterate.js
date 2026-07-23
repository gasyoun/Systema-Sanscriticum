/**
 * H1463 — /transliterate playground (vanilla JS, no React/Alpine).
 * Ports the live convert path from csl-guides Transliterator.js:
 *   slp1 = to_slp1(text);  devanagari = slp1_to_devanagari(slp1)
 * Never use iast_to_devanagari (display-only, wrong abugida).
 *
 * Grok 4.5 (grok-4.5) via xAI — Claude/Opus verify transcoder path after.
 */
import { to_slp1, slp1_to_devanagari } from './vendor/sanskrit-util.js';

const DIACRITICS = ['ā', 'ī', 'ū', 'ṛ', 'ṝ', 'ḷ', 'ḹ', 'ṃ', 'ḥ', 'ṅ', 'ñ', 'ṭ', 'ḍ', 'ṇ', 'ś', 'ṣ'];

function $(id) {
  return document.getElementById(id);
}

function update() {
  const input = $('hub-transliterate-input');
  const text = input ? input.value : '';
  const slp1 = text ? to_slp1(text) : '';
  const devanagari = slp1 ? slp1_to_devanagari(slp1) : '';

  const elDeva = $('hub-result-deva');
  const elIast = $('hub-result-iast');
  const elSlp1 = $('hub-result-slp1');
  if (elDeva) elDeva.textContent = devanagari || '—';
  if (elIast) elIast.textContent = text || '—';
  if (elSlp1) elSlp1.textContent = slp1 || '—';
}

function insertDiacritic(mark) {
  const input = $('hub-transliterate-input');
  if (!input) return;
  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? start;
  const before = input.value.slice(0, start);
  const after = input.value.slice(end);
  input.value = before + mark + after;
  const pos = start + mark.length;
  input.focus();
  input.setSelectionRange(pos, pos);
  update();
}

document.addEventListener('DOMContentLoaded', () => {
  const input = $('hub-transliterate-input');
  if (input) {
    input.addEventListener('input', update);
    update();
  }

  const bar = $('hub-diacritics');
  if (bar) {
    DIACRITICS.forEach((d) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className =
        'px-2 py-1 text-sm rounded-md bg-gray-800 text-gray-200 hover:bg-gray-700 hover:text-[#E85C24] border border-gray-700';
      btn.textContent = d;
      btn.addEventListener('click', () => insertDiacritic(d));
      bar.appendChild(btn);
    });
  }
});

// Export for manual/DOM assertion smoke (optional window hook in tests).
if (typeof window !== 'undefined') {
  window.__hubTransliterate = { to_slp1, slp1_to_devanagari };
}
