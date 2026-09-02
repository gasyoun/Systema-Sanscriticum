r"""H3952 — stop a per-account fresh-link failure from exiting green on ZOOM 1.4.

Runs ON the n8n box (.91). Idempotent: re-running replaces the H3952 nodes in place.

WHY THIS SHAPE
--------------
H3687 already built the per-account fresh-link (registry in `Code in JavaScript1`,
switch «Аккаунт fresh-link» → «Свежая ссылка (Цыди)» / «(Гасунс)»). What it did NOT do is
make a fresh-link *fetch failure* observable. Three surfaces still exit `success`:

  1. `neverError: true` on both fresh-link nodes turns a Zoom 3301/401 into a normal output.
     `Code in JavaScript1` always emits a non-empty `download_url` (the webhook file URL,
     with `?access_token=` only when a token came), so the guard «Есть запись?» passes
     regardless and NOTHING anywhere records that the per-account fetch failed. The run is
     green, the recording lands via the signed ≤24 h URL — and the broken credential is
     discovered only days later, when a >24 h replay needs it.
  2. `TG: replay недоступен` is a terminal node: it alerts, then the execution ends
     **success** although no recording was delivered.
  3. The guard's own false branch was a dead end (rare, since download_url is ~always set,
     but it is the literal "nothing was retrieved" case).

So `neverError: true` deliberately STAYS on the two fresh-link nodes — removing it would
hard-fail runs that the signed URL can still complete, which is a regression, and the
handoff explicitly permits keeping it behind an explicit assertion. This patch adds that
assertion plus the verdict split:

  Свежая ссылка (Цыди|Гасунс|pass-through)
        -> Диагноз fresh-link            (classify, pass payload through unchanged)
             |-> Есть запись?            (main pipeline, untouched)
             \-> Деградация fresh-link?  -> TG: fresh-link деградировал   (loud, ops chat)

  Есть запись? --false--> HEAD вебхук-токен -> Вердикт fresh-link
                          -> TG: диагноз fresh-link -> Стоп: запись не получена  (THROWS)

  TG: replay недоступен -> Стоп: replay невозможен                                (THROWS)

Verdicts are split the way the handoff asks: `credential_fetch_failure` (auth/scope refusal,
or 3301 while the webhook token still HEADs alive — the recording exists, this credential
cannot see it) vs `webhook_missing` (no recording, no live token). Zoom's 3301 is genuinely
ambiguous on its own, so when there is no live token to corroborate it the verdict is
`undecidable_3301` — reported as a failure per the handoff's ambiguity policy, because a
false alarm is cheaper than a silent success.
"""
import json
import subprocess
import sys
import urllib.request

sys.stdout.reconfigure(encoding='utf-8')

WF = '1EIqqNzMl5NNIxST'
BASE = 'http://127.0.0.1:5678/api/v1'
GUARD = 'Есть запись?'
FRESH = ['Свежая ссылка (Цыди)', 'Свежая ссылка (Гасунс)',
         'Неизвестный аккаунт (pass-through)']
REPLAY_DEAD = 'TG: replay недоступен'
DOWNLOAD_FRESH = ['DOWNLOAD свежая (Цыди)', 'DOWNLOAD свежая (Гасунс)']
OPS_BOT_CRED = {'id': '7f3c2a91-4b6e-4d58-9a20-b1c7d2e3f4a5',
                'name': 'ops-пульс bot (testpodpiska12_bot)'}
OPS_CHAT = '7961639774'

N_DIAG = 'Диагноз fresh-link'
N_DEGRADED = 'Деградация fresh-link?'
N_TG_DEGRADED = 'TG: fresh-link деградировал'
N_HEAD = 'HEAD вебхук-токен'
N_VERDICT = 'Вердикт fresh-link'
N_TG = 'TG: диагноз fresh-link'
N_STOP = 'Стоп: запись не получена'
N_STOP_REPLAY = 'Стоп: replay невозможен'
H3952_NODES = [N_DIAG, N_DEGRADED, N_TG_DEGRADED, N_HEAD, N_VERDICT, N_TG,
               N_STOP, N_STOP_REPLAY]


def api_key() -> str:
    out = subprocess.run(
        ['sqlite3', '/opt/n8n/storage/database.sqlite',
         "SELECT apiKey FROM user_api_keys WHERE label='oxalpha-ops';"],
        capture_output=True, text=True, encoding='utf-8', check=True)
    return out.stdout.strip()


