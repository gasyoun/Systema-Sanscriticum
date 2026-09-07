#!/usr/bin/env python3
# Vendored copy — DO NOT EDIT HERE (H4287, copied 07-09-2026).
# Source of truth: private gasyoun/Uprava `tools/probe_journeys.py` (H4168;
# H4287 added the /fail forced-incident pulse contract + injectable sender).
# This public-repo copy exists only so the hourly GH Actions journey-probe
# runner can execute it without checking out a private repo. Re-vendor by
# copying the current Uprava source over this file after every upstream
# change; verify with `selftest` (N2 + calendar design tests must stay green).
"""probe_journeys.py - critical-user-journey probes for platinum products (H4168).

Journeys (AGENT_FLEET_SLO_2026.md SLI-4 family, Workbook Ch 2):
  sale        dedicated test-SKU checkout walk (add-to-cart on the mock, then
              checkout render with price + payment form). NEVER touches real
              orders; on prod it is strictly read-only (GET only) and needs a
              dedicated test SKU slug (a prod DB write -> MG gate, see kit doc).
  lead_signup registration surface walk; on the fixture it submits a MARKED
              lead (name prefix PROBE_, @probe.invalid mail) and verifies the
              state delta; on prod it only GETs the form (flag-gated 404 counts
              as an honest MISS, not a crash).
  suggester   support-suggester answer journey: fixture asks a question and
              requires the marked-correct FAQ answer to come back; prod checks
              the public FAQ answer surfaces (/faq/payment, /faq/dz).

Modes:
  --fixture healthy|n2   full-depth journeys (POSTs allowed) against an
                         in-process stdlib mock of Systema. n2 reproduces the
                         N2 incident shape: catalog/course pages 200 while the
                         tariff is deactivated -> /checkout/{slug} 404s. The
                         probe MUST flag the sale journey there while the
                         access-half journeys stay green.
  --base-url URL         prod (default https://samskrte.ru): GET-only.

Results append one JSON line per run to ~/.claude/journey-probe/results.jsonl
(override with --jsonl); tools/build_sli_daily.py consumes prod rows.

Exit code: 0 if no journey HARD-failed (dash = not installed is not a fail),
1 otherwise - daily-cron ready for the MG-gated .92 install.

Stdlib only, Python >= 3.9.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import socket
import ssl
import sys
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DEFAULT_BASE_URL = "https://samskrte.ru"
DEFAULT_JSONL = os.path.join(
    os.path.expanduser("~"), ".claude", "journey-probe", "results.jsonl"
)
LEAD_MARKER_PREFIX = "PROBE_"
LEAD_MAIL_DOMAIN = "probe.invalid"
TEST_SKU_SLUG = "probe-sale-test"
CATALOG_PATH = "/online"  # prod shop.index (shop.index = Route::get('/online')); /shop 301s here
CHECKOUT_404_TEXT = "Тариф недоступен для покупки."
SALE_FORM_MARKERS = ("checkout-form", "listPrice")  # literal in checkout/show.blade.php
FAQ_PATHS = ("/faq/payment", "/faq/dz")

# --- leg 1: TLS-expiry countdown (H4196 W1) ---------------------------------
TLS_FAIL_DAYS = 14   # < this -> hard FAIL
TLS_WARN_DAYS = 21   # < this (and >= FAIL) -> WARN-marked PASS

# --- leg 2: editorial-calendar render journey (H4196 W2) --------------------
# markers discovered from ONE live read-only GET of https://samskrte.ru/calendary
# on 06-09-2026 (10 card entries, title "Восточные календари"); never asserts
# on prose copy, only on the repeated card wrapper class + dated-entry shape.
CALENDAR_PATH = "/calendary"
CALENDAR_CARD_MARKER = "group block h-full relative transition-transform"
MIN_CALENDAR_CARDS = 3
CALENDAR_DATE_RE = re.compile(
    r"[0-9]{1,2}\s+(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|"
    r"октябр|ноябр|декабр)",
    re.UNICODE,
)

# --- leg 3: probe-lane deadman heartbeat (H4196 W3) -------------------------
# Same degrade-and-log idiom as tools/drain_heartbeat.py, reimplemented inline
# so the deployed single-file Mac copy (~/.claude/journey-probe/probe_journeys.py)
# needs no sibling import. Real cross-machine wiring (a marker the .91 deadman
# roster can see) is the box-step residual — see the handoff.
HEARTBEAT_ENV_VAR = "PROBE_JOURNEYS_HEARTBEAT_URLS"
DEFAULT_HEARTBEAT_JOURNAL = os.path.join(
    os.path.expanduser("~"), ".claude", "journey-probe", "heartbeat.jsonl"
)


class Check:
    def __init__(self, name, ok, detail="", warn=False):
        self.name = name
        self.ok = ok  # True / False / None (None = dash: not installed)
        self.detail = detail
        self.warn = warn  # soft column: True on a WARN-marked PASS (H4196 W1)

    def as_dict(self):
        d = {"name": self.name, "ok": self.ok, "detail": self.detail}
        if self.warn:
            d["warn"] = True
        return d


class JourneyResult:
    def __init__(self, name, checks):
        self.name = name
        self.checks = checks

    @property
    def ok(self):
        oks = [c.ok for c in self.checks]
        if any(o is False for o in oks):
            return False
        if all(o is None for o in oks):
            return None
        return True

    def as_dict(self):
        return {"journey": self.name, "ok": self.ok, "checks": [c.as_dict() for c in self.checks]}


class _MockState:
    def __init__(self, tariff_active=True, calendar_cards=3):
        self.tariff_active = tariff_active
        self.calendar_cards = calendar_cards
        self.cart = []
        self.orders = []
        self.leads = []
        self.emails_sent = 0
        self.requests = []
        self.asks = []


def _mock_html(title, body):
    return (
        "<!doctype html><html><head><title>%s</title></head><body>%s</body></html>"
        % (title, body)
    ).encode("utf-8")


def make_mock_handler(state):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def _send(self, code, body, ctype="text/html; charset=utf-8"):
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _record(self, method):
            state.requests.append((method, self.path))

        def do_GET(self):
            self._record("GET")
            path = urllib.parse.urlparse(self.path).path
            if path == "/__state":
                payload = json.dumps(
                    {
                        "tariff_active": state.tariff_active,
                        "cart": state.cart,
                        "orders": state.orders,
                        "leads": state.leads,
                        "emails_sent": state.emails_sent,
                        "requests": state.requests,
                        "asks": state.asks,
                    }
                ).encode("utf-8")
                return self._send(200, payload, "application/json")
            if path in ("/catalog", "/online", "/shop"):
                link = '<a href="/checkout/%s">%s</a>' % (TEST_SKU_SLUG, TEST_SKU_SLUG)
                return self._send(200, _mock_html("catalog", link))
            if path.startswith("/checkout/"):
                if state.tariff_active:
                    body = (
                        '<form action="/payment/create" method="POST" id="checkout-form">'
                        "<button>pay</button></form>"
                        '<script>const listPrice = 4900;</script>'
                    )
                    return self._send(200, _mock_html("checkout", body))
                return self._send(404, _mock_html("checkout", CHECKOUT_404_TEXT))
            if path == "/register":
                return self._send(
                    200,
                    _mock_html(
                        "register",
                        '<form method="POST" action="/register">'
                        '<input name="csrf" value="token123">'
                        '<input name="email"><button>ok</button></form>',
                    ),
                )
            if path in FAQ_PATHS:
                return self._send(200, _mock_html("faq", "Ответ на частый вопрос " + path))
            if path == CALENDAR_PATH:
                cards = "".join(
                    '<div class="%s"><span>%d %s</span></div>'
                    % (CALENDAR_CARD_MARKER, 10 + i, "сентября")
                    for i in range(state.calendar_cards)
                )
                return self._send(200, _mock_html("calendary", cards or "<p>empty</p>"))
            return self._send(404, _mock_html("missing", "nope"))

        def do_POST(self):
            self._record("POST")
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length).decode("utf-8")
            form = urllib.parse.parse_qs(raw)
            path = urllib.parse.urlparse(self.path).path
            if path == "/cart/add":
                slug = form.get("slug", [""])[0]
                state.cart.append(slug)
                return self._send(200, _mock_html("cart", "added " + slug))
            if path == "/register":
                email = form.get("email", [""])[0]
                state.leads.append({"email": email, "name": form.get("name", [""])[0]})
                return self._send(200, _mock_html("register", "lead saved"))
            if path == "/support/ask":
                q = form.get("q", [""])[0]
                state.asks.append(q)
                if "оплат" in q or "payment" in q:
                    return self._send(
                        200,
                        json.dumps({"answer_id": "faq-payment", "text": "оплата картой"}).encode(
                            "utf-8"
                        ),
                        "application/json",
                    )
                return self._send(
                    200,
                    json.dumps({"answer_id": None, "text": ""}).encode("utf-8"),
                    "application/json",
                )
            return self._send(404, _mock_html("missing", "nope"))

    return Handler


class MockServer:
    """In-process Systema stand-in; healthy and n2 (deactivated) profiles."""

    def __init__(self, tariff_active=True, calendar_cards=3):
        self.state = _MockState(tariff_active=tariff_active, calendar_cards=calendar_cards)
        self._server = ThreadingHTTPServer(("127.0.0.1", 0), make_mock_handler(self.state))
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)

    @property
    def base_url(self):
        return "http://127.0.0.1:%d" % self._server.server_address[1]

    def __enter__(self):
        self._thread.start()
        return self

    def __exit__(self, *exc):
        self._server.shutdown()
        self._server.server_close()
        return False


class Client:
    def __init__(self, base_url):
        self.base_url = base_url.rstrip("/")

    def get(self, path):
        req = urllib.request.Request(self.base_url + path, method="GET")
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as err:
            return err.code, err.read().decode("utf-8", "replace")

    def post(self, path, fields):
        data = urllib.parse.urlencode(fields).encode("utf-8")
        req = urllib.request.Request(self.base_url + path, data=data, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as err:
            return err.code, err.read().decode("utf-8", "replace")


def _state(client):
    status, body = client.get("/__state")
    if status != 200:
        raise RuntimeError("state endpoint unavailable (%s)" % status)
    return json.loads(body)


def journey_sale(client, sale_slug, mock_mode):
    """Checkout walk with independent state-delta verification on the mock."""
    checks = []
    if not sale_slug:
        return JourneyResult(
            "sale",
            [
                Check(
                    "sale_test_sku",
                    None,
                    "dash: no dedicated test SKU configured (prod DB write -> MG gate)",
                )
            ],
        )
    status, body = client.get(CATALOG_PATH)
    checks.append(
        Check("catalog_200", status == 200, "%s context (access half) HTTP %s" % (CATALOG_PATH, status))
    )
    status, body = client.get("/checkout/%s" % sale_slug)
    if status != 200:
        checks.append(
            Check(
                "checkout_buyable",
                False,
                "HTTP %s on /checkout/%s - %s (N2 shape if catalog still 200)"
                % (status, sale_slug, CHECKOUT_404_TEXT if status == 404 else "unexpected"),
            )
        )
        return JourneyResult("sale", checks)
    missing = [m for m in SALE_FORM_MARKERS if m not in body]
    checks.append(
        Check(
            "checkout_buyable",
            not missing,
            "payment form markers absent: %s" % missing if missing else "form + pay action present",
        )
    )
    if mock_mode:
        add_status, _ = client.post("/cart/add", {"slug": sale_slug})
        st = _state(client)
        delta_ok = add_status == 200 and sale_slug in st["cart"]
        checks.append(
            Check(
                "state_delta_cart",
                delta_ok,
                "add-to-cart HTTP %s, cart holds test SKU: %s (independent /__state read)"
                % (add_status, delta_ok),
            )
        )
        checks.append(
            Check(
                "never_real_orders",
                not st["orders"],
                "orders created: %d (must stay 0)" % len(st["orders"]),
            )
        )
    return JourneyResult("sale", checks)


def journey_lead_signup(client, mock_mode):
    token = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    marker = LEAD_MARKER_PREFIX + token
    checks = []
    status, body = client.get("/register")
    if status != 200:
        checks.append(
            Check(
                "register_surface",
                False,
                "HTTP %s on /register - journey unavailable (computed MISS, not a dash; "
                "404 = flag-gated off, the N2 lesson: deactivation is a real zero)" % status,
            )
        )
        return JourneyResult("lead_signup", checks)
    checks.append(
        Check(
            "register_surface",
            "csrf" in body.lower() or "<form" in body.lower(),
            "form present HTTP %s" % status,
        )
    )
    if mock_mode:
        status, _ = client.post(
            "/register", {"email": marker + "@" + LEAD_MAIL_DOMAIN, "name": marker, "csrf": "t"}
        )
        st = _state(client)
        marked = [l for l in st["leads"] if l["email"].startswith(LEAD_MARKER_PREFIX)]
        checks.append(
            Check(
                "state_delta_lead",
                status == 200 and bool(marked),
                "marked leads in state: %d (submit HTTP %s)" % (len(marked), status),
            )
        )
        checks.append(
            Check(
                "never_real_emails",
                st["emails_sent"] == 0,
                "emails sent: %d (must stay 0; probe mail is @probe.invalid)"
                % st["emails_sent"],
            )
        )
    else:
        checks.append(
            Check(
                "lead_submit",
                None,
                "dash: prod lead write is MG-gated (marked-lead cron install)",
            )
        )
    return JourneyResult("lead_signup", checks)


def journey_suggester(client, mock_mode):
    checks = []
    for path in FAQ_PATHS:
        status, body = client.get(path)
        checks.append(
            Check(
                "faq_surface %s" % path,
                status == 200 and len(body.strip()) > 50,
                "HTTP %s, %d bytes" % (status, len(body)),
            )
        )
    if mock_mode:
        status, body = client.post("/support/ask", {"q": "как оплатить payment курс?"})
        try:
            answer = json.loads(body)
        except ValueError:
            answer = {}
        checks.append(
            Check(
                "suggester_answer",
                status == 200 and answer.get("answer_id") == "faq-payment",
                "question -> answer_id=%r (expected faq-payment)" % answer.get("answer_id"),
            )
        )
    return JourneyResult("suggester", checks)


def journey_calendar(client):
    """Editorial-calendar render walk (H4196 W2): content half, not just access."""
    checks = []
    status, body = client.get(CALENDAR_PATH)
    if status != 200:
        checks.append(
            Check(
                "calendar_200",
                False,
                "HTTP %s on %s (N2 shape if catalog is still 200 elsewhere)"
                % (status, CALENDAR_PATH),
            )
        )
        return JourneyResult("calendar", checks)
    card_count = body.count(CALENDAR_CARD_MARKER)
    checks.append(
        Check(
            "calendar_cards_present",
            card_count >= MIN_CALENDAR_CARDS,
            "%d card marker(s) found (need >= %d) — empty/stale feed regression check"
            % (card_count, MIN_CALENDAR_CARDS),
        )
    )
    dated = CALENDAR_DATE_RE.findall(body)
    checks.append(
        Check(
            "calendar_dated_entries",
            len(dated) >= 1,
            "%d dated entr(y/ies) found" % len(dated),
        )
    )
    return JourneyResult("calendar", checks)


def get_cert_notafter(host, port=443, timeout=10.0, fetcher=None):
    """Return the cert's notAfter as a UTC datetime. ``fetcher(host, port, timeout)``
    returns the raw notAfter string for injection (selftest never opens a real
    socket)."""
    if fetcher is not None:
        raw = fetcher(host, port, timeout)
    else:
        ctx = ssl.create_default_context()
        with socket.create_connection((host, port), timeout=timeout) as sock:
            with ctx.wrap_socket(sock, server_hostname=host) as ssock:
                cert = ssock.getpeercert()
        raw = cert["notAfter"]
    return dt.datetime.strptime(raw, "%b %d %H:%M:%S %Y %Z").replace(tzinfo=dt.timezone.utc)


def journey_tls_expiry(host, port=443, fetcher=None, now=None):
    """TLS-expiry countdown (H4196 W1): FAIL < 14d, WARN-marked PASS 14-21d."""
    now = now or dt.datetime.now(dt.timezone.utc)
    checks = []
    try:
        expires = get_cert_notafter(host, port, fetcher=fetcher)
    except Exception as exc:  # noqa: BLE001 — any inspect failure is a hard FAIL
        checks.append(Check("tls_cert_readable", False, "cert inspect failed: %s: %s" % (type(exc).__name__, exc)))
        return JourneyResult("tls_expiry", checks)
    days_left = (expires - now).total_seconds() / 86400.0
    ok = days_left >= TLS_FAIL_DAYS
    warn = TLS_FAIL_DAYS <= days_left < TLS_WARN_DAYS
    check = Check(
        "tls_expiry_days",
        ok,
        "%s%.1f day(s) left, cert %s expires %s"
        % ("WARN " if warn else "", days_left, host, expires.isoformat()),
    )
    check.warn = warn
    checks.append(check)
    return JourneyResult("tls_expiry", checks)


def run_journeys(base_url, mode, sale_slug):
    client = Client(base_url)
    mock_mode = mode.startswith("fixture")
    return [
        journey_sale(client, sale_slug, mock_mode),
        journey_lead_signup(client, mock_mode),
        journey_suggester(client, mock_mode),
        journey_calendar(client),
    ]


def pulse_heartbeat(reason, ok, jsonl_path=None, urls=None, sender=None):
    """Degrade-and-log liveness pulse (probe-lane deadman, H4196 W3) — mirrors
    tools/drain_heartbeat.py's idiom inline so the deployed single-file Mac
    copy needs no sibling import. Never raises.

    H4287: ok=False GETs <url>/fail (the Better Stack / healthchecks.io forced-
    incident contract — the H4245-proven path to MG's phone) instead of waiting
    out the silence window; ok=True GETs the plain pulse URL. `sender` is
    injectable for tests."""
    record = {
        "ts": dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat(),
        "reason": reason,
        "ok": ok,
    }
    raw = os.environ.get(HEARTBEAT_ENV_VAR, "").strip()
    targets = list(urls) if urls else []
    if not targets and raw:
        for sep in (",", ";", "\n"):
            raw = raw.replace(sep, "\n")
        targets = [p.strip() for p in raw.split("\n") if p.strip()]
    record["delivered"] = []
    record["misses"] = []
    if not targets:
        record["misses"].append({"error": "no %s configured (local journal only)" % HEARTBEAT_ENV_VAR})
    _send = sender or (lambda u: urllib.request.urlopen(u, timeout=10).status)
    for url in targets:
        hit = url if ok else url.rstrip("/") + "/fail"
        try:
            code = _send(hit)
            record["delivered"].append({"code": code, "fail": not ok})
        except Exception as exc:  # noqa: BLE001 — monitoring must not take the probe down
            record["misses"].append({"error": "%s: %s" % (type(exc).__name__, exc), "url": hit})
    path = jsonl_path or DEFAULT_HEARTBEAT_JOURNAL
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(record, ensure_ascii=False) + "\n")
    except Exception as exc:  # noqa: BLE001 — journaling must not raise either
        sys.stderr.write("heartbeat journal unavailable: %s\n" % exc)
    return record


def record_run(jsonl_path, mode, base_url, sale_slug, results):
    row = {
        "ts": dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat(),
        "mode": mode,
        "base_url": base_url,
        "sale_slug": sale_slug,
        "journeys": [r.as_dict() for r in results],
    }
    os.makedirs(os.path.dirname(jsonl_path), exist_ok=True)
    with open(jsonl_path, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(row, ensure_ascii=False) + "\n")
    return row


def print_run(row):
    print("mode=%s base=%s ts=%s" % (row["mode"], row["base_url"], row["ts"]))
    for j in row["journeys"]:
        mark = {True: "PASS", False: "FAIL", None: "DASH"}[j["ok"]]
        print("  %-12s %s" % (j["journey"], mark))
        for c in j["checks"]:
            tag = {True: "ok", False: "FAIL", None: "-"}[c["ok"]]
            if c.get("warn"):
                tag = "WARN"
            print("    %-4s %-22s %s" % (tag, c["name"], c["detail"]))


def cmd_run(args):
    if args.fixture:
        mode = "fixture-" + args.fixture
        with MockServer(tariff_active=args.fixture == "healthy") as mock:
            results = run_journeys(mock.base_url, mode, TEST_SKU_SLUG)
    else:
        mode = "prod"
        results = run_journeys(args.base_url, mode, args.sale_slug)
        host = urllib.parse.urlparse(args.base_url).hostname
        if host:
            results.append(journey_tls_expiry(host))
    row = record_run(args.jsonl, mode, args.fixture and "mock://" or args.base_url, args.sale_slug or None, results)
    print_run(row)
    hard_fail = any(r.ok is False for r in results)
    pulse_heartbeat("probe-journeys-run", ok=not hard_fail, jsonl_path=args.heartbeat_jsonl)
    return 1 if hard_fail else 0


def _fake_tls_fetcher(days_left):
    expires = dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=days_left)
    def fetcher(_host, _port, _timeout):
        return expires.strftime("%b %d %H:%M:%S %Y GMT")
    return fetcher


def cmd_selftest(args):
    """The N2 design test: n2 fixture must FAIL sale while pages stay 200.
    Extended (H4196) with the calendar-empty design test and TLS-expiry
    threshold checks via an injected fetcher (never opens a real socket)."""
    overall = 0
    with MockServer(tariff_active=True) as mock:
        results = run_journeys(mock.base_url, "fixture-healthy", TEST_SKU_SLUG)
        row = record_run(args.jsonl, "fixture-healthy", mock.base_url, TEST_SKU_SLUG, results)
        print_run(row)
        st = mock.state
        print(
            "state-delta: cart=%s orders=%d leads_marked=%d emails=%d"
            % (
                st.cart,
                len(st.orders),
                sum(1 for l in st.leads if l["email"].startswith(LEAD_MARKER_PREFIX)),
                st.emails_sent,
            )
        )
        if st.cart != [TEST_SKU_SLUG] or st.orders or st.emails_sent:
            overall = 1
        calendar = [r for r in results if r.name == "calendar"][0]
        if calendar.ok is not True:
            print("calendar (healthy fixture): expected PASS, got %r" % calendar.ok)
            overall = 1
    with MockServer(tariff_active=False) as mock:
        results = run_journeys(mock.base_url, "fixture-n2", TEST_SKU_SLUG)
        row = record_run(args.jsonl, "fixture-n2", mock.base_url, TEST_SKU_SLUG, results)
        print_run(row)
        sale = [r for r in results if r.name == "sale"][0]
        access = [r for r in results if r.name != "sale"]
        n2_reproduced = sale.ok is False and all(r.ok for r in access)
        print("N2 design test: %s" % ("REPRODUCED (sale FAIL while access green)" if n2_reproduced else "NOT REPRODUCED"))
        if not n2_reproduced:
            overall = 1

    with MockServer(calendar_cards=0) as mock:
        results = run_journeys(mock.base_url, "fixture-calendar-empty", TEST_SKU_SLUG)
        row = record_run(args.jsonl, "fixture-calendar-empty", mock.base_url, TEST_SKU_SLUG, results)
        print_run(row)
        calendar = [r for r in results if r.name == "calendar"][0]
        calendar_reproduced = calendar.ok is False
        print(
            "calendar-empty design test: %s"
            % ("REPRODUCED (calendar FAIL on empty feed)" if calendar_reproduced else "NOT REPRODUCED")
        )
        if not calendar_reproduced:
            overall = 1

    tls_cases = [
        ("fail", 5, False),
        ("warn", 18, True),
        ("clean", 60, True),
    ]
    for label, days_left, expect_ok in tls_cases:
        result = journey_tls_expiry("example.invalid", fetcher=_fake_tls_fetcher(days_left))
        check = result.checks[0]
        expect_warn = label == "warn"
        ok_match = result.ok is expect_ok and check.warn is expect_warn
        print(
            "tls_expiry[%s]: ok=%s warn=%s detail=%s -> %s"
            % (label, result.ok, check.warn, check.detail, "OK" if ok_match else "MISMATCH")
        )
        if not ok_match:
            overall = 1
    unreachable = journey_tls_expiry(
        "example.invalid",
        fetcher=lambda _host, _port, _timeout: (_ for _ in ()).throw(OSError("unreachable (selftest)")),
    )
    if unreachable.ok is not False:
        print("tls_expiry[unreachable]: expected FAIL, got %r" % unreachable.ok)
        overall = 1
    else:
        print("tls_expiry[unreachable]: OK (cert-inspect failure -> FAIL)")

    hb = pulse_heartbeat("selftest", ok=(overall == 0), jsonl_path=args.jsonl + ".heartbeat")
    print(
        "heartbeat: delivered=%d misses=%d journal=%s.heartbeat"
        % (len(hb["delivered"]), len(hb["misses"]), args.jsonl)
    )
    return overall


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_run = sub.add_parser("run", help="one probe run (fixture or prod)")
    p_run.add_argument("--fixture", choices=("healthy", "n2"), default=None)
    p_run.add_argument("--base-url", default=DEFAULT_BASE_URL)
    p_run.add_argument("--sale-slug", default=None, help="dedicated test SKU slug (prod)")
    p_run.add_argument("--jsonl", default=DEFAULT_JSONL)
    p_run.add_argument("--heartbeat-jsonl", default=DEFAULT_HEARTBEAT_JOURNAL)
    p_run.set_defaults(func=cmd_run)

    p_self = sub.add_parser("selftest", help="healthy + n2 fixture evidence run")
    p_self.add_argument("--jsonl", default=DEFAULT_JSONL)
    p_self.set_defaults(func=cmd_selftest)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
