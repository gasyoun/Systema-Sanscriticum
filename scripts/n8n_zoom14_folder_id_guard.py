r"""issue #4179 — гард «Folder id есть?» на ZOOM 1.4: пустой/полупустой drive_folder_id
ронял поиск обложки Drive-404 ДО гарда «Обложка найдена?».

Runs ON the n8n box (.91). Idempotent: re-run verifies the applied shape and no-ops.

Класс 14-11-2026 (exec 15242, урок 64 «Грамматика санскрита с нуля №64», meeting 84208866339):
Settings-строка найдена по meeting_id, но drive_folder_id пуст → queryString строился как
`name = '2026-11-14.jpg' and trashed = false and '' in parents` → Drive 404
«File not found: .» на Search files and folders. Прогон умер ДО «Обложка найдена?»,
штатная ветка «Обложки нет — пропуск» (H4513-топология) не сработала, хвост
(Rutube → плейлист → субтитры → урок в админке → TG) не доехал.

FIX (graph change):
    Normalize YT -> Folder id есть? (IF v2.2, string notEmpty)
        | true  -> Search files and folders   (как раньше)
        \ false -> Обложки нет — пропуск      (та же skip-нода H4513 -> ЗАГРУЗКА НА РУТУБ)

Левая часть условия: `(($('Get row(s) in sheet').first().json.drive_folder_id || '') + '').trim()`
- `.first()` вместо `.item` из рецепта @DO: прогон однопоточен (одна Settings-строка на
  exec), `.first()` не зависит от paired-item цепочки через UPLOAD-хвост;
- `|| ''` + строковая коэрция + `.trim()`: null / undefined / FALSE-ячейка / пробельная
  строка — все считаются пустыми (класс «полупустых строк», а не строго пустых).

Discipline §7.3 RUNBOOK_N8N_RECORDING_STALL: бэкап JSON перед PUT
(/root/wf_backup_pre_folderguard_<UTC>.json), БЕЗ деактивации live-воркфлоу,
точечный PUT через /api/v1 (ключ oxalpha-ops из sqlite). Verify failed →
автоматический restore pre-состояния и exit 1.
"""
import json
import subprocess
import sys
import urllib.request
from datetime import datetime, timezone

sys.stdout.reconfigure(encoding='utf-8')

WF = '1EIqqNzMl5NNIxST'
BASE = 'http://127.0.0.1:5678/api/v1'

GUARD = 'Folder id есть?'
UPSTREAM = 'Normalize YT'
SEARCH = 'Search files and folders'
SKIP = 'Обложки нет — пропуск'
SHEET = 'Get row(s) in sheet'
COVER_IF = 'Обложка найдена?'
RUTUBE = 'ЗАГРУЗКА НА РУТУБ'

LEFT = "={{ (($('Get row(s) in sheet').first().json.drive_folder_id || '') + '').trim() }}"


def api_key() -> str:
    out = subprocess.run(
        ['sqlite3', '/opt/n8n/storage/database.sqlite',
         "SELECT apiKey FROM user_api_keys WHERE label='oxalpha-ops';"],
        capture_output=True, text=True, encoding='utf-8', check=True)
    return out.stdout.strip()


def req(method, path, key, body=None):
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


def out_targets(rc, src, out=0):
    try:
        return [t['node'] for t in rc[src]['main'][out]]
    except (KeyError, IndexError, TypeError):
        return []


def verify(wf):
    rc = wf['connections']
    by = {n['name']: n for n in wf['nodes']}
    errs = []
    if GUARD not in by:
        errs.append(f'node {GUARD!r} missing')
    else:
        g = by[GUARD]
        if g.get('type') != 'n8n-nodes-base.if' or g.get('typeVersion') != 2.2:
            errs.append(f'{GUARD!r}: type/typeVersion drifted')
        conds = g.get('parameters', {}).get('conditions', {}).get('conditions') or [{}]
        if conds[0].get('leftValue', '') != LEFT:
            errs.append(f'{GUARD!r}: leftValue drifted: {conds[0].get("leftValue")!r}')
    if out_targets(rc, UPSTREAM, 0) != [GUARD]:
        errs.append(f'{UPSTREAM} -> {out_targets(rc, UPSTREAM, 0)} (want [{GUARD}])')
    if out_targets(rc, GUARD, 0) != [SEARCH]:
        errs.append(f'{GUARD} true -> {out_targets(rc, GUARD, 0)} (want [{SEARCH}])')
    if out_targets(rc, GUARD, 1) != [SKIP]:
        errs.append(f'{GUARD} false -> {out_targets(rc, GUARD, 1)} (want [{SKIP}])')
    if out_targets(rc, SEARCH, 0) != [COVER_IF]:
        errs.append(f'{SEARCH} -> {out_targets(rc, SEARCH, 0)} (want [{COVER_IF}])')
    if SKIP not in out_targets(rc, COVER_IF, 1):
        errs.append(f'{COVER_IF} false branch lost {SKIP!r}')
    if out_targets(rc, SKIP, 0) != [RUTUBE]:
        errs.append(f'H4513 {SKIP} -> {out_targets(rc, SKIP, 0)} (want [{RUTUBE}])')
    return errs