def req(method: str, path: str, key: str, body=None):
    # `path` is always a literal from this file, but pin it anyway: urllib honours
    # file:// and friends, so refuse anything that is not the local n8n REST base.
    url = BASE + path
    if not url.startswith('http://127.0.0.1:5678/api/v1/'):
        raise ValueError(f'refusing non-n8n URL: {url!r}')

    data = json.dumps(body).encode('utf-8') if body is not None else None
    r = urllib.request.Request(url, data=data, method=method,
                               headers={'X-N8N-API-KEY': key,
                                        'Content-Type': 'application/json',
                                        'Accept': 'application/json'})
    opener = urllib.request.build_opener(urllib.request.HTTPHandler)  # http only
    with opener.open(r, timeout=60) as resp:
        return resp.status, json.loads(resp.read().decode('utf-8'))


DIAG_JS = r"""
// H3952 — классифицируем ИСХОД per-account fresh-link и пропускаем payload дальше
// без изменений. Раньше 3301/401 от Zoom утекал через neverError:true как обычный
// выход и нигде не фиксировался: прогон зелёный, запись приезжала по подписанному
// URL (<=24 ч), а сломанный cred всплывал только на replay через несколько дней.
const src = $('Code in JavaScript1').first().json;
const item = $input.first().json || {};

const account = src.zoom_account_id || 'Unknown';
const accountKnown = account !== 'Unknown';
const code = (item.code !== undefined && item.code !== null) ? Number(item.code) : null;
const message = item.message ? String(item.message) : '';
const files = Array.isArray(item.recording_files) ? item.recording_files.length : 0;
const webhookUrlPresent = !!src.download_url;

// Отказ авторизации/скоупа — однозначный сбой credential.
const AUTH_CODES = [124, 401, 403, 1001];

let degraded = false;
let verdict = 'ok';
let human = '';

if (!accountKnown) {
  degraded = true;
  verdict = 'account_unregistered';
  human = 'Zoom-аккаунт ' + (src.zoom_account_raw_id || '—') + ' отсутствует в реестре '
        + '(Code in JavaScript1): per-account fresh-link для него не заведён. Доставка '
        + 'идёт только по подписанному URL (<=24 ч), replay>24h и DELETE недоступны.';
} else if (code !== null && AUTH_CODES.indexOf(code) !== -1) {
  degraded = true;
  verdict = 'credential_fetch_failure';
  human = 'Zoom ответил ' + code + (message ? ' «' + message + '»' : '')
        + ' на fresh-link аккаунта ' + account + ' — отказ авторизации/скоупа, '
        + 'а НЕ отсутствие записи. Чинить OAuth-cred: пока он сломан, replay>24h невозможен.';
} else if (code === 3301) {
  degraded = true;
  verdict = 'fresh_link_3301';
  human = 'Zoom вернул 3301 «запись не найдена» на fresh-link аккаунта ' + account + '. '
        + 'Либо встреча принадлежит другому аккаунту (ошибка реестра), либо записи в облаке '
        + 'уже нет. Прогон продолжится по подписанному URL, если тот жив, но replay>24h '
        + 'для этой встречи работать НЕ будет.';
} else if (code !== null) {
  degraded = true;
  verdict = 'fresh_link_error_' + code;
  human = 'Zoom ответил ' + code + (message ? ' «' + message + '»' : '')
        + ' на fresh-link аккаунта ' + account + '.';
} else if (files === 0 && accountKnown) {
  degraded = true;
  verdict = 'fresh_link_empty';
  human = 'Fresh-link аккаунта ' + account + ' ответил без ошибки, но не вернул ни одного '
        + 'файла записи.';
}

const out = Object.assign({}, item, {
  h3952_degraded: degraded,
  h3952_verdict: verdict,
  h3952_human: human,
  h3952_account: account,
  h3952_code: code,
  h3952_files: files,
  h3952_webhook_url_present: webhookUrlPresent,
  h3952_meeting_id: src.meeting_id ?? null,
  h3952_topic: src.topic ?? null,
  h3952_account_raw: src.zoom_account_raw_id ?? null,
});

return [{ json: out }];
""".strip()

DEGRADED_JS = r"""
// H3952 — пропускаем дальше только деградировавшие прогоны: пустой возврат означает,
// что TG-нода ниже не выполнится вовсе. Здоровый fresh-link => тишина.
return $input.all().filter(i => i.json && i.json.h3952_degraded === true);
""".strip()

