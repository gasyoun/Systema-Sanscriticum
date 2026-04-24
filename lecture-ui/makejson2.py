#!/usr/bin/env python3
"""
Конвертер стенограммы нового формата (.txt) в JSON.

Формат входного файла:
  <!-- META ... -->                        — опциональный блок метаданных
  <h2>Заголовок</h2> [00:00:29]           — раздел с тайм-кодом
  <h3>Подраздел</h3> [00:01:24]           — подраздел с тайм-кодом
  [СПИКЕР 00]                             — переключение роли
  [00:01:24] Текст абзаца...              — абзац с тайм-кодом
  [00:02:10] Следующий абзац...           — следующий абзац той же реплики
  <figure><img N><figcaption>...</figcaption></figure>

  <!-- GLOSSARY -->                        — опциональный блок глоссария в конце файла
  термин [тег] {определение} §N §N        — строки глоссария

Структура JSON:
  content[]: { type: "speech", role, paragraphs: [{t, text}] }
           | { type: "figure", src, alt, caption }
  glossary[]: { term, tag, definition, refs: [N, ...] }  — если блок присутствует
"""

import re, json, sys
from pathlib import Path


# ---------------------------------------------------------------------------
# META

def parse_meta(text):
    m = re.search(r'<!--\s*META\s*(.*?)-->', text, re.DOTALL)
    if not m:
        return {}
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


def build_meta_block(meta):
    if not meta:
        return {}
    lesson_number = int(meta.get('lesson_number', 0))
    return {
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
        'yt_offset':     int(meta.get('yt_offset', 0)),
        'rt_offset':     int(meta.get('rt_offset', 0)),
        'au_offset':     int(meta.get('au_offset', 0)),
    }


# ---------------------------------------------------------------------------
# GLOSSARY

# Формат строки: термин [тег] {определение} §N §N ...
# Тег и §-ссылки опциональны.
RE_GLOSSARY_ENTRY = re.compile(
    r'^(.+?)'                          # термин (всё до первого '[' или '{')
    r'(?:\s*\[([^\]]+)\])?'            # опциональный тег: [sanskrit]
    r'\s*\{([^}]+)\}'                  # определение в фигурных скобках
    r'((?:\s*§\d+)*)'                  # опциональные ссылки §N
    r'\s*$'
)
RE_PARA_REF = re.compile(r'§(\d+)')


BASE_TAG_LABELS = {
    'sanskrit':  'Санскритские термины',
    'concept':   'Понятия курса',
    'person':    'Персоналии',
    'text':      'Тексты',
    'school':    'Философские школы',
    'geography': 'География',
    'deity':     'Боги и мифологические персонажи',
    'ritual':    'Ритуальные понятия и практики',
    'dynasty':   'Династии',
    'event':     'События',
}


def parse_tag_labels(glossary_file_text):
    """Извлекает нестандартные метки из заголовка файла глоссария.
    Формат: [метка]  — «Русское название»
    Возвращает dict: {'deity': 'Боги и мифологические персонажи', ...}
    """
    labels = dict(BASE_TAG_LABELS)
    pattern = re.compile(r'\[([\w]+)\]\s*[—–-]+\s*[«"]?([^»"\n]+)[»"]?')
    for m in pattern.finditer(glossary_file_text):
        tag   = m.group(1).strip().lower()
        label = m.group(2).strip().strip('«»"')
        labels[tag] = label
    return labels


def parse_glossary_block(block, tag_labels=None):
    """Парсит текстовый блок глоссария (всё после <!-- GLOSSARY -->).
    Возвращает список словарей или пустой список если блок пустой/нераспознан.
    """
    if tag_labels is None:
        tag_labels = BASE_TAG_LABELS
    entries = []
    for line in block.splitlines():
        line = line.strip()
        if not line:
            continue
        m = RE_GLOSSARY_ENTRY.match(line)
        if not m:
            continue
        term       = m.group(1).strip()
        tag        = m.group(2).strip() if m.group(2) else ''
        definition = m.group(3).strip()
        refs       = [int(r) for r in RE_PARA_REF.findall(m.group(4))]
        entries.append({
            'term':       term,
            'tag':        tag,
            'tag_label':  tag_labels.get(tag.lower(), tag),
            'definition': definition,
            'refs':       refs,
        })
    return entries


def split_glossary(text):
    """Разделяет текст файла на основной body и блок глоссария.
    Возвращает (body: str, glossary_block: str | None).
    Если маркера нет — возвращает (text, None).
    Блок глоссария содержит файл _glossary.txt целиком:
    заголовок с метками + --- + термины.
    """
    parts = re.split(r'(?m)^<!--\s*GLOSSARY\s*-->\s*$', text, maxsplit=1)
    if len(parts) == 2:
        return parts[0].strip(), parts[1].strip()
    return text.strip(), None


