#!/usr/bin/env python3
"""
Конвертер стенограммы (.txt / .md) в JSON.
  - [Лекция]     → type=text
  - [Реплика]    → type=interjection
  - [Любая роль] → type=dialog
  Роль запоминается сквозь все секции и никогда не сбрасывается.
  Роли слушателей — без uppercase и без вырезания "(слушатель)".
"""
import re, json, sys
from pathlib import Path


def parse_meta(text):
    m = re.search(r'<!--\s*META\s*(.*?)-->', text, re.DOTALL)
    if not m:
        raise ValueError("Блок META не найден")
    meta = {}
    for line in m.group(1).splitlines():
        line = line.strip()
        if not line or ':' not in line:
            continue
        key, _, value = line.partition(':')
        meta[key.strip()] = value.strip()
    return meta


def build_video(meta):
    return {k: meta[k] for k in ('youtube', 'rutube') if k in meta}


def make_timecodes(s, yt, rt, au):
    return {'yt': s + yt, 'rt': s + rt, 'au': s + au}


def normalize_role(raw):
    s = raw.strip().strip('[]')
    low = s.lower()
    if low == 'ведущий':
        return 'host'
    if low == 'лектор':
        return 'lecturer'
    return s  # как есть — без uppercase, с "(слушатель)"


def img_path(lesson, n):
    return f'./src/img/{lesson:02d}_{n:02d}.jpg'


# ---------------------------------------------------------------------------
def tokenize(body, lesson_number):
    tokens = []
    for line in body.splitlines():
        s = line.strip()
        if not s:
            continue
        mh = re.match(r'<h(\d)>(.*?)</h\d>', s)
        if mh:
            tokens.append(('heading', int(mh.group(1)), mh.group(2).strip()))
            continue
        mt = re.match(r'<time>(\d+)</time>', s)
        if mt:
            tokens.append(('time', int(mt.group(1))))
            continue
        mr = re.match(r'^\[(.+?)\]\s*$', s)
        if mr:
            inner = mr.group(1).strip()
            low = inner.lower()
            if low == 'лекция':
                tokens.append(('mode', 'text', 'lecturer'))
            elif low == 'реплика':
                tokens.append(('mode', 'interjection', 'host'))
            else:
                tokens.append(('mode', 'dialog', normalize_role(f'[{inner}]')))
            continue
        mf = re.match(r'<figure><img\s+(\d+)><figcaption>(.*?)</figcaption></figure>', s)
        if mf:
            src = img_path(lesson_number, int(mf.group(1)))
            tokens.append(('figure', src, mf.group(2).strip()))
            continue
        tokens.append(('paragraph', s))
    return tokens


# ---------------------------------------------------------------------------
def build_content(events, initial_role='NARRATOR', initial_mode='dialog'):
    if not events:
        return [], initial_role, initial_mode

    content = []
    current_mode = initial_mode
    current_role = initial_role
    buf_paras = []
    turns = []
    turn_role = None
    turn_paras = []

    def flush_turn():
        nonlocal turn_role, turn_paras
        if turn_role and turn_paras:
            text = turn_paras if len(turn_paras) > 1 else turn_paras[0]
            turns.append({'role': turn_role, 'text': text})
        turn_role = None
        turn_paras = []

    def flush_block():
        nonlocal buf_paras, turns, turn_role, turn_paras
        if current_mode == 'text' and buf_paras:
            content.append({'type': 'text', 'role': current_role,
                            'paragraphs': list(buf_paras)})
            buf_paras.clear()
        elif current_mode == 'interjection' and buf_paras:
            content.append({'type': 'interjection', 'speaker': current_role,
                            'text': ' '.join(buf_paras)})
            buf_paras.clear()
        elif current_mode == 'dialog':
            flush_turn()
            if turns:
                content.append({'type': 'dialog', 'turns': list(turns)})
            turns.clear()

    for ev in events:
        kind = ev[0]

        if kind == 'figure':
            flush_block()
            content.append({'type': 'figure', 'src': ev[1],
                            'alt': ev[2], 'caption': ev[2]})
            continue

        if kind == 'mode':
            _, new_mode, new_role = ev
            mode_changed = new_mode != current_mode

            if mode_changed:
                flush_block()
                current_mode = new_mode

            if new_role is not None:
                current_role = new_role

            if new_mode == 'dialog':
                if mode_changed:
                    turn_role = current_role
                    turn_paras = []
                else:
                    flush_turn()
                    turn_role = current_role
                    turn_paras = []
            continue

        if kind == 'paragraph':
            text = ev[1]
            if current_mode in ('text', 'interjection'):
                buf_paras.append(text)
            else:
                if turn_role is None:
                    turn_role = current_role
                turn_paras.append(text)

    flush_block()
    return content, current_role, current_mode