VERDICT_JS = r"""
// H3952 — «ничего не получено»: ни fresh-link, ни подписанного URL.
// Разделяем «сбой credential/fetch» и «вебхук не пришёл» так, как требует хендофф:
// живой вебхук-токен (HEAD 2xx/3xx) доказывает, что запись ЕСТЬ в облаке, значит
// виноват cred; мёртвый или отсутствующий — класс «записи нет».
const src = $('Code in JavaScript1').first().json;
const webhookUrl = src.download_url || '';

let head = {};
try { head = $input.first().json || {}; } catch (e) {}
const status = Number(head.statusCode ?? head.status ?? 0);
const tokenAlive = webhookUrl !== '' && status >= 200 && status < 400;

let diag = {};
try { diag = $('Диагноз fresh-link').first().json || {}; } catch (e) {}
const code = (diag.h3952_code !== undefined && diag.h3952_code !== null)
  ? Number(diag.h3952_code) : null;
const account = diag.h3952_account || src.zoom_account_id || 'Unknown';
const AUTH_CODES = [124, 401, 403, 1001];

let verdict, marker, human;
if (account === 'Unknown') {
  verdict = 'account_unregistered';
  marker = 'H3952_ACCOUNT_UNREGISTERED';
  human = 'Zoom-аккаунт ' + (src.zoom_account_raw_id || '—') + ' не в реестре — '
        + 'ни fresh-link, ни replay для него не заведены.';
} else if (code !== null && AUTH_CODES.indexOf(code) !== -1) {
  verdict = 'credential_fetch_failure';
  marker = 'H3952_CREDENTIAL_FETCH_FAILURE';
  human = 'Zoom отказал по авторизации (' + code + ') аккаунту ' + account
        + '. Запись почти наверняка жива — чинить cred, доставать вручную (Play B).';
} else if (code === 3301 && tokenAlive) {
  verdict = 'credential_fetch_failure';
  marker = 'H3952_CREDENTIAL_FETCH_FAILURE';
  human = 'Zoom вернул 3301 аккаунту ' + account + ', НО вебхук-токен жив (HEAD ' + status
        + ') — запись существует, её не видит именно этот cred. Сбой credential/fetch, '
        + 'НЕ пропавший вебхук.';
} else if (code === 3301) {
  verdict = 'undecidable_3301';
  marker = 'H3952_UNDECIDABLE_3301';
  human = 'Zoom вернул 3301 аккаунту ' + account + ', живого вебхук-токена нет (HEAD '
        + (status || 'нет ответа') + '), поэтому «чужой аккаунт» и «записи не было» по логам '
        + 'НЕ различимы. Посмотреть облако Zoom этого аккаунта глазами (Play B). Помечено '
        + 'провалом намеренно: ложная тревога дешевле тихого успеха.';
} else {
  verdict = 'webhook_missing';
  marker = 'H3952_WEBHOOK_MISSING';
  human = 'Fresh-link без ошибки Zoom и без файлов, вебхук-токен мёртв или отсутствует '
        + '(HEAD ' + (status || 'нет ответа') + '). Класс «запись не пришла / не готова», '
        + 'НЕ сбой credential.';
}

return [{
  json: {
    h3952_verdict: verdict,
    h3952_marker: marker,
    h3952_human: human,
    head_status: status,
    webhook_token_alive: tokenAlive,
    zoom_code: code,
    meeting_id: src.meeting_id ?? null,
    topic: src.topic ?? null,
    zoom_account_id: account,
    zoom_account_raw_id: src.zoom_account_raw_id ?? null,
  },
}];
""".strip()

STOP_JS = r"""
// H3952 — АССЕРТ. Эта ветка раньше была тупиком: прогон завершался success, хотя урок
// не создан (FINDINGS 608). Теперь прогон ПАДАЕТ, а вердикт лежит в тексте ошибки,
// откуда его читает `recordings:gap-watch` (N8nZoomExecutionProbe::classify).
const v = $('Вердикт fresh-link').first().json;
throw new Error(
  v.h3952_marker
  + ' | verdict=' + v.h3952_verdict
  + ' | meeting=' + v.meeting_id
  + ' | account=' + v.zoom_account_id
  + ' | head=' + v.head_status
  + ' | zoom_code=' + v.zoom_code
  + ' | ' + v.h3952_human
);
""".strip()

STOP_REPLAY_JS = r"""
// H3952 — АССЕРТ. «TG: replay недоступен» был терминальным: алерт уходил, а прогон
// заканчивался success, хотя запись не доставлена. Теперь прогон падает.
let src = {};
try { src = $('Code in JavaScript1').first().json || {}; } catch (e) {}
throw new Error(
  'H3952_REPLAY_IMPOSSIBLE'
  + ' | verdict=replay_impossible'
  + ' | meeting=' + (src.meeting_id ?? '—')
  + ' | account=' + (src.zoom_account_id ?? '—')
  + ' | raw_account=' + (src.zoom_account_raw_id ?? '—')
  + ' | Подписанная ссылка истекла, аккаунт не в реестре fresh-link — свежую ссылку взять'
  + ' неоткуда. Запись доставить вручную (Play B), затем завести аккаунт в реестре.'
);
""".strip()

