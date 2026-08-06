/**
 * PayPal claim paste-to-fill (H2215).
 *
 * Pure parser + form wiring for /paypal/{tariff} step 2.
 * Never invents missing fields; never auto-submits.
 *
 * Browser: window.PaypalClaimPaste.parse / .bindForm
 * Node (PHPUnit): module.exports
 */
(function (root, factory) {
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = factory();
    } else {
        root.PaypalClaimPaste = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var MONTHS_EN = {
        jan: 1, january: 1,
        feb: 2, february: 2,
        mar: 3, march: 3,
        apr: 4, april: 4,
        may: 5,
        jun: 6, june: 6,
        jul: 7, july: 7,
        aug: 8, august: 8,
        sep: 9, sept: 9, september: 9,
        oct: 10, october: 10,
        nov: 11, november: 11,
        dec: 12, december: 12,
    };

    var REQUIRED_KEYS = ['paypal_payer', 'paid_on', 'foreign_amount', 'foreign_currency'];
    var ALL_KEYS = REQUIRED_KEYS.concat(['paypal_txn']);

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function toIsoDate(year, month, day) {
        var y = Number(year);
        var m = Number(month);
        var d = Number(day);
        if (!y || !m || !d || m < 1 || m > 12 || d < 1 || d > 31) {
            return null;
        }
        if (y < 100) {
            y += 2000;
        }
        // Reject clearly impossible calendar dates without inventing a "fix".
        var dt = new Date(Date.UTC(y, m - 1, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) {
            return null;
        }
        return y + '-' + pad2(m) + '-' + pad2(d);
    }

    function parseEnMonthDate(raw) {
        // "July 30, 2026" | "30 Jul 2026" | "Jul 30 2026"
        var m1 = raw.match(/^([A-Za-z]+)\s+(\d{1,2}),?\s+(\d{4})$/);
        if (m1) {
            var mon1 = MONTHS_EN[m1[1].toLowerCase()];
            if (mon1) {
                return toIsoDate(m1[3], mon1, m1[2]);
            }
        }
        var m2 = raw.match(/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/);
        if (m2) {
            var mon2 = MONTHS_EN[m2[2].toLowerCase()];
            if (mon2) {
                return toIsoDate(m2[3], mon2, m2[1]);
            }
        }
        return null;
    }

    function parseNumericDate(raw) {
        // DD.MM.YYYY | DD/MM/YYYY | DD-MM-YYYY
        var m = raw.match(/^(\d{1,2})[./\-](\d{1,2})[./\-](\d{2,4})$/);
        if (!m) {
            return null;
        }
        return toIsoDate(m[3], m[2], m[1]);
    }

    function parseIsoDate(raw) {
        var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) {
            return null;
        }
        return toIsoDate(m[1], m[2], m[3]);
    }

    function normalizeAmount(raw) {
        if (!raw) {
            return null;
        }
        var s = String(raw).trim();
        // Space thousands: "1 234,56" or "1,234.56"
        s = s.replace(/\s+/g, '');
        if (s.indexOf(',') >= 0 && s.indexOf('.') >= 0) {
            // Assume comma is thousands separator when both present.
            s = s.replace(/,/g, '');
        } else if (s.indexOf(',') >= 0 && s.indexOf('.') < 0) {
            // RU decimal comma.
            s = s.replace(',', '.');
        }
        if (!/^\d+(\.\d{1,2})?$/.test(s)) {
            return null;
        }
        // Strip trailing .00 noise for display but keep two-decimal values when meaningful.
        var n = Number(s);
        if (!isFinite(n) || n <= 0) {
            return null;
        }
        if (Math.abs(n - Math.round(n)) < 1e-9) {
            return String(Math.round(n));
        }
        return n.toFixed(2).replace(/\.?0+$/, function (tail) {
            // Keep at least one decimal if original had fraction, else integer.
            return tail.indexOf('.') === 0 && /[1-9]/.test(s.split('.')[1] || '') ? tail : '';
        }) || String(n);
    }

    function normalizeCurrency(raw) {
        if (!raw) {
            return null;
        }
        var u = String(raw).trim().toUpperCase();
        if (u === '$' || u === 'USD' || u === 'US$' || u === 'DOLLAR' || u === 'DOLLARS') {
            return 'USD';
        }
        if (u === '€' || u === 'EUR' || u === 'EURO' || u === 'EUROS') {
            return 'EUR';
        }
        return null;
    }

    function firstMatch(text, patterns) {
        for (var i = 0; i < patterns.length; i++) {
            var m = text.match(patterns[i]);
            if (m && m[1]) {
                return m[1].trim();
            }
        }
        return null;
    }

    function extractTxn(text) {
        var raw = firstMatch(text, [
            /Transaction\s*ID\s*[:#]?\s*([A-Z0-9]{12,25})\b/i,
            /Идентификатор\s+транзакции\s*[:#]?\s*([A-Z0-9]{12,25})\b/i,
            /Transaction\s*number\s*[:#]?\s*([A-Z0-9]{12,25})\b/i,
            /Номер\s+транзакции\s*[:#]?\s*([A-Z0-9]{12,25})\b/i,
            /\bID\s*транзакции\s*[:#]?\s*([A-Z0-9]{12,25})\b/i,
        ]);
        return raw || null;
    }

    function extractDate(text) {
        // Labeled lines first.
        var labeled = firstMatch(text, [
            /(?:^|\n)\s*(?:Date|Дата|Payment\s+date|Дата\s+платежа|Дата\s+оплаты)\s*[:\-]?\s*([^\n\r]+)/i,
        ]);
        if (labeled) {
            var piece = labeled.trim().split(/\s{2,}|\s+[|•]\s+/)[0].trim();
            var iso = parseIsoDate(piece) || parseNumericDate(piece) || parseEnMonthDate(piece);
            if (iso) {
                return iso;
            }
            // Maybe "July 30, 2026 12:34:56 PDT" — take first 3 tokens.
            var tokens = piece.replace(/,/g, ' ').split(/\s+/).filter(Boolean);
            if (tokens.length >= 3) {
                iso = parseEnMonthDate(tokens.slice(0, 3).join(' '))
                    || parseEnMonthDate(tokens[0] + ' ' + tokens[1] + ' ' + tokens[2]);
                if (iso) {
                    return iso;
                }
            }
        }

        // Free-form ISO / numeric / English month anywhere.
        var freeIso = text.match(/\b(\d{4}-\d{2}-\d{2})\b/);
        if (freeIso) {
            var iso2 = parseIsoDate(freeIso[1]);
            if (iso2) {
                return iso2;
            }
        }
        var freeNum = text.match(/\b(\d{1,2}[./\-]\d{1,2}[./\-]\d{2,4})\b/);
        if (freeNum) {
            var iso3 = parseNumericDate(freeNum[1]);
            if (iso3) {
                return iso3;
            }
        }
        var freeEn = text.match(
            /\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+\d{4})\b/i
        );
        if (freeEn) {
            return parseEnMonthDate(freeEn[1].replace(/,/g, ''));
        }
        var freeEn2 = text.match(
            /\b(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{4})\b/i
        );
        if (freeEn2) {
            return parseEnMonthDate(freeEn2[1]);
        }
        return null;
    }

    function extractAmountCurrency(text) {
        var amount = null;
        var currency = null;

        // "$50.00 USD" / "€45.00" / "50.00 USD" / "50,00 EUR"
        var patterns = [
            /(?:Amount|Сумма|You\s+sent|Вы\s+отправили|Payment\s+of|платеж(?:а|у)?\s+на\s+сумму)?\s*[:\-]?\s*\$\s*([\d\s.,]+)\s*(USD)?\b/i,
            /(?:Amount|Сумма|You\s+sent|Вы\s+отправили)?\s*[:\-]?\s*€\s*([\d\s.,]+)\s*(EUR)?\b/i,
            /(?:Amount|Сумма|You\s+sent|Вы\s+отправили|Payment\s+of)?\s*[:\-]?\s*([\d\s.,]+)\s*(USD|EUR|\$|€)\b/i,
            /\b([\d\s.,]+)\s*(USD|EUR)\b/i,
            /\$\s*([\d\s.,]+)/,
            /€\s*([\d\s.,]+)/,
        ];

        for (var i = 0; i < patterns.length; i++) {
            var m = text.match(patterns[i]);
            if (!m) {
                continue;
            }
            var a = normalizeAmount(m[1]);
            if (!a) {
                continue;
            }
            var c = null;
            if (m[2]) {
                c = normalizeCurrency(m[2]);
            }
            if (!c) {
                // Symbol was on the left of the number in $ / € patterns.
                if (/\$/.test(m[0]) && !/EUR|€/i.test(m[0])) {
                    c = 'USD';
                } else if (/€|EUR/i.test(m[0])) {
                    c = 'EUR';
                }
            }
            amount = a;
            currency = c;
            break;
        }

        // Currency-only labeled line fallback (rare).
        if (!currency) {
            var cOnly = firstMatch(text, [
                /(?:Currency|Валюта)\s*[:\-]?\s*(USD|EUR|\$|€)/i,
            ]);
            currency = normalizeCurrency(cOnly);
        }

        return { foreign_amount: amount, foreign_currency: currency };
    }

    function looksLikeEmail(s) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
    }

    function looksLikeHandle(s) {
        return /^@[A-Za-z0-9._-]{2,}$/.test(s);
    }

    function cleanPayerCandidate(raw) {
        if (!raw) {
            return null;
        }
        var s = String(raw).trim();
        // Strip trailing punctuation and role labels.
        s = s.replace(/^["'«]+|["'».,;:]+$/g, '').trim();
        s = s.replace(/\s+\(.*\)$/, '').trim();
        // Prefer email / @handle if embedded.
        var email = s.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/);
        if (email) {
            return email[0];
        }
        var handle = s.match(/@[A-Za-z0-9._-]{2,}/);
        if (handle) {
            return handle[0];
        }
        // Plain name alone is not useful for reconciliation — skip inventing.
        if (looksLikeEmail(s) || looksLikeHandle(s)) {
            return s;
        }
        return null;
    }

    function extractPayer(text) {
        // Prefer explicit From / Sender / From account labels (payer, not recipient).
        var labeled = firstMatch(text, [
            /(?:^|\n)\s*(?:From|Sender|From\s+account|Paid\s+from|С\s+аккаунта|Отправитель|От|Плательщик|С\s+какого\s+PayPal)\s*[:\-]?\s*([^\n\r]+)/i,
        ]);
        var fromLabeled = cleanPayerCandidate(labeled);
        if (fromLabeled) {
            return fromLabeled;
        }

        // "Buyer: email" / "Customer: email"
        var buyer = firstMatch(text, [
            /(?:^|\n)\s*(?:Buyer|Customer|Payer|Покупатель)\s*[:\-]?\s*([^\n\r]+)/i,
        ]);
        var fromBuyer = cleanPayerCandidate(buyer);
        if (fromBuyer) {
            return fromBuyer;
        }

        // Do NOT take "To:" / "Sent to:" — that is the school recipient.
        return null;
    }

    /**
     * @param {string} text
     * @returns {object}
     */
    function parse(text) {
        var result = {
            paypal_payer: null,
            paid_on: null,
            foreign_amount: null,
            foreign_currency: null,
            paypal_txn: null,
            found: [],
            missing: [],
        };

        if (text == null || String(text).trim() === '') {
            result.missing = ALL_KEYS.slice();
            return result;
        }

        var body = String(text).replace(/\r\n/g, '\n');

        result.paypal_txn = extractTxn(body);
        result.paid_on = extractDate(body);
        var ac = extractAmountCurrency(body);
        result.foreign_amount = ac.foreign_amount;
        result.foreign_currency = ac.foreign_currency;
        result.paypal_payer = extractPayer(body);

        ALL_KEYS.forEach(function (key) {
            if (result[key]) {
                result.found.push(key);
            } else {
                result.missing.push(key);
            }
        });

        return result;
    }

    function fieldLabelRu(key) {
        switch (key) {
            case 'paypal_payer':
                return 'from';
            case 'paid_on':
                return 'date';
            case 'foreign_amount':
                return 'amount';
            case 'foreign_currency':
                return 'currency';
            case 'paypal_txn':
                return 'txn';
            default:
                return key;
        }
    }

    function statusMessage(parsed) {
        var filled = parsed.found.map(fieldLabelRu);
        var need = parsed.missing.filter(function (k) {
            return REQUIRED_KEYS.indexOf(k) >= 0;
        }).map(fieldLabelRu);

        if (filled.length === 0) {
            return {
                kind: 'empty',
                text: 'Ничего не распознали — заполните поля вручную или вставьте другой фрагмент из PayPal.',
            };
        }

        var parts = [];
        parts.push('Заполнили: ' + filled.join(', ') + '.');
        if (need.length) {
            parts.push('Не нашли: ' + need.join(', ') + ' — заполните вручную.');
        } else {
            parts.push('Проверьте поля и отправьте форму.');
        }
        return { kind: need.length ? 'partial' : 'ok', text: parts.join(' ') };
    }

    /**
     * Apply parsed values to a claim form. Only overwrites with non-null matches.
     * Never submits.
     *
     * @param {HTMLFormElement|Document} root
     * @param {object} parsed
     */
    function applyToForm(root, parsed) {
        var scope = root && root.querySelector ? root : document;
        var map = {
            paypal_payer: 'input[name="paypal_payer"]',
            paid_on: 'input[name="paid_on"]',
            foreign_amount: 'input[name="foreign_amount"]',
            foreign_currency: 'select[name="foreign_currency"]',
            paypal_txn: 'input[name="paypal_txn"]',
        };

        Object.keys(map).forEach(function (key) {
            if (!parsed[key]) {
                return;
            }
            var el = scope.querySelector(map[key]);
            if (!el) {
                return;
            }
            el.value = parsed[key];
            // Fire input/change so any listeners see the update.
            try {
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (e) {
                // IE-safe no-op; modern browsers only on this form.
            }
        });
    }

    /**
     * Wire paste UI on the claim page.
     *
     * @param {object} opts
     * @param {string} [opts.formSelector]
     * @param {string} [opts.textareaSelector]
     * @param {string} [opts.buttonSelector]
     * @param {string} [opts.statusSelector]
     */
    function bindForm(opts) {
        opts = opts || {};
        var form = document.querySelector(opts.formSelector || 'form[action*="paypal"]')
            || document.querySelector('#paypal-claim-form')
            || document.querySelector('form');
        var ta = document.querySelector(opts.textareaSelector || '#paypal-paste-input');
        var btn = document.querySelector(opts.buttonSelector || '#paypal-paste-parse');
        var status = document.querySelector(opts.statusSelector || '#paypal-paste-status');

        if (!ta || !btn) {
            return;
        }

        function run() {
            var parsed = parse(ta.value);
            if (form) {
                applyToForm(form, parsed);
            }
            var msg = statusMessage(parsed);
            if (status) {
                status.textContent = msg.text;
                status.dataset.kind = msg.kind;
                status.classList.remove('text-gray-500', 'text-amber-800', 'text-green-800', 'text-red-700');
                if (msg.kind === 'ok') {
                    status.classList.add('text-green-800');
                } else if (msg.kind === 'partial') {
                    status.classList.add('text-amber-800');
                } else {
                    status.classList.add('text-red-700');
                }
            }
        }

        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            run();
        });

        // Debounced parse on paste only (not every keystroke — less surprising).
        ta.addEventListener('paste', function () {
            setTimeout(run, 0);
        });
    }

    // Auto-bind when DOM ready in the browser.
    if (typeof document !== 'undefined' && document.addEventListener) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                if (document.getElementById('paypal-paste-input')) {
                    bindForm();
                }
            });
        } else if (document.getElementById('paypal-paste-input')) {
            bindForm();
        }
    }

    return {
        parse: parse,
        applyToForm: applyToForm,
        bindForm: bindForm,
        statusMessage: statusMessage,
        normalizeAmount: normalizeAmount,
        normalizeCurrency: normalizeCurrency,
    };
});