def split_glossary_header(glossary_raw):
    """Разделяет блок глоссария на заголовок (с метками) и термины.
    Возвращает (header: str, terms: str).
    Если разделителя --- нет — header пустой, terms = весь блок.
    """
    parts = re.split(r'(?m)^---\s*$', glossary_raw, maxsplit=1)
    if len(parts) == 2:
        return parts[0].strip(), parts[1].strip()
    return '', glossary_raw.strip()


# ---------------------------------------------------------------------------
# Вспомогательные функции

def tc_to_seconds(tc_str):
    """'00:01:24' или '01:24' → секунды (int)"""
    parts = tc_str.strip().split(':')
    parts = [int(p) for p in parts]
    if len(parts) == 3:
        return parts[0] * 3600 + parts[1] * 60 + parts[2]
    if len(parts) == 2:
        return parts[0] * 60 + parts[1]
    return parts[0]


def img_path(lesson, n):
    return f'./src/img/{lesson:02d}_{n:02d}.jpg'


# ---------------------------------------------------------------------------
# Токенизация

RE_HEADING    = re.compile(r'<h(\d)>(.*?)</h\d>\s*(?:§\d+\s*)?(?:\[(\d+:\d{2}(?::\d{2})?)\])?')
RE_ROLE       = re.compile(r'^\[([A-ZА-ЯЁa-zа-яё0-9\s]+)\]\s*$')
RE_TIMEPARA   = re.compile(r'^\[(\d+:\d{2}(?::\d{2})?)\]\s*(.*)')
RE_FIGURE     = re.compile(r'<figure><img\s+(\d+)><figcaption>(.*?)</figcaption></figure>')
# Форматы с префиксом §N (генерируются при флаге --para):
RE_PARA_PARA  = re.compile(r'^§(\d+)\s+\[(\d+:\d{2}(?::\d{2})?)\]\s*(.*)')  # §N [00:00:00] текст
RE_PARA_FIG   = re.compile(r'^§\d+\s+(<figure><img\s+\d+>.*</figure>)\s*$') # §N <figure>...</figure>
RE_PARA_HEAD  = re.compile(r'^(<h\d>.*</h\d>.*)')                            # §N <hN>...</hN> [tc]
RE_STRIP_PARA = re.compile(r'^§\d+\s+')                                      # убрать §N из начала


def tokenize(body, lesson_number):
    tokens = []
    for line in body.splitlines():
        s = line.strip()
        if not s:
            continue

        # §N <figure>...</figure> — фигура с префиксом §N
        mpf = RE_PARA_FIG.match(s)
        if mpf:
            s = mpf.group(1)   # убираем §N, оставляем тег
        else:
            # §N <hN>...</hN> — заголовок с префиксом §N (редко, но бывает)
            mph = RE_PARA_HEAD.match(RE_STRIP_PARA.sub('', s))
            if RE_STRIP_PARA.match(s) and mph:
                s = mph.group(1)

        # heading
        mh = RE_HEADING.match(s)
        if mh:
            level = int(mh.group(1))
            title = mh.group(2).strip()
            tc    = tc_to_seconds(mh.group(3)) if mh.group(3) else None
            if tc is None:
                print(f'  ПРЕДУПРЕЖДЕНИЕ: заголовок без таймкода — <h{level}>{title}</h{level}>')
            tokens.append(('heading', level, title, tc))
            continue

        # figure
        mf = RE_FIGURE.match(s)
        if mf:
            src = img_path(lesson_number, int(mf.group(1)))
            tokens.append(('figure', src, mf.group(2).strip()))
            continue

        # role switch: [СПИКЕР 00] или [Лектор] и т.п.
        mr = RE_ROLE.match(s)
        if mr:
            tokens.append(('role', mr.group(1).strip()))
            continue

        # §N [00:01:24] текст — параграф с префиксом §N (формат --para)
        mpp = RE_PARA_PARA.match(s)
        if mpp:
            dg_ref = int(mpp.group(1))
            t      = tc_to_seconds(mpp.group(2))
            text   = mpp.group(3).strip()
            tokens.append(('para', t, text, dg_ref))
            continue

        # paragraph with timecode: [00:01:24] текст
        mp = RE_TIMEPARA.match(s)
        if mp:
            t    = tc_to_seconds(mp.group(1))
            text = mp.group(2).strip()
            tokens.append(('para', t, text))
            continue

        # plain paragraph (no timecode)
        tokens.append(('para', None, s))

    return tokens


# ---------------------------------------------------------------------------
# Сборка секций

def make_timecodes(t, yt_off, rt_off, au_off):
    """Тайм-код из Whisper (=аудио) + смещения для каждой платформы."""
    return {'yt': t + yt_off, 'rt': t + rt_off, 'au': t + au_off}


def apply_offsets(t, yt_off, rt_off, au_off):
    """Для абзацев: храним все три значения только если смещения ненулевые,
    иначе храним одно поле t для компактности."""
    if yt_off == 0 and rt_off == 0 and au_off == 0:
        return {'t': t}
    return {'t_yt': t + yt_off, 't_rt': t + rt_off, 't_au': t + au_off}