TG_DEGRADED_TEXT = (
    '=⚠️ <b>Fresh-link деградировал</b> — <code>{{ $json.h3952_verdict }}</code>\n\n'
    'Урок «{{ $json.h3952_topic }}» (meeting <code>{{ $json.h3952_meeting_id }}</code>, '
    'аккаунт <code>{{ $json.h3952_account }}</code> / '
    '<code>{{ $json.h3952_account_raw }}</code>)\n\n'
    '{{ $json.h3952_human }}\n\n'
    'Прогон продолжается по подписанному URL, если тот жив. Это предупреждение, а не отказ: '
    'раньше такой сбой не оставлял никакого следа (H3952).'
)

TG_VERDICT_TEXT = (
    '=🚨 <b>Запись не получена</b> — <code>{{ $json.h3952_verdict }}</code>\n\n'
    'Урок «{{ $json.topic }}» (meeting <code>{{ $json.meeting_id }}</code>, '
    'аккаунт <code>{{ $json.zoom_account_id }}</code> / '
    '<code>{{ $json.zoom_account_raw_id }}</code>)\n\n'
    '{{ $json.h3952_human }}\n\n'
    'Прогон помечен <b>error</b> (H3952): раньше он завершался «зелёным» и был '
    'неотличим от «вебхук не пришёл».'
)


def code_node(name, node_id, js, pos):
    return {'parameters': {'jsCode': js}, 'type': 'n8n-nodes-base.code',
            'typeVersion': 2, 'position': pos, 'id': node_id, 'name': name}


def tg_node(name, node_id, text, pos):
    return {
        'parameters': {'chatId': OPS_CHAT, 'text': text,
                       'additionalFields': {'appendAttribution': False,
                                            'parse_mode': 'HTML'}},
        'type': 'n8n-nodes-base.telegram', 'typeVersion': 1.2, 'position': pos,
        'id': node_id, 'name': name,
        'credentials': {'telegramApi': OPS_BOT_CRED},
        'onError': 'continueRegularOutput',
    }


def build_nodes(guard_pos, replay_pos):
    x, y = guard_pos[0], guard_pos[1]
    rx, ry = replay_pos[0], replay_pos[1]
    return [
        code_node(N_DIAG, 'h3952-diag', DIAG_JS, [x - 200, y + 200]),
        # A Code filter, not an IF: the IF v2 boolean operator evaluated a plainly-true
        # `h3952_degraded` as false (exec 2308 carried h3952_degraded:true and the alert
        # still did not fire), so the gate is expressed as code with no operator semantics
        # to get wrong. An empty return means no downstream alert, which is the gate.
        # Positioned ABOVE the guard on purpose: with executionOrder v1 n8n walks a
        # fan-out in canvas order (y, then x), not connection order, and it runs each
        # branch to completion. Below the guard this alert either queued behind the whole
        # upload pipeline or never ran at all when the pipeline branch threw
        # (observed on execs 2310-2313). Above it, the alert always fires first.
        code_node(N_DEGRADED, 'h3952-degraded-filter', DEGRADED_JS, [x - 200, y - 420]),
        tg_node(N_TG_DEGRADED, 'h3952-tg-degraded', TG_DEGRADED_TEXT, [x + 20, y - 420]),
        {
            'parameters': {
                'method': 'HEAD',
                'url': ("={{ $('Code in JavaScript1').first().json.download_url "
                        "|| 'https://api.zoom.us/v2/h3952/no-download-url' }}"),
                'options': {'response': {'response': {'neverError': True,
                                                      'fullResponse': True}}},
            },
            'type': 'n8n-nodes-base.httpRequest', 'typeVersion': 4.2,
            'position': [x + 220, y + 240], 'id': 'h3952-head-token', 'name': N_HEAD,
            'alwaysOutputData': True, 'onError': 'continueRegularOutput',
        },
        code_node(N_VERDICT, 'h3952-verdict', VERDICT_JS, [x + 440, y + 240]),
        tg_node(N_TG, 'h3952-tg-verdict', TG_VERDICT_TEXT, [x + 660, y + 240]),
        code_node(N_STOP, 'h3952-stop', STOP_JS, [x + 880, y + 240]),
        code_node(N_STOP_REPLAY, 'h3952-stop-replay', STOP_REPLAY_JS, [rx + 220, ry]),
    ]


