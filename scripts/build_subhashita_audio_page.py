#!/usr/bin/env python3
"""Build the self-contained HTML comparison page (H4474, MG 14-09-2026):
all 7537 Böhtlingk *Indische Sprüche* sayings — the IS text always visible,
"our" material (audio recordings, tape RU, textbook quotes) revealed per-row
by a button. Published to gasyoun.github.io (the HTML-for-humans surface).

    python scripts/build_subhashita_audio_page.py \
        --deck resources/data/subhashita_srs_deck.json \
        --matches resources/data/subhashita_textbook_is_matches.json \
        --sentences ../SanskritGrammar/scripts/data/sentences.json \
        --sprueche ../SanskritLexicography/IndischeSprueche/data/indische_sprueche.jsonl \
        --out dist/subhashita-audio-compare.html

Inputs: the verified deck feed (audio ids, tape verses, RU), the textbook→IS
crossmatch JSON, SanskritGrammar sentences, the IS corpus itself.
"""

import argparse
import json
from pathlib import Path

AUDIO_BASE = "https://samskrte.ru/storage/srs/subhashita"


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--deck", required=True, type=Path)
    ap.add_argument("--matches", required=True, type=Path)
    ap.add_argument("--sentences", required=True, type=Path)
    ap.add_argument("--sprueche", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    args = ap.parse_args()

    feed = json.load(open(args.deck, encoding="utf-8"))
    matches = json.load(open(args.matches, encoding="utf-8"))
    sentences = {s["id"]: s for s in json.load(open(args.sentences, encoding="utf-8"))}
    sayings = {}
    for line in open(args.sprueche, encoding="utf-8"):
        if line.strip():
            r = json.loads(line)
            sayings[r["num"]] = r

    # our-material index: is_num -> {audio, tape_verse, ru, textbooks}
    ours = {}
    for c in feed["cards"]:
        if not c["is_num"]:
            continue
        o = ours.setdefault(c["is_num"], {"audio": [], "tape_verse": None, "ru": None, "books": []})
        o["audio"].append({"id": c["audio_id"], "dur": c["duration_s"], "set": c["set"], "su": c["su_num"]})
        if c["verse_deva"] and not o["tape_verse"]:
            o["tape_verse"] = c["verse_deva"]
        if c["ru"] and not o["ru"]:
            o["ru"] = c["ru"]
    for sid, m in matches.items():
        if m.get("tier") in ("EXACT", "CONTAIN", "QGRAM", "FUZZY"):
            s = sentences.get(sid)
            if not s:
                continue
            o = ours.setdefault(m["is_num"], {"audio": [], "tape_verse": None, "ru": None, "books": []})
            o["books"].append({"id": sid, "book": s["book"], "lesson": s.get("lesson", ""), "text": s["text"], "tier": m["tier"]})

    non_boet = [
        {"id": c["audio_id"], "front": c["verse_deva"], "ru": c["ru"], "set": c["set"]}
        for c in feed["cards"] if not c["is_num"]
    ]

    say_list = []
    for num in sorted(sayings):
        rec = sayings[num]
        say_list.append([num, rec["deva"], rec["iast"], rec.get("translation_de") or "", rec.get("page") or ""])
    ours_compact = {
        str(num): {
            "a": o["audio"],
            "tv": o["tape_verse"],
            "ru": o["ru"],
            "b": o["books"],
        }
        for num, o in ours.items()
    }

    n_with = len(ours_compact)
    n_audio = sum(1 for v in ours_compact.values() if v["a"])
    n_books = sum(len(v["b"]) for v in ours_compact.values())

    data_json = json.dumps({"say": say_list, "ours": ours_compact, "non": non_boet},
                           ensure_ascii=False, separators=(",", ":"))

    html = TEMPLATE.replace("__DATA__", data_json).replace("__N_WITH__", str(n_with)) \
        .replace("__N_AUDIO__", str(n_audio)).replace("__N_BOOKS__", str(n_books)) \
        .replace("__N_SAY__", str(len(say_list))).replace("__N_NON__", str(len(non_boet))) \
        .replace("__AUDIO_BASE__", AUDIO_BASE)

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(html, encoding="utf-8")
    print(f"sayings={len(say_list)} with_ours={n_with} audio={n_audio} textbook_quotes={n_books} non_boet={len(non_boet)}")
    print(f"wrote {args.out} ({args.out.stat().st_size // 1024} KB)")


TEMPLATE = r"""<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Субхашиты: Бётлингк ↔ наши материалы (H4474)</title>
<style>
  :root{--red:#E3122C;--ink:#1a1a1a;--mut:#6b7280;--line:#e5e7eb;--bg:#fafaf9;--card:#fff;--gold:#b45309}
  *{box-sizing:border-box}
  body{margin:0;font:16px/1.55 -apple-system,'Segoe UI',Roboto,sans-serif;color:var(--ink);background:var(--bg)}
  header{padding:28px 20px 18px;max-width:1060px;margin:0 auto}
  h1{font-size:26px;margin:0 0 6px}
  .sub{color:var(--mut);font-size:14px;margin:0 0 4px}
  .sub a{color:#1d4ed8;text-decoration:none}
  .controls{position:sticky;top:0;z-index:5;background:var(--bg);border-bottom:1px solid var(--line);
    padding:10px 20px;max-width:1060px;margin:0 auto;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .controls input[type=search]{flex:1;min-width:220px;padding:8px 12px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--card)}
  .controls label{font-size:14px;color:var(--mut);display:flex;gap:6px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:8px 12px;cursor:pointer}
  .controls button{padding:8px 14px;border:1px solid var(--line);border-radius:10px;background:var(--card);font-size:14px;cursor:pointer}
  #count{font-size:13px;color:var(--mut)}
  main{max-width:1060px;margin:0 auto;padding:14px 20px 60px}
  .row{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:10px 0;overflow:hidden}
  .row .is{padding:14px 16px;display:flex;gap:14px;align-items:flex-start}
  .num{flex:0 0 auto;background:var(--red);color:#fff;font-weight:700;border-radius:8px;padding:6px 9px;font-size:13px;min-width:52px;text-align:center}
  .is .body{flex:1;min-width:0}
  .deva{font-size:21px;line-height:1.6;font-family:'Noto Serif Devanagari','Noto Sans Devanagari','Nirmala UI',serif}
  .iast{color:var(--mut);font-style:italic;font-size:14px;margin-top:4px;word-break:break-word}
  .meta{color:#9ca3af;font-size:12px;margin-top:4px}
  .rowbtn{margin:0 16px 12px;padding:7px 14px;border-radius:9px;border:1px solid var(--line);
    background:#fef2f2;color:var(--red);font-weight:600;font-size:13px;cursor:pointer}
  .row.open .rowbtn{background:var(--red);color:#fff;border-color:var(--red)}
  .ours{display:none;padding:0 16px 16px}
  .row.open .ours{display:grid;grid-template-columns:1fr;gap:12px}
  @media(min-width:900px){.row.open .ours{grid-template-columns:1fr 1fr}}
  .panel{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fcfcfb}
  .panel h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
  .panel .deva{font-size:18px}
  .panel .ru{font-size:15px;color:#374151}
  .audio{width:100%;margin-top:6px}
  .aid{color:#9ca3af;font-size:11px;margin-top:4px;word-break:break-all}
  .book{border-left:3px solid var(--gold);padding:6px 10px;margin:8px 0;background:#fffbeb;border-radius:0 8px 8px 0}
  .book .bl{font-size:11px;color:var(--gold);font-weight:700}
  .book .bt{font-family:'Noto Serif Devanagari','Nirmala UI',serif;font-size:17px}
  .badge{display:inline-block;font-size:11px;border:1px solid var(--line);border-radius:6px;padding:1px 6px;color:var(--mut);margin-right:4px}
  #more{display:block;margin:18px auto;padding:10px 22px;border-radius:10px;border:1px solid var(--line);background:var(--card);font-size:14px;cursor:pointer}
  details.nonb{max-width:1060px;margin:30px auto 0;padding:0 20px}
  details.nonb summary{cursor:pointer;font-weight:700;font-size:17px;padding:10px 0}
  .nonrow{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:8px 0}
  .empty{color:var(--mut);text-align:center;padding:30px 0}
</style>
</head>
<body>
<header>
  <h1>Субхашиты: Бётлингк ↔ наши материалы</h1>
  <p class="sub">Все __N_SAY__ изречений «Indische Sprüche» О. Бётлингка (2-е изд., 1870–73; корпус <a href="https://github.com/gasyoun/SanskritLexicography/blob/master/IndischeSprueche/data/indische_sprueche.jsonl">indische_sprueche.jsonl</a>). Кнопка «наше ▸» показывает материалы H4474: аудиозаписи с Ян.Диска (права подтверждены MG 14-09-2026), текст плёнки, RU-перевод антологии и цитаты из учебников Бюлера/Кнауэра/Кочергиной.</p>
  <p class="sub">С нашим материалом: <b>__N_WITH__</b> изречений · аудио: <b>__N_AUDIO__</b> · цитат из учебников: <b>__N_BOOKS__</b> · вне Бётлингка: <b>__N_NON__</b> записей (секция внизу).</p>
</header>
<div class="controls">
  <input type="search" id="q" placeholder="поиск: номер, IAST, деванагари…">
  <label><input type="checkbox" id="only"> только с нашим</label>
  <button id="all">раскрыть все (на странице)</button>
  <span id="count"></span>
</div>
<main id="list"></main>
<button id="more">показать ещё</button>
<details class="nonb">
  <summary>Записи вне Бётлингка (__N_NON__) — источник: антология (Махабхарата, Панчатантра, Хитопадеша…) и набор Кочергиной</summary>
  <div id="nonlist"></div>
</details>
<script>
const D = __DATA__;
const AB = "__AUDIO_BASE__";
const listEl = document.getElementById('list');
const moreBtn = document.getElementById('more');
const qEl = document.getElementById('q');
const onlyEl = document.getElementById('only');
const countEl = document.getElementById('count');
let shown = 0; const PAGE = 300;

function filtered(){
  const q = qEl.value.trim().toLowerCase();
  const only = onlyEl.checked;
  const out = [];
  for (const s of D.say){
    const num = s[0];
    const o = D.ours[num];
    if (only && !o) continue;
    if (q){
      const hay = (num + ' ' + s[2] + ' ' + s[1]).toLowerCase();
      if (!hay.includes(q)) continue;
    }
    out.push(s);
  }
  return out;
}
function rowHTML(s){
  const num = s[0], deva = s[1], iast = s[2], de = s[3], page = s[4];
  const o = D.ours[num];
  let ours = '';
  if (o){
    let aud = '';
    for (const a of o.a){
      aud += `<div><audio class="audio" controls preload="none" data-src="${AB}/${a.id}.mp3"></audio><div class="aid">${a.id} · ${a.dur}s · ${a.set}${a.su?' · Su'+a.su:''}</div></div>`;
    }
    const books = (o.b||[]).map(b => `<div class="book"><div class="bl">${b.book} · урок ${b.lesson||'?'} · ${b.tier}</div><div class="bt">${b.text}</div><div class="aid">${b.id}</div></div>`).join('');
    ours = `<div class="ours">
      <div class="panel"><h4>Наша запись</h4>${aud}</div>
      <div class="panel"><h4>Текст плёнки</h4><div class="deva">${o.tv||'—'}</div>
        ${o.ru?`<div class="ru" style="margin-top:8px">${o.ru}</div>`:''}</div>
      ${books?`<div class="panel"><h4>Учебники (Бюлер / Кнауэр / Кочергина)</h4>${books}</div>`:''}
    </div>`;
  }
  return `<div class="row" data-num="${num}">
    <div class="is"><div class="num">IS ${num}</div>
      <div class="body"><div class="deva">${deva.replaceAll('/','<br>')}</div>
      <div class="iast">${iast}</div>
      <div class="meta">${page||''}${de?' · '+de.slice(0,110)+'…':''}</div></div></div>
    ${o?`<button class="rowbtn" onclick="toggle(this)">наше ▸</button>${ours}`:`<div class="ours"><div class="panel" style="color:var(--mut)">наших материалов нет</div></div>`}
  </div>`;
}
function toggle(btn){ btn.closest('.row').classList.toggle('open');
  btn.closest('.row').querySelectorAll('audio[data-src]').forEach(a=>{ if(!a.src) a.src=a.dataset.src; });
  btn.textContent = btn.closest('.row').classList.contains('open') ? 'наше ▴' : 'наше ▸'; }
function render(reset){
  if (reset){ listEl.innerHTML=''; shown=0; }
  const rows = filtered();
  const chunk = rows.slice(shown, shown+PAGE);
  const frag = document.createElement('div');
  for (const s of chunk){ frag.insertAdjacentHTML('beforeend', rowHTML(s)); }
  listEl.appendChild(frag);
  shown += chunk.length;
  countEl.textContent = `показано ${shown} из ${rows.length}`;
  moreBtn.style.display = shown < rows.length ? 'block' : 'none';
}
qEl.addEventListener('input', ()=>render(true));
onlyEl.addEventListener('change', ()=>render(true));
moreBtn.addEventListener('click', ()=>render(false));
document.getElementById('all').addEventListener('click', ()=>{
  const open = document.querySelectorAll('.row.open').length > 0;
  document.querySelectorAll('.row').forEach(r=>{ r.classList.remove('open');
    r.querySelectorAll('audio[data-src]').forEach(a=>{ if(!a.src) a.src=a.dataset.src; }); });
  if (!open){ document.querySelectorAll('.row').forEach(r=>r.classList.add('open')); }
});
render(true);

const nonEl = document.getElementById('nonlist');
nonEl.innerHTML = D.non.map(n=>`<div class="nonrow">
  <div class="deva" style="font-size:19px">${n.front||n.id}</div>
  ${n.ru?`<div class="ru">${n.ru}</div>`:''}
  <audio class="audio" controls preload="none" data-src="${AB}/${n.id}.mp3" style="margin-top:6px"></audio>
  <div class="aid">${n.id} · ${n.set}</div></div>`).join('');
document.querySelector('details.nonb').addEventListener('toggle', function(){
  if (this.open) this.querySelectorAll('audio[data-src]').forEach(a=>{ if(!a.src) a.src=a.dataset.src; });
});
</script>
</body>
</html>
"""


if __name__ == "__main__":
    main()