#!/usr/bin/env python3
"""Build an n8n "tail repair" workflow from a failed execution.

Runbook: docs/RUNBOOK_N8N_RECORDING_STALL.md §9 (Systema-Sanscriticum).
Pattern: when a pipeline dies mid-flow and a FULL retry would duplicate
side effects (uploads), rebuild only the tail: manualTrigger -> Code stub
nodes named after the upstream nodes (they replay the recorded items so
$('X') expressions in the tail resolve) -> the real tail nodes verbatim
(credentials resolve on the same instance), with retryOnFail enabled on
the failed node + its LLM sub-node.

Usage (on the n8n host):
    python3 n8n_tail_repair_builder.py --exec-id 2880 \
        --out /data/repair.json [--workflow-id 1EIqqNzMl5NNIxST]

Then:
    docker exec n8n-n8n-1 n8n import:workflow --input=/data/repair.json
    # the JSON carries an explicit "id" (import fails without it)
    docker exec -e N8N_RUNNERS_BROKER_PORT=5699 n8n-n8n-1 \
        n8n execute --id <that id>
Notes:
  - Wait nodes resume via the MAIN n8n instance (waitTill in DB); the CLI
    process may exit before the run completes.
  - Delete the repair workflow afterwards: DELETE /api/v1/workflows/{id}
    (needs workflow:delete scope) or leave it inactive, renamed DONE-....
"""
import argparse, json, sqlite3, zlib, re