def build_sections(tokens, yt_off=0, rt_off=0, au_off=0):
    sections = []
    current_section = None
    current_role    = None
    pending_paras   = []   # накопленные абзацы текущей реплики
    content         = []   # content текущей секции

    def flush_speech():
        """Закрыть текущую реплику и добавить в content."""
        nonlocal pending_paras, current_role
        if pending_paras and current_role is not None:
            content.append({
                'type': 'speech',
                'role': current_role,
                'paragraphs': list(pending_paras),
            })
        pending_paras = []

    def flush_section():
        nonlocal content
        if current_section is not None:
            flush_speech()
            current_section['content'] = list(content)
            sections.append(current_section)
        elif content:
            # Параграфы до первого заголовка — помещаем в секцию-пролог
            flush_speech()
            sections.append({
                'level': 2,
                'title': '',
                'timecodes': {},
                'id': 's0',
                'content': list(content),
            })
        content = []

    for tok in tokens:
        kind = tok[0]

        if kind == 'heading':
            flush_section()
            _, level, title, tc = tok
            current_section = {
                'level': level,
                'title': title,
                'timecodes': make_timecodes(tc, yt_off, rt_off, au_off) if tc is not None else {},
            }

        elif kind == 'role':
            flush_speech()
            current_role = tok[1]

        elif kind == 'para':
            dg_ref = tok[3] if len(tok) > 3 else None
            _, t, text = tok[0], tok[1], tok[2]
            if not text:
                continue
            entry = {'text': text}
            if t is not None:
                entry.update(apply_offsets(t, yt_off, rt_off, au_off))
            if dg_ref is not None:
                entry['dg_ref'] = dg_ref
            pending_paras.append(entry)

        elif kind == 'figure':
            flush_speech()
            _, src, caption = tok
            content.append({
                'type':    'figure',
                'src':     src,
                'alt':     caption,
                'caption': caption,
            })

    flush_section()

    # Проставляем id секций (s0 — пролог без заголовка, s1..sN — обычные)
    h2, h3 = 0, 0
    for sec in sections:
        if sec.get('id') == 's0':
            continue  # пролог уже имеет id
        if sec['level'] == 2:
            h2 += 1; h3 = 0
            sec['id'] = f's{h2}'
        else:
            h3 += 1
            sec['id'] = f's{h2}_{h3}'

    return sections


# ---------------------------------------------------------------------------
# Основная функция

def convert(input_path):
    text = Path(input_path).read_text(encoding='utf-8')

    meta = parse_meta(text)
    # Убираем блок META перед дальнейшей обработкой
    text_no_meta = re.sub(r'<!--\s*META.*?-->', '', text, flags=re.DOTALL).strip()

    # Отделяем глоссарий от основного текста
    body, glossary_raw = split_glossary(text_no_meta)

    # Извлекаем маппинг меток из заголовка файла (до ---)
    # Метки читаем из заголовка блока GLOSSARY (часть до ---)
    glossary_header, glossary_terms = split_glossary_header(glossary_raw or "")
    tag_labels = parse_tag_labels(glossary_header)

    lesson_n = int(meta.get('lesson_number', 0)) if meta else 0
    yt_off   = int(meta.get('yt_offset', 0)) if meta else 0
    rt_off   = int(meta.get('rt_offset', 0)) if meta else 0
    au_off   = int(meta.get('au_offset', 0)) if meta else 0

    tokens   = tokenize(body, lesson_n)
    sections = build_sections(tokens, yt_off, rt_off, au_off)

    result = {'sections': sections}
    if meta:
        result = {'meta': build_meta_block(meta), **result}

    # Глоссарий — только если блок есть и содержит распознанные записи
    if glossary_raw:
        glossary = parse_glossary_block(glossary_terms, tag_labels)
        if glossary:
            result['glossary'] = glossary
            print(f'  Глоссарий: {len(glossary)} терминов добавлено в JSON')
        else:
            print(f'  Глоссарий: блок найден, но записи не распознаны — пропуск')
    else:
        print(f'  Глоссарий: блок отсутствует')

    json_str = json.dumps(result, ensure_ascii=False, indent=2)

    data_dir = Path(__file__).parent / 'data'
    data_dir.mkdir(exist_ok=True)
    out_path = data_dir / f'{Path(input_path).stem}.json'
    out_path.write_text(json_str, encoding='utf-8')

    h2_count = sum(1 for s in sections if s['level'] == 2)
    h3_count = sum(1 for s in sections if s['level'] == 3)
    print(f'Сохранено: {out_path}')
    print(f'Разделов h2: {h2_count}, подразделов h3: {h3_count}, итого: {h2_count + h3_count}')


# ---------------------------------------------------------------------------
if __name__ == '__main__':
    script_dir = Path(__file__).parent
    txt_files = sorted((script_dir / 'txt').glob('*.txt'))
    if not txt_files:
        print('Файлы .txt рядом со скриптом не найдены.')
        sys.exit(1)
    for txt_file in txt_files:
        print(f'\nОбрабатывается: {txt_file.name}')
        convert(txt_file)