# ---------------------------------------------------------------------------
def build_sections(tokens, yt_off, rt_off, au_off):
    sections = []
    current_section = None
    raw_events = []
    h2, h3 = 0, 0
    last_role = ['NARRATOR']  # список, чтобы можно было менять внутри вложенной функции
    last_mode = ['dialog']

    def flush():
        nonlocal raw_events
        if current_section is None:
            raw_events = []
            return
        current_section['content'], last_role[0], last_mode[0] = build_content(
            raw_events, last_role[0], last_mode[0]
        )
        sections.append(current_section)
        raw_events = []

    for tok in tokens:
        kind = tok[0]

        if kind == 'heading':
            flush()
            level, title = tok[1], tok[2]
            if level == 2:
                h2 += 1; h3 = 0; sec_id = f's{h2}'
            else:
                h3 += 1; sec_id = f's{h2}_{h3}'
            current_section = {
                'id': sec_id,
                'level': level,
                'title': title,
                'timecodes': {},
                'content': [],
            }

        elif kind == 'time':
            if current_section is not None:
                current_section['timecodes'] = make_timecodes(
                    tok[1], yt_off, rt_off, au_off
                )

        elif kind in ('mode', 'paragraph', 'figure'):
            if current_section is not None:
                raw_events.append(tok)

    flush()
    return sections


# ---------------------------------------------------------------------------
def convert(input_path):
    text = Path(input_path).read_text(encoding='utf-8')
    meta = parse_meta(text)
    lesson_number = int(meta.get('lesson_number', 0))
    yt_off = int(meta.get('yt_offset', 0))
    rt_off = int(meta.get('rt_offset', 0))
    au_off = int(meta.get('au_offset', 0))

    body = re.sub(r'<!--\s*META.*?-->', '', text, flags=re.DOTALL).strip()
    tokens   = tokenize(body, lesson_number)
    sections = build_sections(tokens, yt_off, rt_off, au_off)

    result = {
        'meta': {
            'course':        meta.get('course', ''),
            'lesson_title':  meta.get('lesson_title', ''),
            'title':         meta.get('title', ''),
            'lecturer':      meta.get('lecturer', ''),
            'lesson_number': lesson_number,
            'organization':  meta.get('organization', ''),
            'period':        meta.get('period', ''),
            'date_display':  meta.get('date_display', ''),
            'host':          meta.get('host', ''),
            'video':         build_video(meta),
        },
        'sections': sections,
    }

    json_str = json.dumps(result, ensure_ascii=False, indent=2)
    data_dir = Path(__file__).parent / 'data'
    data_dir.mkdir(exist_ok=True)
    out_path = data_dir / f'{Path(input_path).stem}.json'
    out_path.write_text(json_str, encoding='utf-8')

    h2_count = sum(1 for s in sections if s['level'] == 2)
    h3_count = sum(1 for s in sections if s['level'] == 3)
    print(f'Сохранено: {out_path}')
    print(f'Разделов h2: {h2_count}, подразделов h3: {h3_count}, итого секций: {h2_count + h3_count}')


# ---------------------------------------------------------------------------
if __name__ == '__main__':
    if len(sys.argv) < 2:
        print('Использование: python md_to_json.py <входной_файл>')
        sys.exit(1)
    convert(sys.argv[1])