def decode_execution(con, exec_id):
    row = con.execute("SELECT data FROM execution_data WHERE executionId=?", (exec_id,)).fetchone()
    if not row:
        raise SystemExit(f'no execution_data for {exec_id}')
    d = row[0]
    if isinstance(d, str):
        d = d.encode('utf-8', 'surrogateescape')
    try:
        raw = zlib.decompress(d)
    except Exception:
        raw = d
    arr = json.loads(raw)
    memo = {}
    def res(i):
        if i in memo:
            return memo[i]
        memo[i] = None
        memo[i] = build(arr[i])
        return memo[i]
    def build(v):
        if isinstance(v, dict):
            return {k: ref(x) for k, x in v.items()}
        if isinstance(v, list):
            return [ref(x) for x in v]
        return v
    def ref(x):
        if isinstance(x, str) and x.isdigit():
            return res(int(x))
        if isinstance(x, (dict, list)):
            return build(x)
        return x
    return res(0).get('resultData', {})

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--exec-id', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--workflow-id', default=None)
    ap.add_argument('--db', default='/opt/n8n/storage/database.sqlite')
    a = ap.parse_args()

    con = sqlite3.connect(a.db)
    if a.workflow_id:
        wf_id = a.workflow_id
    else:
        wf_id = con.execute("SELECT workflowId FROM execution_entity WHERE id=?",
                            (a.exec_id,)).fetchone()[0]
    name, nodes_json, conn_json = con.execute(
        "SELECT name, nodes, connections FROM workflow_entity WHERE id=?", (wf_id,)).fetchone()
    nodes = json.loads(nodes_json)
    conn = json.loads(conn_json)
    by_name = {n['name']: n for n in nodes}

    rd = decode_execution(con, a.exec_id)
    run = rd.get('runData', {}) or {}

    # failed node = the one with an error (prefer lastNodeExecuted)
    tail_root = rd.get('lastNodeExecuted')
    if not any((runs[-1].get('error')) for runs in run.values()):
        raise SystemExit('no failed node found in runData — nothing to repair?')

    # tail = BFS from failed node over main edges + ai_* sub-nodes
    tail = {tail_root}
    queue = [tail_root]
    while queue:
        cur = queue.pop(0)
        for ctype, branches in (conn.get(cur) or {}).items():
            if not ctype.startswith('ai_'):
                for br in branches:
                    for lnk in (br or []):
                        t = lnk.get('node')
                        if t and t not in tail:
                            tail.add(t)
                            queue.append(t)
    ai_subs = {}
    for src, cmap in conn.items():
        for ctype, branches in cmap.items():
            if ctype.startswith('ai_'):
                for br in branches:
                    for lnk in (br or []):
                        if lnk.get('node') in tail:
                            ai_subs[src] = (lnk.get('node'), ctype)
    tail.update(ai_subs)

    # upstream nodes referenced by tail expressions -> stubs
    referenced = set()
    for name in tail:
        blob = json.dumps(by_name[name].get('parameters', {}), ensure_ascii=False)
        for m in re.finditer(r"\$\(\s*[\"']([^\"']+)[\"']\s*\)", blob):
            if m.group(1) not in tail:
                referenced.add(m.group(1))
    missing = [r for r in referenced if r not in run]
    if missing:
        raise SystemExit(f'referenced nodes have no runData: {missing}')

    # feeder of the failed node must be the LAST stub (carries prompt fields)
    feeder = None
    for src, cmap in conn.items():
        for ctype, branches in cmap.items():
            if ctype == 'main':
                for br in branches:
                    for lnk in (br or []):
                        if lnk.get('node') == tail_root:
                            feeder = src
    if feeder and feeder not in tail and feeder not in referenced:
        referenced.add(feeder)

    def last_items(name):
        runs = run.get(name) or []
        best = max(runs, key=lambda r: r.get('executionIndex', 0))
        items = []
        for br in (best.get('data') or {}).get('main') or []:
            items.extend(it for it in (br or []))
        return items

    stub_order = sorted(referenced, key=lambda n: (run.get(n, [{}])[-1].get('executionIndex', 0) if run.get(n) else 0))
    if feeder and feeder in referenced:
        stub_order.remove(feeder)
        stub_order.append(feeder)

    def clean(n):
        c = {k: n[k] for k in ('name', 'type', 'typeVersion', 'position', 'parameters') if k in n}
        for k in ('credentials', 'webhookId', 'onError'):
            if k in n:
                c[k] = n[k]
        return c

    def is_llm(n):
        return '.lmChat' in n['type'] or 'langchain.agent' in n['type']

    rnodes = [{'parameters': {}, 'name': 'REPAIR TRIGGER', 'type': 'n8n-nodes-base.manualTrigger',
               'typeVersion': 1, 'position': [-560, 300]}]
    rconn = {}
    def link(prev, tgt):
        rconn.setdefault(prev, {}).setdefault('main', [[]])[0].append(
            {'node': tgt, 'type': 'main', 'index': 0})

    pos = [-400, 300]
    prev = 'REPAIR TRIGGER'
    for s in stub_order:
        items = last_items(s)
        code = 'const out = ' + json.dumps(items, ensure_ascii=False) + ';\nreturn out;'
        rnodes.append({'parameters': {'jsCode': code}, 'name': s,
                       'type': 'n8n-nodes-base.code', 'typeVersion': 2,
                       'position': [pos[0], pos[1]]})
        pos = [pos[0] + 220, pos[1]]
        link(prev, s)
        prev = s

    for name in sorted(tail):
        n = clean(by_name[name])
        if name == tail_root:
            n.update({'retryOnFail': True, 'maxTries': 2, 'waitBetweenTries': 60000})
        if is_llm(by_name[name]):
            n.update({'retryOnFail': True, 'maxTries': 3, 'waitBetweenTries': 60000})
        rnodes.append(n)
    link(prev, tail_root)

    for src in list(tail):
        for ctype, branches in (conn.get(src) or {}).items():
            if ctype == 'main':
                for i, br in enumerate(branches):
                    for lnk in (br or []):
                        t = lnk.get('node')
                        if t in tail:
                            brs = rconn.setdefault(src, {}).setdefault('main', [])
                            while len(brs) <= i:
                                brs.append([])
                            brs[i].append({'node': t, 'type': 'main',
                                           'index': lnk.get('index', 0)})
            elif ctype.startswith('ai_'):
                for br in branches:
                    for lnk in (br or []):
                        t = lnk.get('node')
                        if t in tail:
                            rconn.setdefault(src, {}).setdefault(ctype, [[]])[0].append(
                                {'node': t, 'type': ctype, 'index': lnk.get('index', 0)})

    rid = 'R3p' + re.sub(r'[^A-Za-z0-9]', '', f'{tail_root}{a.exec_id}')[:13] + str(abs(hash(a.exec_id)) % 10000).zfill(4)
    wf = {'id': rid, 'name': f'REPAIR exec {a.exec_id} tail from "{tail_root}" (auto, delete after use)',
          'nodes': rnodes, 'connections': rconn, 'settings': {'executionOrder': 'v1'}}
    json.dump(wf, open(a.out, 'w', encoding='utf-8'), ensure_ascii=False)
    print(f'wrote {a.out}: {len(rnodes)} nodes, tail={len(tail)}, stubs={len(stub_order)}, failed="{tail_root}"')

if __name__ == '__main__':
    main()