def main():
    key = api_key()
    _, wf = req('GET', f'/workflows/{WF}', key)
    nodes, conns = wf['nodes'], wf['connections']
    by = {n['name']: n for n in nodes}
    for required in (UPSTREAM, SEARCH, SKIP, COVER_IF, SHEET, RUTUBE):
        if required not in by:
            sys.exit(f'FATAL: expected node {required!r} not found')

    if not verify(wf):
        print('ALREADY-APPLIED: guard present, all edges verified. Nothing to do.')
        print('active:', wf.get('active'))
        return 0

    # safety: Search must not have any inbound edge other than from UPSTREAM —
    # the rewrite drops UPSTREAM->SEARCH, an unknown second feeder would be lost.
    strangers = sorted({s for s in conns
                        for br in conns[s].get('main', [])
                        for t in br if t.get('node') == SEARCH and s != UPSTREAM})
    if strangers:
        sys.exit(f'FATAL: unexpected inbound edges into {SEARCH!r} from {strangers}')

    stamp = datetime.now(timezone.utc).strftime('%d-%m-%Y-%H%M')
    backup = f'/root/wf_backup_pre_folderguard_{stamp}.json'
    with open(backup, 'w', encoding='utf-8') as f:
        json.dump(wf, f, ensure_ascii=False)
    print('backup:', backup)

    node = {
        'parameters': {
            'conditions': {
                'options': {'caseSensitive': True, 'leftValue': '',
                            'typeValidation': 'loose', 'version': 2},
                'conditions': [{
                    'id': 'folder-id-not-empty',
                    'leftValue': LEFT,
                    'rightValue': '',
                    'operator': {'type': 'string', 'operation': 'notEmpty',
                                 'singleValue': True},
                }],
                'combinator': 'and',
            },
            'options': {},
        },
        'type': 'n8n-nodes-base.if',
        'typeVersion': 2.2,
        'position': [424, 64],
        'id': 'folder-id-guard-if',
        'name': GUARD,
    }
    nodes = [n for n in nodes if n['name'] != GUARD]
    nodes.append(node)

    conns.pop(GUARD, None)
    up = conns.setdefault(UPSTREAM, {}).setdefault('main', [[]])
    up[0] = [c for c in up[0] if c.get('node') != SEARCH]
    up[0].append({'node': GUARD, 'type': 'main', 'index': 0})
    conns[GUARD] = {'main': [
        [{'node': SEARCH, 'type': 'main', 'index': 0}],
        [{'node': SKIP, 'type': 'main', 'index': 0}],
    ]}

    allowed = {'saveExecutionProgress', 'saveManualExecutions', 'saveDataErrorExecution',
               'saveDataSuccessExecution', 'executionTimeout', 'errorWorkflow',
               'timezone', 'executionOrder'}
    settings = {k: v for k, v in (wf.get('settings') or {}).items() if k in allowed}

    status, res = req('PUT', f'/workflows/{WF}', key, {
        'name': wf['name'], 'nodes': nodes, 'connections': conns, 'settings': settings})
    print('PUT status:', status, '| nodes:', len(wf['nodes']), '->', len(res['nodes']))

    errs2 = verify(res)
    if errs2:
        print('VERIFY FAILED after PUT:', *errs2, sep='\n  ')
        print('restoring pre-change state from', backup)
        _, res2 = req('PUT', f'/workflows/{WF}', key, {
            'name': wf['name'], 'nodes': wf['nodes'],
            'connections': wf['connections'], 'settings': settings})
        errs3 = verify(res2)
        print('restore verify:', 'OK' if not errs3 else errs3)
        sys.exit(1)

    for label, got in ((f'{UPSTREAM} ->', out_targets(res['connections'], UPSTREAM, 0)),
                       (f'{GUARD} true ->', out_targets(res['connections'], GUARD, 0)),
                       (f'{GUARD} false ->', out_targets(res['connections'], GUARD, 1)),
                       (f'{SEARCH} ->', out_targets(res['connections'], SEARCH, 0)),
                       (f'{SKIP} -> (H4513)', out_targets(res['connections'], SKIP, 0))):
        print(f'  {label} {got}')
    assert res.get('active'), 'workflow came back inactive — §7.3 violated; restore from backup!'
    print('active:', res.get('active'))
    print('GUARD LIVE: пустой/полупустой drive_folder_id теперь ведёт в '
          '«Обложки нет — пропуск» (skip-ветка H4513), а не в Drive-404.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