def main():
    key = api_key()
    _, wf = req('GET', f'/workflows/{WF}', key)
    nodes = wf['nodes']
    conns = wf['connections']
    by = {n['name']: n for n in nodes}

    for required in [GUARD, REPLAY_DEAD] + FRESH + DOWNLOAD_FRESH:
        if required not in by:
            sys.exit(f'FATAL: expected node {required!r} not found')

    before = set(by)
    nodes = [n for n in nodes if n['name'] not in H3952_NODES]      # idempotent
    nodes.extend(build_nodes(by[GUARD].get('position', [0, 0]),
                             by[REPLAY_DEAD].get('position', [0, 600])))

    for n in H3952_NODES:
        conns.pop(n, None)

    # 1. every fresh-link outcome flows through the diagnosis node
    for f in FRESH:
        conns.setdefault(f, {})['main'] = [[{'node': N_DIAG, 'type': 'main', 'index': 0}]]

    # 2. diagnosis fans out: the degradation alert FIRST, then the main pipeline.
    #    Order matters — n8n walks a fan-out in connection order and runs each branch to
    #    completion, so with the pipeline first the alert either waits behind hours of
    #    upload/Wait nodes or, if that branch throws, never runs at all (observed: execs
    #    2310/2311 aborted at the assertion before the alert branch was ever reached).
    conns[N_DIAG] = {'main': [[
        {'node': N_DEGRADED, 'type': 'main', 'index': 0},
        {'node': GUARD, 'type': 'main', 'index': 0},
    ]]}
    conns[N_DEGRADED] = {'main': [[{'node': N_TG_DEGRADED, 'type': 'main', 'index': 0}]]}

    # 3. guard-false backstop: HEAD -> verdict -> alert -> throw
    conns[N_HEAD] = {'main': [[{'node': N_VERDICT, 'type': 'main', 'index': 0}]]}
    conns[N_VERDICT] = {'main': [[{'node': N_TG, 'type': 'main', 'index': 0}]]}
    conns[N_TG] = {'main': [[{'node': N_STOP, 'type': 'main', 'index': 0}]]}
    g = conns.setdefault(GUARD, {}).setdefault('main', [])
    while len(g) < 2:
        g.append([])
    g[1] = [{'node': N_HEAD, 'type': 'main', 'index': 0}]

    # 4. the replay-impossible terminal must fail, not end green
    conns[REPLAY_DEAD] = {'main': [[{'node': N_STOP_REPLAY, 'type': 'main', 'index': 0}]]}

    # 5. the >24 h replay path is where a broken per-account credential actually bites:
    #    fresh-link returned nothing, so «DOWNLOAD свежая (…)» gets an empty URL and dies
    #    with an opaque node error. Route that error into the verdict chain so the run
    #    still fails, but with `credential_fetch_failure` vs `webhook_missing` named.
    for dl in DOWNLOAD_FRESH:
        node = next(n for n in nodes if n['name'] == dl)
        node['onError'] = 'continueErrorOutput'
        conns.setdefault(dl, {})['main'] = [
            [{'node': 'Resolve YT channel', 'type': 'main', 'index': 0}],
            [{'node': N_HEAD, 'type': 'main', 'index': 0}],
        ]

    allowed = {'saveExecutionProgress', 'saveManualExecutions', 'saveDataErrorExecution',
               'saveDataSuccessExecution', 'executionTimeout', 'errorWorkflow',
               'timezone', 'executionOrder'}
    settings = {k: v for k, v in (wf.get('settings') or {}).items() if k in allowed}

    status, res = req('PUT', f'/workflows/{WF}', key, {
        'name': wf['name'], 'nodes': nodes, 'connections': conns, 'settings': settings})

    print('PUT status:', status)
    print('nodes:', len(before), '->', len(res['nodes']))
    print('added:', sorted(set(n['name'] for n in res['nodes']) - before) or '(in place)')
    rc = res['connections']
    for f in FRESH:
        print(f'  {f} ->', [t['node'] for t in rc[f]['main'][0]])
    print(f'  {N_DIAG} ->', [t['node'] for t in rc[N_DIAG]['main'][0]])
    print(f'  {GUARD} false ->', [t['node'] for t in rc[GUARD]['main'][1]])
    print(f'  {REPLAY_DEAD} ->', [t['node'] for t in rc[REPLAY_DEAD]['main'][0]])
    for dl in DOWNLOAD_FRESH:
        print(f'  {dl} error ->', [t['node'] for t in rc[dl]['main'][1]])
    print('active:', res.get('active'))


if __name__ == '__main__':
    main()
